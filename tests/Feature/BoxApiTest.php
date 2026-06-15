<?php

use App\Models\Box;
use App\Models\BoxBalanceLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function actingAsBoxUser(string $role = 'owner'): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    Sanctum::actingAs($user);

    return $user;
}

test('owner can manage boxes and filter by type', function (): void {
    $assignedUser = User::factory()->create(['is_active' => true]);
    actingAsBoxUser('owner');

    $this->postJson('/api/v1/boxes', [
        'name' => 'Turkish Main',
        'type' => 'turkish',
        'current_balance' => 1000,
        'currency' => 'TRY',
        'assigned_user_id' => $assignedUser->id,
        'status' => 'active',
        'notes' => 'Main Turkish cash box',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'تم إنشاء الصندوق')
        ->assertJsonPath('data.name', 'Turkish Main')
        ->assertJsonPath('data.type', 'turkish')
        ->assertJsonPath('data.assigned_user_id', $assignedUser->id);

    Box::factory()->create([
        'name' => 'USDT Wallet',
        'type' => 'usdt_wallet',
        'currency' => 'USDT',
    ]);

    $box = Box::query()->where('name', 'Turkish Main')->firstOrFail();

    $this->getJson('/api/v1/boxes?type=turkish')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Turkish Main')
        ->assertJsonPath('data.0.type', 'turkish');

    $this->getJson("/api/v1/boxes/{$box->id}")
        ->assertOk()
        ->assertJsonPath('data.currency', 'TRY');

    $this->putJson("/api/v1/boxes/{$box->id}", [
        'name' => 'Turkish Updated',
        'status' => 'inactive',
        'notes' => 'Updated note',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث الصندوق')
        ->assertJsonPath('data.name', 'Turkish Updated')
        ->assertJsonPath('data.status', 'inactive');

    $this->deleteJson("/api/v1/boxes/{$box->id}")
        ->assertOk()
        ->assertJsonPath('message', 'تم حذف الصندوق');

    $this->assertDatabaseMissing('boxes', ['id' => $box->id]);
});

test('box creation validates type and non negative current balance', function (): void {
    actingAsBoxUser('owner');

    $this->postJson('/api/v1/boxes', [
        'name' => '',
        'type' => 'vault',
        'current_balance' => -1,
        'currency' => 'USD',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'current_balance']);
});

test('balance adjustment updates current balance and writes audit log', function (): void {
    $owner = actingAsBoxUser('owner');
    $box = Box::factory()->create([
        'current_balance' => 100,
        'type' => 'local_bank_wallet',
        'currency' => 'USD',
    ]);

    $this->patchJson("/api/v1/boxes/{$box->id}/balance", [
        'operation_type' => 'add',
        'amount' => 75.5,
        'notes' => 'Opening correction',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'تم تحديث رصيد الصندوق')
        ->assertJsonPath('data.current_balance', '175.5000');

    $log = BoxBalanceLog::query()->where('box_id', $box->id)->firstOrFail();

    expect((float) $box->refresh()->current_balance)->toBe(175.5)
        ->and($log->operation_type->value)->toBe('add')
        ->and((float) $log->amount)->toBe(75.5)
        ->and((float) $log->balance_before)->toBe(100.0)
        ->and((float) $log->balance_after)->toBe(175.5)
        ->and($log->created_by)->toBe($owner->id);

    $this->getJson("/api/v1/boxes/{$box->id}/logs")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.operation_type', 'add')
        ->assertJsonPath('data.0.created_by', $owner->id);
});

test('box balance cannot be adjusted below zero', function (): void {
    actingAsBoxUser('owner');
    $box = Box::factory()->create(['current_balance' => 25]);

    $this->patchJson("/api/v1/boxes/{$box->id}/balance", [
        'operation_type' => 'subtract',
        'amount' => 50,
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن أن يصبح رصيد الصندوق سالباً');

    expect((float) $box->refresh()->current_balance)->toBe(25.0)
        ->and(BoxBalanceLog::query()->where('box_id', $box->id)->exists())->toBeFalse();
});

test('operations employee can only access assigned boxes', function (): void {
    $employee = actingAsBoxUser('operations_employee');
    $assignedBox = Box::factory()->create([
        'name' => 'Assigned Box',
        'assigned_user_id' => $employee->id,
    ]);
    $otherBox = Box::factory()->create(['name' => 'Other Box']);

    $this->getJson('/api/v1/boxes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assignedBox->id);

    $this->getJson("/api/v1/boxes/{$assignedBox->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Assigned Box');

    $this->getJson("/api/v1/boxes/{$otherBox->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'غير مصرح');

    $this->patchJson("/api/v1/boxes/{$assignedBox->id}/balance", [
        'operation_type' => 'add',
        'amount' => 10,
    ])
        ->assertOk();

    $this->postJson('/api/v1/boxes', [
        'name' => 'Forbidden Create',
        'type' => 'turkish',
        'currency' => 'TRY',
    ])
        ->assertForbidden();
});
