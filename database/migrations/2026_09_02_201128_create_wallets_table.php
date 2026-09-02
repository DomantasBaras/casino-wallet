<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // External player identifier, deliberately not a foreign key to
            // users: in an operator integration the player is owned upstream
            // and this service only holds their balance.
            $table->string('player_id', 64);
            $table->char('currency', 3);

            // DECIMAL(20,4) — exact, four decimal places for sub-cent amounts.
            // See docs/adr/0001-money-representation.md.
            //
            // Not UNSIGNED: MySQL deprecated the UNSIGNED attribute on DECIMAL
            // in 8.0.17. The balance >= 0 invariant lives in application code
            // for now, and gains a CHECK constraint in step 6 — deliberately
            // not before, so that step 5 can show the race as a negative
            // balance rather than as a database error.
            $table->decimal('balance', 20, 4)->default(0);

            $table->timestamps();

            // One wallet per player per currency.
            $table->unique(['player_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
