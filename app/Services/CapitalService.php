<?php

namespace App\Services;

use App\Enums\BoxBalanceOperationType;
use App\Models\Box;
use App\Models\CapitalAccount;
use App\Models\CapitalTransaction;
use App\Models\OwnerExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CapitalService
{
    public function account(User $owner): CapitalAccount
    {
        return CapitalAccount::query()->firstOrCreate(
            ['user_id' => $owner->id],
            ['balance_usd' => 0, 'free_balance_usd' => 0]
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
            $boxBalanceBefore = (float) $box->current_balance;
            $boxBalanceAfter = round($boxBalanceBefore + $amount, 4);
            $box->update(['current_balance' => $boxBalanceAfter]);

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
            'capital_balance' => round((float) $this->account($owner)->balance_usd, 4),
            'free_capital' => round((float) $this->account($owner)->free_balance_usd, 4),
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

    private function lockedAccount(User $owner): CapitalAccount
    {
        $this->account($owner);

        return CapitalAccount::query()
            ->where('user_id', $owner->id)
            ->lockForUpdate()
            ->firstOrFail();
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
        $account->update([
            'balance_usd' => $balanceAfter,
            'free_balance_usd' => $freeBalanceAfter ?? $balanceAfter,
        ]);

        return $account->transactions()->create([
            'user_id' => $account->user_id,
            'box_id' => $boxId,
            'owner_expense_id' => $ownerExpenseId,
            'type' => $type,
            'amount' => round($amount, 4),
            'balance_before' => round($balanceBefore, 4),
            'balance_after' => round($balanceAfter, 4),
            'transaction_date' => $transactionDate,
            'notes' => $notes,
        ]);
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
