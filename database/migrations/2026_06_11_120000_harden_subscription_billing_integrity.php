<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardens billing data integrity for production:
 *
 *  1. Adds a DB-level UNIQUE constraint on (gateway, gateway_subscription_id) and
 *     (gateway, gateway_transaction_id). Without these, the webhook handler and the
 *     checkout success-URL fulfilment can race and create duplicate subscriptions or
 *     double-counted payments. The unique index makes idempotency authoritative at the DB.
 *
 *  2. Adds subscriptions.trial_reminder_sent_at so the "your trial is ending" reminder
 *     can be sent exactly once instead of every time the scheduler runs.
 *
 * Existing duplicate rows (e.g. from pre-launch testing) are collapsed first so the
 * unique index can be created. NULL gateway IDs are left untouched (manual/admin
 * subscriptions) — multiple NULLs are permitted by a SQL UNIQUE index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dedupe('subscriptions', 'gateway_subscription_id');
        $this->dedupe('payment_transactions', 'gateway_transaction_id');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('trial_ends_at');
            $table->unique(['gateway', 'gateway_subscription_id'], 'subscriptions_gateway_sub_unique');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            // Replace the non-unique composite index with a unique one on the same columns.
            try {
                $table->dropIndex(['gateway', 'gateway_transaction_id']);
            } catch (Throwable $e) {
                // Index may not exist on some environments — safe to ignore.
            }
            $table->unique(['gateway', 'gateway_transaction_id'], 'payment_tx_gateway_txid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique('subscriptions_gateway_sub_unique');
            $table->dropColumn('trial_reminder_sent_at');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique('payment_tx_gateway_txid_unique');
            $table->index(['gateway', 'gateway_transaction_id']);
        });
    }

    /**
     * Collapse duplicate rows that share the same (gateway, $idColumn), keeping the
     * lowest id. Portable across MySQL and SQLite (no window functions / DB-specific SQL).
     */
    private function dedupe(string $table, string $idColumn): void
    {
        $duplicates = DB::table($table)
            ->select('gateway', $idColumn, DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull($idColumn)
            ->groupBy('gateway', $idColumn)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            DB::table($table)
                ->where('gateway', $dup->gateway)
                ->where($idColumn, $dup->{$idColumn})
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }
    }
};
