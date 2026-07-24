<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $needsUserForeignReset = ! Schema::hasColumn('capital_accounts', 'type');

        if ($needsUserForeignReset) {
            Schema::table('capital_accounts', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropUnique('capital_accounts_user_id_unique');
            });
        }

        Schema::table('capital_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('capital_accounts', 'name')) {
                $table->string('name', 150)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('capital_accounts', 'type')) {
                $table->string('type', 30)->default('owner')->after('name');
            }

            if (! Schema::hasColumn('capital_accounts', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('type');
            }

            if (! Schema::hasColumn('capital_accounts', 'total_balance')) {
                $table->decimal('total_balance', 18, 4)->default(0)->after('currency');
            }

            if (! Schema::hasColumn('capital_accounts', 'unallocated_balance')) {
                $table->decimal('unallocated_balance', 18, 4)->default(0)->after('total_balance');
            }

            if (! Schema::hasColumn('capital_accounts', 'allocated_balance')) {
                $table->decimal('allocated_balance', 18, 4)->default(0)->after('unallocated_balance');
            }

            if (! Schema::hasColumn('capital_accounts', 'notes')) {
                $table->text('notes')->nullable()->after('free_balance_usd');
            }

            if (! Schema::hasColumn('capital_accounts', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if ($needsUserForeignReset) {
            Schema::table('capital_accounts', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        DB::table('capital_accounts')->update([
            'type' => DB::raw("COALESCE(type, 'owner')"),
            'currency' => DB::raw("COALESCE(currency, 'USD')"),
            'name' => DB::raw("COALESCE(name, 'Owner Capital')"),
            'total_balance' => DB::raw('balance_usd'),
            'unallocated_balance' => DB::raw('free_balance_usd'),
            'allocated_balance' => DB::raw('CASE WHEN balance_usd - free_balance_usd > 0 THEN balance_usd - free_balance_usd ELSE 0 END'),
        ]);

        $this->expandCapitalTransactionTypes();

        Schema::table('capital_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('capital_transactions', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('amount');
            }

            if (! Schema::hasColumn('capital_transactions', 'total_balance_before')) {
                $table->decimal('total_balance_before', 18, 4)->nullable()->after('balance_after');
                $table->decimal('total_balance_after', 18, 4)->nullable()->after('total_balance_before');
                $table->decimal('unallocated_balance_before', 18, 4)->nullable()->after('total_balance_after');
                $table->decimal('unallocated_balance_after', 18, 4)->nullable()->after('unallocated_balance_before');
                $table->decimal('allocated_balance_before', 18, 4)->nullable()->after('unallocated_balance_after');
                $table->decimal('allocated_balance_after', 18, 4)->nullable()->after('allocated_balance_before');
                $table->decimal('box_balance_before', 18, 4)->nullable()->after('allocated_balance_after');
                $table->decimal('box_balance_after', 18, 4)->nullable()->after('box_balance_before');
            }

            if (! Schema::hasColumn('capital_transactions', 'transaction_at')) {
                $table->dateTime('transaction_at')->nullable()->after('transaction_date');
            }

            if (! Schema::hasColumn('capital_transactions', 'reference_number')) {
                $table->string('reference_number', 120)->nullable()->after('transaction_at');
            }

            if (! Schema::hasColumn('capital_transactions', 'statement')) {
                $table->text('statement')->nullable()->after('reference_number');
            }

            if (! Schema::hasColumn('capital_transactions', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('capital_transactions', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('capital_transactions', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('capital_transactions')->update([
            'currency' => DB::raw("COALESCE(currency, 'USD')"),
            'created_by' => DB::raw('COALESCE(created_by, user_id)'),
            'transaction_at' => DB::raw('COALESCE(transaction_at, transaction_date)'),
            'total_balance_before' => DB::raw('COALESCE(total_balance_before, balance_before)'),
            'total_balance_after' => DB::raw('COALESCE(total_balance_after, balance_after)'),
        ]);

        $this->addIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('capital_transactions', 'currency')) {
                $table->dropColumn([
                    'currency',
                    'total_balance_before',
                    'total_balance_after',
                    'unallocated_balance_before',
                    'unallocated_balance_after',
                    'allocated_balance_before',
                    'allocated_balance_after',
                    'box_balance_before',
                    'box_balance_after',
                    'transaction_at',
                    'reference_number',
                    'statement',
                    'deleted_at',
                ]);
            }

            if (Schema::hasColumn('capital_transactions', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }

            if (Schema::hasColumn('capital_transactions', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });

        $this->restoreLegacyCapitalTransactionTypes();

        DB::table('capital_accounts')->where('type', '!=', 'owner')->delete();

        Schema::table('capital_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('capital_accounts', 'type')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex('capital_accounts_user_type_currency_idx');
                $table->dropIndex('capital_accounts_type_currency_idx');
                $table->dropColumn([
                    'name',
                    'type',
                    'currency',
                    'total_balance',
                    'unallocated_balance',
                    'allocated_balance',
                    'notes',
                    'deleted_at',
                ]);
                $table->unique('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            }
        });
    }

    private function expandCapitalTransactionTypes(): void
    {
        if ($this->isMysql()) {
            DB::statement("ALTER TABLE capital_transactions MODIFY type ENUM('deposit', 'withdraw', 'expense', 'box_transfer', 'initial_deposit', 'top_up', 'withdrawal', 'allocation', 'deallocation') NOT NULL");
        }
    }

    private function restoreLegacyCapitalTransactionTypes(): void
    {
        if ($this->isMysql()) {
            DB::statement("ALTER TABLE capital_transactions MODIFY type ENUM('deposit', 'withdraw', 'expense', 'box_transfer') NOT NULL");
        }
    }

    private function addIndexes(): void
    {
        Schema::table('capital_accounts', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'currency'], 'capital_accounts_user_type_currency_idx');
            $table->index(['type', 'currency'], 'capital_accounts_type_currency_idx');
        });

        Schema::table('capital_transactions', function (Blueprint $table) {
            $table->index(['capital_account_id', 'type', 'transaction_date'], 'capital_transactions_account_type_date_idx');
            $table->index('currency', 'capital_transactions_currency_idx');
            $table->index('created_by', 'capital_transactions_created_by_idx');
        });
    }

    private function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
