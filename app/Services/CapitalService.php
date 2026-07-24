<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Enums\CapitalAccountType;
use App\Enums\CapitalMovementType;
use App\Models\AuditLog;
use App\Models\Box;
use App\Models\CapitalAccount;
use App\Models\CapitalBoxAllocation;
use App\Models\CapitalTransaction;
use App\Models\OwnerExpense;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CapitalService
{
    public function account(User $owner): CapitalAccount
    {
        return CapitalAccount::query()->firstOrCreate(
            [
                'user_id' => $owner->id,
                'type' => CapitalAccountType::Owner->value,
                'currency' => 'USD',
            ],
            [
                'name' => 'Owner Capital',
                'total_balance' => 0,
                'unallocated_balance' => 0,
                'allocated_balance' => 0,
                'balance_usd' => 0,
                'free_balance_usd' => 0,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deposit(User $owner, array $data): CapitalTransaction
    {
        return $this->moveCapital($owner, 'deposit', (float) $data['amount'], $data['transaction_date'] ?? now()->toDateString(), $data['notes'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withdraw(User $owner, array $data): CapitalTransaction
    {
        return $this->moveCapital($owner, 'withdraw', -1 * (float) $data['amount'], $data['transaction_date'] ?? now()->toDateString(), $data['notes'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function transferToBox(User $owner, array $data): CapitalTransaction
    {
        return DB::transaction(function () use ($owner, $data): CapitalTransaction {
            $account = $this->lockedAccount($owner);
            $amount = (float) $data['amount'];
            $this->ensureSufficientFreeCapital($account, $amount);

            $box = Box::query()->whereKey((int) $data['box_id'])->lockForUpdate()->firstOrFail();
            $this->ensureBoxCurrencyMatches($account, $box);
            $boxBalanceBefore = (float) $box->current_balance;
            $boxBalanceAfter = round($boxBalanceBefore + $amount, 4);
            $box->update(['current_balance' => $boxBalanceAfter]);
            $this->increaseAllocation($account, $box, $amount);

            $transaction = $this->recordMovement(
                account: $account,
                type: 'box_transfer',
                amount: $amount,
                balanceBefore: (float) $account->balance_usd,
                balanceAfter: (float) $account->balance_usd,
                freeBalanceAfter: round((float) $account->free_balance_usd - $amount, 4),
                transactionDate: (string) ($data['transaction_date'] ?? now()->toDateString()),
                notes: $data['notes'] ?? "Capital transfer to {$box->name}",
                boxId: $box->id
            );

            $box->balanceLogs()->create([
                'operation_type' => BoxBalanceOperationType::Add->value,
                'amount' => $amount,
                'balance_before' => $boxBalanceBefore,
                'balance_after' => $boxBalanceAfter,
                'notes' => "Capital transfer #{$transaction->id}",
                'created_by' => $owner->id,
            ]);

            return $transaction;
        }, attempts: 3);
    }

    /**
     * @param  array{name?: string|null, company_name?: string|null, type?: string|null, currency?: string|null, initial_balance?: mixed, initial_deposit?: mixed, transaction_date?: string|null, transaction_at?: string|null, box_id?: int|null, reference_number?: string|null, statement?: string|null, notes?: string|null}  $data
     */
    public function createCapitalAccount(User $actor, array $data): CapitalAccount
    {
        $this->authorizeCapital($actor, 'capital.account.create');

        return DB::transaction(function () use ($actor, $data): CapitalAccount {
            $type = $this->normalizeAccountType($data['type'] ?? CapitalAccountType::Company->value);
            $currency = $this->normalizeCurrency($data['currency'] ?? 'USD');
            $name = trim((string) ($data['name'] ?? $data['company_name'] ?? ''));
            $initialAmount = $this->nonNegativeAmount($data['initial_balance'] ?? $data['initial_deposit'] ?? 0);

            if ($type !== CapitalAccountType::Owner && $name === '') {
                throw ValidationException::withMessages([
                    'name' => 'اسم جهة رأس المال مطلوب.',
                ]);
            }

            if ($type === CapitalAccountType::Owner) {
                $existing = CapitalAccount::query()
                    ->where('user_id', $actor->id)
                    ->where('type', CapitalAccountType::Owner->value)
                    ->where('currency', $currency)
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'type' => 'حساب رأس المال العام لهذه العملة موجود بالفعل.',
                    ]);
                }

                $name = $name !== '' ? $name : 'Owner Capital';
            }

            $account = CapitalAccount::query()->create([
                'user_id' => $actor->id,
                'name' => $name,
                'type' => $type->value,
                'currency' => $currency,
                'total_balance' => 0,
                'unallocated_balance' => 0,
                'allocated_balance' => 0,
                'balance_usd' => 0,
                'free_balance_usd' => 0,
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLog::record(
                action: 'capital_account.created',
                model: $account,
                userId: $actor->id,
                newValues: $account->attributesToArray()
            );

            if ($initialAmount > 0) {
                $this->createCapitalMovement($actor, $account, CapitalMovementType::InitialDeposit, [
                    ...$data,
                    'amount' => $initialAmount,
                    'currency' => $currency,
                ]);
            }

            return $account->refresh()->load('boxAllocations.box', 'transactions.box');
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function initialCapital(User $actor, CapitalAccount $account, array $data): CapitalTransaction
    {
        return $this->createCapitalMovement($actor, $account, CapitalMovementType::InitialDeposit, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function topUp(User $actor, CapitalAccount $account, array $data): CapitalTransaction
    {
        return $this->createCapitalMovement($actor, $account, CapitalMovementType::TopUp, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withdrawCapital(User $actor, CapitalAccount $account, array $data): CapitalTransaction
    {
        return $this->createCapitalMovement($actor, $account, CapitalMovementType::Withdrawal, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function allocateToBox(User $actor, CapitalAccount $account, array $data): CapitalTransaction
    {
        return $this->createCapitalMovement($actor, $account, CapitalMovementType::Allocation, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deallocateFromBox(User $actor, CapitalAccount $account, array $data): CapitalTransaction
    {
        return $this->createCapitalMovement($actor, $account, CapitalMovementType::Deallocation, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function accountsOverview(User $actor): array
    {
        $this->authorizeCapital($actor, 'capital.statement.view');
        $this->account($actor);

        $accounts = CapitalAccount::query()
            ->where('user_id', $actor->id)
            ->withCount('transactions')
            ->withMax('transactions as last_movement_date', 'transaction_date')
            ->orderByRaw("CASE WHEN type = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (CapitalAccount $account): array => $this->capitalAccountRow($account))
            ->all();

        return [
            'summaries' => $this->capitalAccountSummaries($actor),
            'accounts' => $accounts,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addCapitalToAccount(User $actor, array $data): CapitalAccount
    {
        $type = $this->uiAccountType($data['type'] ?? 'investor');
        $currency = $this->normalizeCurrency($data['currency'] ?? 'USD');
        $amount = $this->positiveAmount($data['amount'] ?? 0);
        $name = $type === CapitalAccountType::Owner
            ? (string) ($data['name'] ?? 'Owner Capital')
            : trim((string) ($data['name'] ?? ''));

        if ($type !== CapitalAccountType::Owner && $name === '') {
            throw ValidationException::withMessages([
                'name' => 'اسم المستثمر أو الشركة مطلوب.',
            ]);
        }

        $account = $this->findReusableCapitalAccount($actor, $type, $currency, $name);

        if ($account === null) {
            return $this->createCapitalAccount($actor, [
                ...$data,
                'name' => $type === CapitalAccountType::Owner ? ($name !== '' ? $name : 'Owner Capital') : $name,
                'type' => $type->value,
                'currency' => $currency,
                'initial_balance' => $amount,
            ])->refresh();
        }

        $this->topUp($actor, $account, [
            ...$data,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return $account->refresh()->load('boxAllocations.box');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAccountMovement(User $actor, CapitalAccount $account, array $data): CapitalTransaction
    {
        $type = (string) ($data['type'] ?? CapitalMovementType::TopUp->value);

        if (in_array($type, ['withdraw', CapitalMovementType::Withdrawal->value], true)) {
            return $this->withdrawCapital($actor, $account, $data);
        }

        return $this->topUp($actor, $account, $data);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function accountDetails(User $actor, CapitalAccount $account, array $filters = []): array
    {
        $this->authorizeCapital($actor, 'capital.statement.view');
        $account = $this->ownedCapitalAccount($actor, $account);
        $currency = $this->normalizeCurrency($filters['currency'] ?? $account->currency);

        $movements = $account->transactions()
            ->with(['creator', 'updater'])
            ->where('currency', $currency)
            ->whereIn('type', [
                'deposit',
                'withdraw',
                CapitalMovementType::InitialDeposit->value,
                CapitalMovementType::TopUp->value,
                CapitalMovementType::Withdrawal->value,
            ])
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '<=', $date))
            ->oldest('transaction_date')
            ->oldest('id')
            ->get()
            ->map(fn (CapitalTransaction $transaction): array => $this->capitalMovementRow($transaction))
            ->all();

        return [
            'account' => $this->capitalAccountRow($account->refresh()),
            'movements' => $movements,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCapitalMovement(User $actor, CapitalTransaction $transaction, array $data): CapitalTransaction
    {
        $this->authorizeCapital($actor, 'capital.movement.update');

        return DB::transaction(function () use ($actor, $transaction, $data): CapitalTransaction {
            $lockedTransaction = $this->lockedEditableMovement($actor, $transaction);
            $account = $this->lockedCapitalAccount($actor, $lockedTransaction->capitalAccount);
            $oldValues = $lockedTransaction->attributesToArray();
            $type = CapitalMovementType::from((string) $lockedTransaction->type);
            $amount = $this->positiveAmount($data['amount'] ?? $lockedTransaction->amount);

            $this->reverseMovementImpact($account, $actor, $lockedTransaction);
            $snapshot = $this->applyMovementImpact(
                account: $account,
                actor: $actor,
                type: $type,
                amount: $amount,
                currency: (string) $lockedTransaction->currency,
                boxId: $lockedTransaction->box_id,
                reason: $data['statement'] ?? $data['notes'] ?? $lockedTransaction->statement ?? $lockedTransaction->notes
            );

            $transactionDate = (string) ($data['transaction_date'] ?? $lockedTransaction->transaction_date->toDateString());
            $lockedTransaction->update([
                ...$snapshot,
                'amount' => $amount,
                'transaction_date' => $transactionDate,
                'transaction_at' => $this->transactionAt($data['transaction_at'] ?? null, $transactionDate),
                'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $lockedTransaction->reference_number,
                'statement' => array_key_exists('statement', $data) ? $data['statement'] : $lockedTransaction->statement,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $lockedTransaction->notes,
                'updated_by' => $actor->id,
            ]);

            AuditLog::record(
                action: 'capital_movement.updated',
                model: $lockedTransaction,
                userId: $actor->id,
                oldValues: $oldValues,
                newValues: $lockedTransaction->refresh()->attributesToArray()
            );

            $this->assertCapitalInvariant($account->refresh());

            return $lockedTransaction->load('capitalAccount', 'box', 'creator', 'updater');
        }, attempts: 3);
    }

    public function deleteCapitalMovement(User $actor, CapitalTransaction $transaction): void
    {
        $this->authorizeCapital($actor, 'capital.movement.delete');

        DB::transaction(function () use ($actor, $transaction): void {
            $lockedTransaction = $this->lockedEditableMovement($actor, $transaction);
            $account = $this->lockedCapitalAccount($actor, $lockedTransaction->capitalAccount);
            $oldValues = $lockedTransaction->attributesToArray();

            $this->reverseMovementImpact($account, $actor, $lockedTransaction);
            $lockedTransaction->update(['updated_by' => $actor->id]);
            $lockedTransaction->delete();

            AuditLog::record(
                action: 'capital_movement.deleted',
                model: $lockedTransaction,
                userId: $actor->id,
                oldValues: $oldValues
            );

            $this->assertCapitalInvariant($account->refresh());
        }, attempts: 3);
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, currency?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function capitalStatement(User $actor, CapitalAccount $account, array $filters = []): array
    {
        $this->authorizeCapital($actor, 'capital.statement.view');
        $account = $this->ownedCapitalAccount($actor, $account);
        $currency = $this->normalizeCurrency($filters['currency'] ?? $account->currency);
        $query = $account->transactions()
            ->with(['box', 'creator', 'updater'])
            ->whereIn('type', $this->ownershipMovementValues())
            ->where('currency', $currency);

        $opening = ['total' => 0.0, 'unallocated' => 0.0, 'allocated' => 0.0];
        (clone $query)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '<', $date))
            ->when(! ($filters['date_from'] ?? null), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->oldest('transaction_date')
            ->oldest('id')
            ->get()
            ->each(function (CapitalTransaction $transaction) use (&$opening): void {
                $opening = $this->applyStatementDeltas($opening, $transaction);
            });

        $running = $opening;
        $transactions = (clone $query)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '<=', $date))
            ->oldest('transaction_date')
            ->oldest('id')
            ->get()
            ->map(function (CapitalTransaction $transaction) use (&$running): array {
                $running = $this->applyStatementDeltas($running, $transaction);

                return [
                    'id' => $transaction->id,
                    'transaction_date' => $transaction->transaction_date?->toDateString(),
                    'transaction_at' => $transaction->transaction_at,
                    'type' => $transaction->type,
                    'amount' => round((float) $transaction->amount, 4),
                    'currency' => $transaction->currency,
                    'box' => $transaction->box,
                    'reference_number' => $transaction->reference_number,
                    'statement' => $transaction->statement,
                    'notes' => $transaction->notes,
                    'total_owned_capital' => $running['total'],
                    'unallocated_capital' => $running['unallocated'],
                    'allocated_capital' => $running['allocated'],
                    'created_by' => $transaction->creator,
                    'updated_by' => $transaction->updater,
                ];
            })
            ->all();

        return [
            'account' => $account->load('boxAllocations.box'),
            'currency' => $currency,
            'opening_balance' => $opening,
            'transactions' => $transactions,
            'closing_balance' => $running,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createExpense(User $owner, array $data): OwnerExpense
    {
        return DB::transaction(function () use ($owner, $data): OwnerExpense {
            $account = $this->lockedAccount($owner);
            $amount = (float) $data['amount'];
            $this->ensureSufficientFreeCapital($account, $amount);

            $expense = OwnerExpense::query()->create([
                'capital_account_id' => $account->id,
                'user_id' => $owner->id,
                'title' => $data['title'],
                'category' => $data['category'],
                'amount' => $amount,
                'expense_date' => $data['expense_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->recordMovement(
                account: $account,
                type: 'expense',
                amount: -1 * $amount,
                balanceBefore: (float) $account->balance_usd,
                balanceAfter: round((float) $account->balance_usd - $amount, 4),
                freeBalanceAfter: round((float) $account->free_balance_usd - $amount, 4),
                transactionDate: (string) $data['expense_date'],
                notes: "Owner expense: {$expense->title}",
                ownerExpenseId: $expense->id
            );

            return $expense->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateExpense(User $owner, OwnerExpense $expense, array $data): OwnerExpense
    {
        return DB::transaction(function () use ($owner, $expense, $data): OwnerExpense {
            $lockedExpense = OwnerExpense::query()
                ->whereKey($expense->id)
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->firstOrFail();
            $account = $this->lockedAccount($owner);
            $oldAmount = (float) $lockedExpense->amount;
            $newAmount = (float) ($data['amount'] ?? $oldAmount);
            $delta = round($newAmount - $oldAmount, 4);

            if ($delta > 0) {
                $this->ensureSufficientFreeCapital($account, $delta);
            }

            if ($delta !== 0.0) {
                $this->recordMovement(
                    account: $account,
                    type: 'expense',
                    amount: -1 * $delta,
                    balanceBefore: (float) $account->balance_usd,
                    balanceAfter: round((float) $account->balance_usd - $delta, 4),
                    freeBalanceAfter: round((float) $account->free_balance_usd - $delta, 4),
                    transactionDate: (string) ($data['expense_date'] ?? $lockedExpense->expense_date->toDateString()),
                    notes: "Owner expense adjustment: {$lockedExpense->title}",
                    ownerExpenseId: $lockedExpense->id
                );
            }

            $lockedExpense->update($data);

            return $lockedExpense->refresh();
        }, attempts: 3);
    }

    public function deleteExpense(User $owner, OwnerExpense $expense): void
    {
        DB::transaction(function () use ($owner, $expense): void {
            $lockedExpense = OwnerExpense::query()
                ->whereKey($expense->id)
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->firstOrFail();
            $account = $this->lockedAccount($owner);
            $amount = (float) $lockedExpense->amount;

            $this->recordMovement(
                account: $account,
                type: 'expense',
                amount: $amount,
                balanceBefore: (float) $account->balance_usd,
                balanceAfter: round((float) $account->balance_usd + $amount, 4),
                freeBalanceAfter: round((float) $account->free_balance_usd + $amount, 4),
                transactionDate: now()->toDateString(),
                notes: "Deleted owner expense: {$lockedExpense->title}",
                ownerExpenseId: $lockedExpense->id
            );

            $lockedExpense->delete();
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $owner): array
    {
        $account = $this->account($owner);
        $freeCapital = round((float) $account->free_balance_usd, 4);
        $boxesTotalBalance = round((float) Box::query()->sum('current_balance'), 4);
        $capitalBalance = $this->capitalBalance($freeCapital, $boxesTotalBalance);

        return [
            'capital_balance' => $capitalBalance,
            'boxes_total_balance' => $boxesTotalBalance,
            'free_capital' => $freeCapital,
            'monthly_expenses' => round((float) $owner->ownerExpenses()
                ->whereYear('expense_date', now()->year)
                ->whereMonth('expense_date', now()->month)
                ->sum('amount'), 4),
            'yearly_expenses' => round((float) $owner->ownerExpenses()
                ->whereYear('expense_date', now()->year)
                ->sum('amount'), 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function expenseReport(User $owner, array $filters): array
    {
        $query = $this->expenseQuery($owner, $filters);
        $rows = (clone $query)
            ->selectRaw('category')
            ->selectRaw('COUNT(*) as expenses_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($row): array => [
                'category' => $row->category,
                'expenses_count' => (int) $row->expenses_count,
                'total_amount' => round((float) $row->total_amount, 4),
            ])
            ->all();

        return [
            'total_expenses' => round((float) (clone $query)->sum('amount'), 4),
            'expenses_count' => (clone $query)->count(),
            'by_category' => $rows,
            'expenses' => (clone $query)->latest('expense_date')->latest('id')->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function capitalReport(User $owner, array $filters): array
    {
        $query = $this->transactionQuery($owner, $filters);
        $account = $this->account($owner);
        $freeCapital = round((float) $account->free_balance_usd, 4);
        $boxesTotalBalance = round((float) Box::query()->sum('current_balance'), 4);
        $capitalBalance = $this->capitalBalance($freeCapital, $boxesTotalBalance);
        $rows = (clone $query)
            ->selectRaw('type')
            ->selectRaw('COUNT(*) as transactions_count')
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('type')
            ->orderBy('type')
            ->get()
            ->map(fn ($row): array => [
                'type' => $row->type,
                'transactions_count' => (int) $row->transactions_count,
                'total_amount' => round((float) $row->total_amount, 4),
            ])
            ->all();

        return [
            'capital_balance' => $capitalBalance,
            'free_capital' => $freeCapital,
            'boxes_total_balance' => $boxesTotalBalance,
            'net_worth' => $capitalBalance,
            'by_type' => $rows,
            'transactions' => (clone $query)->latest('transaction_date')->latest('id')->get(),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function netWorthReport(User $owner): array
    {
        $account = $this->account($owner);
        $freeCapital = round((float) $account->free_balance_usd, 4);
        $boxesTotalBalance = round((float) Box::query()->sum('current_balance'), 4);
        $capitalBalance = $this->capitalBalance($freeCapital, $boxesTotalBalance);

        return [
            'capital_balance' => $capitalBalance,
            'free_capital' => $freeCapital,
            'boxes_total_balance' => $boxesTotalBalance,
            'net_worth' => $capitalBalance,
        ];
    }

    private function capitalBalance(float $freeCapital, float $boxesTotalBalance): float
    {
        return round($freeCapital + $boxesTotalBalance, 4);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function capitalAccountSummaries(User $actor): array
    {
        return CapitalAccount::query()
            ->where('user_id', $actor->id)
            ->selectRaw('currency')
            ->selectRaw("SUM(CASE WHEN type = 'owner' THEN total_balance ELSE 0 END) as own_capital")
            ->selectRaw("SUM(CASE WHEN type != 'owner' THEN total_balance ELSE 0 END) as investor_capital")
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'currency' => $this->normalizeCurrency($row->currency),
                'own_capital' => $this->roundMoney($row->own_capital),
                'investor_capital' => $this->roundMoney($row->investor_capital),
                'total_capital' => $this->roundMoney((float) $row->own_capital + (float) $row->investor_capital),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function capitalAccountRow(CapitalAccount $account): array
    {
        $type = $account->type instanceof CapitalAccountType ? $account->type->value : (string) $account->type;
        $currentBalance = $this->roundMoney($account->total_balance ?? $account->balance_usd ?? 0);

        return [
            'id' => $account->id,
            'name' => $account->name ?: 'Owner Capital',
            'type' => $type === CapitalAccountType::Owner->value ? 'own' : 'investor',
            'backend_type' => $type,
            'currency' => $this->normalizeCurrency($account->currency ?? 'USD'),
            'current_balance' => $currentBalance,
            'total_balance' => $currentBalance,
            'unallocated_balance' => $this->roundMoney($account->unallocated_balance ?? $account->free_balance_usd ?? 0),
            'allocated_balance' => $this->roundMoney($account->allocated_balance ?? 0),
            'last_movement_date' => $account->last_movement_date,
            'movements_count' => (int) ($account->transactions_count ?? $account->transactions()->count()),
            'notes' => $account->notes,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capitalMovementRow(CapitalTransaction $transaction): array
    {
        $type = (string) $transaction->type;
        $direction = in_array($type, [CapitalMovementType::Withdrawal->value, 'withdraw'], true) ? 'out' : 'in';
        $signedAmount = $direction === 'out'
            ? -1 * abs($this->roundMoney($transaction->amount))
            : abs($this->roundMoney($transaction->amount));

        return [
            'id' => $transaction->id,
            'capital_account_id' => $transaction->capital_account_id,
            'type' => $type,
            'direction' => $direction,
            'amount' => $signedAmount,
            'absolute_amount' => abs($signedAmount),
            'currency' => $this->normalizeCurrency($transaction->currency ?? 'USD'),
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'transaction_at' => $transaction->transaction_at,
            'balance_after' => $this->roundMoney($transaction->total_balance_after ?? $transaction->balance_after ?? 0),
            'reference_number' => $transaction->reference_number,
            'statement' => $transaction->statement,
            'notes' => $transaction->notes,
            'created_by' => $transaction->creator,
            'updated_by' => $transaction->updater,
        ];
    }

    private function findReusableCapitalAccount(User $actor, CapitalAccountType $type, string $currency, string $name): ?CapitalAccount
    {
        $query = CapitalAccount::query()
            ->where('user_id', $actor->id)
            ->where('currency', $currency);

        if ($type === CapitalAccountType::Owner) {
            return $query->where('type', CapitalAccountType::Owner->value)->first();
        }

        return $query
            ->where('type', '!=', CapitalAccountType::Owner->value)
            ->where('name', $name)
            ->first();
    }

    private function uiAccountType(mixed $type): CapitalAccountType
    {
        return match ((string) $type) {
            'own', CapitalAccountType::Owner->value => CapitalAccountType::Owner,
            'investor', CapitalAccountType::Company->value, CapitalAccountType::Partner->value => CapitalAccountType::Investor,
            default => throw ValidationException::withMessages([
                'type' => 'نوع حساب رأس المال غير صالح.',
            ]),
        };
    }

    private function lockedAccount(User $owner): CapitalAccount
    {
        $this->account($owner);

        return CapitalAccount::query()
            ->where('user_id', $owner->id)
            ->where('type', CapitalAccountType::Owner->value)
            ->where('currency', 'USD')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedCapitalAccount(User $actor, CapitalAccount $account): CapitalAccount
    {
        return CapitalAccount::query()
            ->whereKey($account->id)
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ownedCapitalAccount(User $actor, CapitalAccount $account): CapitalAccount
    {
        return CapitalAccount::query()
            ->whereKey($account->id)
            ->where('user_id', $actor->id)
            ->firstOrFail();
    }

    private function lockedEditableMovement(User $actor, CapitalTransaction $transaction): CapitalTransaction
    {
        $lockedTransaction = CapitalTransaction::query()
            ->with('capitalAccount')
            ->whereKey($transaction->id)
            ->whereHas('capitalAccount', fn (Builder $query): Builder => $query->where('user_id', $actor->id))
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($lockedTransaction->type, $this->ownershipMovementValues(), true)) {
            throw ValidationException::withMessages([
                'transaction' => 'لا يمكن تعديل أو حذف هذه الحركة من مسار إدارة ملكية رأس المال.',
            ]);
        }

        return $lockedTransaction;
    }

    private function moveCapital(User $owner, string $type, float $signedAmount, string $transactionDate, ?string $notes): CapitalTransaction
    {
        return DB::transaction(function () use ($owner, $type, $signedAmount, $transactionDate, $notes): CapitalTransaction {
            $account = $this->lockedAccount($owner);

            if ($signedAmount < 0) {
                $this->ensureSufficientFreeCapital($account, abs($signedAmount));
            }

            return $this->recordMovement(
                account: $account,
                type: $type,
                amount: $signedAmount,
                balanceBefore: (float) $account->balance_usd,
                balanceAfter: round((float) $account->balance_usd + $signedAmount, 4),
                freeBalanceAfter: round((float) $account->free_balance_usd + $signedAmount, 4),
                transactionDate: $transactionDate,
                notes: $notes
            );
        }, attempts: 3);
    }

    private function recordMovement(
        CapitalAccount $account,
        string $type,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        ?float $freeBalanceAfter,
        string $transactionDate,
        ?string $notes = null,
        ?int $boxId = null,
        ?int $ownerExpenseId = null,
    ): CapitalTransaction {
        $unallocatedBefore = $this->roundMoney($account->unallocated_balance);
        $allocatedBefore = $this->roundMoney($account->allocated_balance);
        $allocatedAfter = round($balanceAfter - ($freeBalanceAfter ?? $balanceAfter), 4);

        $this->updateAccountBalances(
            account: $account,
            totalBalance: $balanceAfter,
            unallocatedBalance: $freeBalanceAfter ?? $balanceAfter,
            allocatedBalance: $allocatedAfter
        );

        return $account->transactions()->create([
            'user_id' => $account->user_id,
            'created_by' => $account->user_id,
            'box_id' => $boxId,
            'owner_expense_id' => $ownerExpenseId,
            'type' => $type,
            'amount' => round($amount, 4),
            'currency' => $account->currency ?? 'USD',
            'balance_before' => round($balanceBefore, 4),
            'balance_after' => round($balanceAfter, 4),
            'total_balance_before' => round($balanceBefore, 4),
            'total_balance_after' => round($balanceAfter, 4),
            'unallocated_balance_before' => $unallocatedBefore,
            'unallocated_balance_after' => round($freeBalanceAfter ?? $balanceAfter, 4),
            'allocated_balance_before' => $allocatedBefore,
            'allocated_balance_after' => $allocatedAfter,
            'transaction_date' => $transactionDate,
            'transaction_at' => $this->transactionAt(null, $transactionDate),
            'statement' => $notes,
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCapitalMovement(User $actor, CapitalAccount $account, CapitalMovementType $type, array $data): CapitalTransaction
    {
        $this->authorizeCapital($actor, 'capital.movement.create');

        return DB::transaction(function () use ($actor, $account, $type, $data): CapitalTransaction {
            $lockedAccount = $this->lockedCapitalAccount($actor, $account);
            $amount = $this->positiveAmount($data['amount'] ?? 0);
            $currency = $this->transactionCurrency($lockedAccount, $data['currency'] ?? null);
            $boxId = $data['box_id'] ?? null;
            $transactionDate = (string) ($data['transaction_date'] ?? now()->toDateString());
            $snapshot = $this->applyMovementImpact(
                account: $lockedAccount,
                actor: $actor,
                type: $type,
                amount: $amount,
                currency: $currency,
                boxId: $boxId === null || $boxId === '' ? null : (int) $boxId,
                reason: $data['statement'] ?? $data['notes'] ?? null
            );

            $transaction = $lockedAccount->transactions()->create([
                ...$snapshot,
                'user_id' => $lockedAccount->user_id,
                'created_by' => $actor->id,
                'box_id' => $boxId,
                'type' => $type->value,
                'amount' => $amount,
                'currency' => $currency,
                'transaction_date' => $transactionDate,
                'transaction_at' => $this->transactionAt($data['transaction_at'] ?? null, $transactionDate),
                'reference_number' => $data['reference_number'] ?? null,
                'statement' => $data['statement'] ?? $data['notes'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLog::record(
                action: 'capital_movement.created',
                model: $transaction,
                userId: $actor->id,
                newValues: $transaction->attributesToArray()
            );

            $this->assertCapitalInvariant($lockedAccount->refresh());

            return $transaction->load('capitalAccount', 'box', 'creator');
        }, attempts: 3);
    }

    /**
     * @return array<string, float|null>
     */
    private function applyMovementImpact(
        CapitalAccount $account,
        User $actor,
        CapitalMovementType $type,
        float $amount,
        string $currency,
        ?int $boxId,
        mixed $reason,
    ): array {
        $totalBefore = $this->roundMoney($account->total_balance);
        $unallocatedBefore = $this->roundMoney($account->unallocated_balance);
        $allocatedBefore = $this->roundMoney($account->allocated_balance);
        $boxBalanceBefore = null;
        $boxBalanceAfter = null;

        $box = $boxId === null ? null : Box::query()->whereKey($boxId)->lockForUpdate()->firstOrFail();

        if ($box !== null) {
            $this->ensureBoxCurrencyMatches($account, $box);
            $boxBalanceBefore = $this->roundMoney($box->current_balance);
        }

        [$totalDelta, $unallocatedDelta, $allocatedDelta, $boxDelta] = match ($type) {
            CapitalMovementType::InitialDeposit, CapitalMovementType::TopUp => $box === null
                ? [$amount, $amount, 0.0, 0.0]
                : [$amount, 0.0, $amount, $amount],
            CapitalMovementType::Withdrawal => $box === null
                ? [-1 * $amount, -1 * $amount, 0.0, 0.0]
                : [-1 * $amount, 0.0, -1 * $amount, -1 * $amount],
            CapitalMovementType::Allocation => [0.0, -1 * $amount, $amount, $amount],
            CapitalMovementType::Deallocation => [0.0, $amount, -1 * $amount, -1 * $amount],
            default => throw ValidationException::withMessages([
                'type' => 'نوع حركة رأس المال غير صالح.',
            ]),
        };

        if (in_array($type, [CapitalMovementType::Allocation, CapitalMovementType::Deallocation], true) && $box === null) {
            throw ValidationException::withMessages([
                'box_id' => 'الصندوق مطلوب لحركة التخصيص أو فك التخصيص.',
            ]);
        }

        $totalAfter = $this->roundMoney($totalBefore + $totalDelta);
        $unallocatedAfter = $this->roundMoney($unallocatedBefore + $unallocatedDelta);
        $allocatedAfter = $this->roundMoney($allocatedBefore + $allocatedDelta);

        if ($totalAfter < 0 || $unallocatedAfter < 0 || $allocatedAfter < 0) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد رأس المال المتاح غير كافٍ.',
            ]);
        }

        if ($box !== null) {
            if ($allocatedDelta > 0) {
                $this->increaseAllocation($account, $box, $allocatedDelta);
            } elseif ($allocatedDelta < 0) {
                $this->decreaseAllocation($account, $box, abs($allocatedDelta));
            }

            $boxBalanceAfter = $this->moveBoxBalance($box, $actor, $boxDelta, $reason);
        }

        $this->updateAccountBalances($account, $totalAfter, $unallocatedAfter, $allocatedAfter);

        return [
            'balance_before' => $totalBefore,
            'balance_after' => $totalAfter,
            'total_balance_before' => $totalBefore,
            'total_balance_after' => $totalAfter,
            'unallocated_balance_before' => $unallocatedBefore,
            'unallocated_balance_after' => $unallocatedAfter,
            'allocated_balance_before' => $allocatedBefore,
            'allocated_balance_after' => $allocatedAfter,
            'box_balance_before' => $boxBalanceBefore,
            'box_balance_after' => $boxBalanceAfter,
        ];
    }

    private function reverseMovementImpact(CapitalAccount $account, User $actor, CapitalTransaction $transaction): void
    {
        $type = CapitalMovementType::from((string) $transaction->type);
        $amount = $this->positiveAmount($transaction->amount);
        $reverseType = match ($type) {
            CapitalMovementType::InitialDeposit, CapitalMovementType::TopUp => CapitalMovementType::Withdrawal,
            CapitalMovementType::Withdrawal => CapitalMovementType::TopUp,
            CapitalMovementType::Allocation => CapitalMovementType::Deallocation,
            CapitalMovementType::Deallocation => CapitalMovementType::Allocation,
            default => throw ValidationException::withMessages([
                'type' => 'نوع حركة رأس المال غير صالح للعكس.',
            ]),
        };

        $this->applyMovementImpact(
            account: $account,
            actor: $actor,
            type: $reverseType,
            amount: $amount,
            currency: (string) $transaction->currency,
            boxId: $transaction->box_id,
            reason: "Reversal for capital movement #{$transaction->id}"
        );
    }

    private function updateAccountBalances(CapitalAccount $account, float $totalBalance, float $unallocatedBalance, float $allocatedBalance): void
    {
        $payload = [
            'total_balance' => $this->roundMoney($totalBalance),
            'unallocated_balance' => $this->roundMoney($unallocatedBalance),
            'allocated_balance' => $this->roundMoney($allocatedBalance),
        ];

        if ($this->normalizeCurrency($account->currency ?? 'USD') === 'USD') {
            $payload['balance_usd'] = $payload['total_balance'];
            $payload['free_balance_usd'] = $payload['unallocated_balance'];
        }

        $account->update($payload);
    }

    private function increaseAllocation(CapitalAccount $account, Box $box, float $amount): CapitalBoxAllocation
    {
        $allocation = $this->lockedAllocation($account, $box);
        $allocation->update([
            'allocated_balance' => $this->roundMoney((float) $allocation->allocated_balance + $amount),
        ]);

        return $allocation;
    }

    private function decreaseAllocation(CapitalAccount $account, Box $box, float $amount): CapitalBoxAllocation
    {
        $allocation = $this->lockedAllocation($account, $box);
        $balanceAfter = $this->roundMoney((float) $allocation->allocated_balance - $amount);

        if ($balanceAfter < 0) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد التخصيص في الصندوق غير كافٍ.',
            ]);
        }

        $allocation->update(['allocated_balance' => $balanceAfter]);

        return $allocation;
    }

    private function lockedAllocation(CapitalAccount $account, Box $box): CapitalBoxAllocation
    {
        $this->ensureBoxCurrencyMatches($account, $box);

        $allocation = CapitalBoxAllocation::query()->firstOrCreate([
            'capital_account_id' => $account->id,
            'box_id' => $box->id,
            'currency' => $this->normalizeCurrency($account->currency),
        ], [
            'allocated_balance' => 0,
        ]);

        return CapitalBoxAllocation::query()
            ->whereKey($allocation->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function moveBoxBalance(Box $box, User $actor, float $delta, mixed $reason): float
    {
        $balanceBefore = $this->roundMoney($box->current_balance);
        $balanceAfter = $this->roundMoney($balanceBefore + $delta);

        if ($balanceAfter < 0) {
            throw ValidationException::withMessages([
                'box_id' => 'رصيد الصندوق غير كافٍ.',
            ]);
        }

        if ($delta === 0.0) {
            return $balanceAfter;
        }

        $box->update(['current_balance' => $balanceAfter]);
        $box->balanceLogs()->create([
            'operation_type' => $delta > 0 ? BoxBalanceOperationType::Add->value : BoxBalanceOperationType::Subtract->value,
            'amount' => abs($this->roundMoney($delta)),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reason' => 'capital_movement',
            'notes' => $reason,
            'created_by' => $actor->id,
        ]);

        return $balanceAfter;
    }

    private function assertCapitalInvariant(CapitalAccount $account): void
    {
        $total = $this->roundMoney($account->total_balance);
        $unallocated = $this->roundMoney($account->unallocated_balance);
        $allocated = $this->roundMoney($account->allocated_balance);
        $allocationSum = $this->roundMoney($account->boxAllocations()->sum('allocated_balance'));

        if ($total !== $this->roundMoney($unallocated + $allocated) || $allocated !== $allocationSum) {
            throw ValidationException::withMessages([
                'capital_account' => 'معادلة رأس المال غير متوازنة.',
            ]);
        }
    }

    /**
     * @param  array{total: float, unallocated: float, allocated: float}  $running
     * @return array{total: float, unallocated: float, allocated: float}
     */
    private function applyStatementDeltas(array $running, CapitalTransaction $transaction): array
    {
        $amount = $this->roundMoney($transaction->amount);
        $hasBox = $transaction->box_id !== null;

        return match (CapitalMovementType::from((string) $transaction->type)) {
            CapitalMovementType::InitialDeposit, CapitalMovementType::TopUp => [
                'total' => $this->roundMoney($running['total'] + $amount),
                'unallocated' => $this->roundMoney($running['unallocated'] + ($hasBox ? 0 : $amount)),
                'allocated' => $this->roundMoney($running['allocated'] + ($hasBox ? $amount : 0)),
            ],
            CapitalMovementType::Withdrawal => [
                'total' => $this->roundMoney($running['total'] - $amount),
                'unallocated' => $this->roundMoney($running['unallocated'] - ($hasBox ? 0 : $amount)),
                'allocated' => $this->roundMoney($running['allocated'] - ($hasBox ? $amount : 0)),
            ],
            CapitalMovementType::Allocation => [
                'total' => $running['total'],
                'unallocated' => $this->roundMoney($running['unallocated'] - $amount),
                'allocated' => $this->roundMoney($running['allocated'] + $amount),
            ],
            CapitalMovementType::Deallocation => [
                'total' => $running['total'],
                'unallocated' => $this->roundMoney($running['unallocated'] + $amount),
                'allocated' => $this->roundMoney($running['allocated'] - $amount),
            ],
            default => $running,
        };
    }

    /**
     * @return list<string>
     */
    private function ownershipMovementValues(): array
    {
        return [
            CapitalMovementType::InitialDeposit->value,
            CapitalMovementType::TopUp->value,
            CapitalMovementType::Withdrawal->value,
            CapitalMovementType::Allocation->value,
            CapitalMovementType::Deallocation->value,
        ];
    }

    private function authorizeCapital(User $actor, string $permission): void
    {
        if ($actor->hasAnyRole(['owner', 'admin'], 'sanctum') || $actor->can($permission)) {
            return;
        }

        throw new AuthorizationException('غير مصرح');
    }

    private function normalizeAccountType(mixed $type): CapitalAccountType
    {
        $type = trim((string) $type);

        return CapitalAccountType::tryFrom($type)
            ?? throw ValidationException::withMessages([
                'type' => 'نوع مالك رأس المال غير صالح.',
            ]);
    }

    private function transactionCurrency(CapitalAccount $account, mixed $currency): string
    {
        $currency = $this->normalizeCurrency($currency ?? $account->currency ?? 'USD');

        if ($currency !== $this->normalizeCurrency($account->currency ?? 'USD')) {
            throw ValidationException::withMessages([
                'currency' => 'عملة الحركة يجب أن تطابق عملة حساب رأس المال.',
            ]);
        }

        return $currency;
    }

    private function ensureBoxCurrencyMatches(CapitalAccount $account, Box $box): void
    {
        if ($this->normalizeCurrency($box->currency) !== $this->normalizeCurrency($account->currency ?? 'USD')) {
            throw ValidationException::withMessages([
                'box_id' => 'عملة الصندوق يجب أن تطابق عملة حساب رأس المال.',
            ]);
        }
    }

    private function normalizeCurrency(mixed $currency): string
    {
        $currency = mb_strtoupper(trim((string) $currency));

        if ($currency === '') {
            throw ValidationException::withMessages([
                'currency' => 'العملة مطلوبة.',
            ]);
        }

        return $currency;
    }

    private function nonNegativeAmount(mixed $amount): float
    {
        $amount = $this->roundMoney($amount);

        if ($amount < 0) {
            throw ValidationException::withMessages([
                'amount' => 'المبلغ يجب أن يكون صفراً أو أكبر.',
            ]);
        }

        return $amount;
    }

    private function positiveAmount(mixed $amount): float
    {
        $amount = $this->roundMoney($amount);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'المبلغ يجب أن يكون أكبر من صفر.',
            ]);
        }

        return $amount;
    }

    private function roundMoney(mixed $amount): float
    {
        return round((float) $amount, 4);
    }

    private function transactionAt(mixed $transactionAt, string $transactionDate): Carbon
    {
        return Carbon::parse($transactionAt ?: $transactionDate);
    }

    private function ensureSufficientCapital(CapitalAccount $account, float $amount): void
    {
        if ((float) $account->balance_usd < $amount) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد رأس المال غير كافٍ.',
            ]);
        }
    }

    private function ensureSufficientFreeCapital(CapitalAccount $account, float $amount): void
    {
        if ((float) $account->free_balance_usd < $amount) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد رأس المال غير كافٍ.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OwnerExpense>
     */
    private function expenseQuery(User $owner, array $filters): Builder
    {
        return OwnerExpense::query()
            ->where('user_id', $owner->id)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expense_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expense_date', '<=', $date))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category): Builder => $query->where('category', $category));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CapitalTransaction>
     */
    private function transactionQuery(User $owner, array $filters): Builder
    {
        return CapitalTransaction::query()
            ->where('user_id', $owner->id)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('transaction_date', '<=', $date));
    }
}
