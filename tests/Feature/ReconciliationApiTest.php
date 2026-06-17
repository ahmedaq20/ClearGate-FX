<?php

use App\Models\AuditLog;
use App\Models\Box;
use App\Models\BoxAdjustment;
use App\Models\CapitalAccount;
use App\Models\ReconciliationSnapshot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsReconciliationUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('owner can view balanced reconciliation and store a snapshot', function (): void {
    $owner = actingAsReconciliationUser();
    Box::factory()->create(['current_balance' => 85000]);
    CapitalAccount::factory()->create([
        'user_id' => $owner->id,
        'balance_usd' => 100000,
        'free_balance_usd' => 15000,
    ]);

    $this->getJson('/api/v1/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.capital_balance', 100000)
        ->assertJsonPath('data.boxes_total_balance', 85000)
        ->assertJsonPath('data.free_capital', 15000)
        ->assertJsonPath('data.difference', 0)
        ->assertJsonPath('data.status', 'balanced');

    $this->postJson('/api/v1/reconciliation/run')
        ->assertCreated()
        ->assertJsonPath('message', 'تم تنفيذ المطابقة المالية')
        ->assertJsonPath('data.status', 'balanced');

    expect(ReconciliationSnapshot::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'reconciliation.executed')->count())->toBe(1);

    $this->getJson('/api/v1/reconciliation/history')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('reconciliation reports mismatch when boxes and free capital exceed total capital', function (): void {
    $owner = actingAsReconciliationUser();
    Box::factory()->create(['current_balance' => 87000]);
    CapitalAccount::factory()->create([
        'user_id' => $owner->id,
        'balance_usd' => 100000,
        'free_balance_usd' => 15000,
    ]);

    $this->getJson('/api/v1/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.difference', -2000)
        ->assertJsonPath('data.status', 'mismatch');
});

test('box adjustment updates balance and creates logs inside the adjustment workflow', function (): void {
    $manager = actingAsReconciliationUser('manager');
    $manager->givePermissionTo('box.adjustment.create');
    $box = Box::factory()->create(['current_balance' => 1000]);

    $this->postJson("/api/v1/boxes/{$box->id}/adjust", [
        'adjustment_type' => 'decrease',
        'amount' => 500,
        'reason' => 'Cash shortage',
        'notes' => 'Physical cash count mismatch',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء تعديل رصيد الصندوق')
        ->assertJsonPath('data.adjustment_type', 'decrease')
        ->assertJsonPath('data.balance_before', '1000.0000')
        ->assertJsonPath('data.balance_after', '500.0000');

    expect((float) $box->refresh()->current_balance)->toBe(500.0)
        ->and(BoxAdjustment::query()->where('box_id', $box->id)->count())->toBe(1)
        ->and($box->balanceLogs()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'box_adjustment.created')->count())->toBe(1);

    $this->getJson("/api/v1/boxes/{$box->id}/adjustments")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/adjustments')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('box adjustment validates amount box existence and available balance', function (): void {
    actingAsReconciliationUser();
    $box = Box::factory()->create(['current_balance' => 100]);

    $this->postJson("/api/v1/boxes/{$box->id}/adjust", [
        'adjustment_type' => 'increase',
        'amount' => 0,
        'reason' => 'Correction',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount'])
        ->assertJsonPath('errors.amount.0', 'قيمة التعديل يجب أن تكون أكبر من صفر');

    $this->postJson("/api/v1/boxes/{$box->id}/adjust", [
        'adjustment_type' => 'decrease',
        'amount' => 150,
        'reason' => 'Cash shortage',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount'])
        ->assertJsonPath('errors.amount.0', 'لا يمكن خصم مبلغ أكبر من رصيد الصندوق');

    $this->postJson('/api/v1/boxes/999999/adjust', [
        'adjustment_type' => 'increase',
        'amount' => 10,
        'reason' => 'Correction',
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'الصندوق غير موجود');
});

test('operations employee can read assigned adjustments but cannot create adjustments', function (): void {
    $owner = actingAsReconciliationUser();
    $employee = User::factory()->create(['is_active' => true]);
    $employee->assignRole('operations_employee');
    $box = Box::factory()->create([
        'assigned_user_id' => $employee->id,
        'current_balance' => 300,
    ]);

    $this->postJson("/api/v1/boxes/{$box->id}/adjust", [
        'adjustment_type' => 'increase',
        'amount' => 50,
        'reason' => 'Cash surplus',
    ])->assertCreated();

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/reconciliation')
        ->assertOk()
        ->assertJsonStructure(['data' => ['capital_balance', 'boxes_total_balance', 'free_capital', 'difference', 'status']]);

    $this->getJson("/api/v1/boxes/{$box->id}/adjustments")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/boxes/{$box->id}/adjust", [
        'adjustment_type' => 'increase',
        'amount' => 10,
        'reason' => 'Cash surplus',
    ])->assertForbidden();
});
