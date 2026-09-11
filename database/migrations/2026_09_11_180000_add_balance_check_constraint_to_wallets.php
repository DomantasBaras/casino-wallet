<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Defence in depth for the invariant proven in docs/race-condition.md.
     *
     * Deliberately added only now, after the race was demonstrated. Had this
     * existed during step 5, the naive endpoint would have failed with a
     * database error instead of quietly producing a wrong balance, and the
     * more interesting failure — the ledger and the balance disagreeing while
     * every response looked fine — would have been hidden behind a 500.
     *
     * With the atomic conditional UPDATE in place this constraint should never
     * fire. That is the point: if it ever does, the application-level
     * affordability check has regressed, and the database refuses to persist
     * the result rather than letting it through silently.
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE wallets
             ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE wallets DROP CONSTRAINT wallets_balance_non_negative'
        );
    }
};
