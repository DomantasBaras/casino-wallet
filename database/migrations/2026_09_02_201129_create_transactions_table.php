<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, not cascade: a ledger row must outlive any
            // attempt to delete the wallet it belongs to.
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            $table->string('type', 16);

            // Signed: bets are negative, wins positive. This makes the ledger
            // invariant in step 7 a single query — SUM(amount) must equal the
            // wallet balance — instead of a conditional sum by type.
            $table->decimal('amount', 20, 4);

            // Balance immediately after this row was applied. Redundant with
            // the running sum, and that is the point: it lets a reviewer spot
            // a broken sequence by eye.
            $table->decimal('balance_after', 20, 4);

            $table->string('round_id', 64)->nullable();
            $table->string('game_id', 64)->nullable();

            // The uniqueness constraint is the idempotency mechanism itself:
            // a duplicate request fails at the database, not at a race-prone
            // application check. Step 8 builds on this.
            $table->string('idempotency_key', 64)->unique();

            // created_at only. A ledger is append-only — there is no such
            // thing as updating a transaction.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
