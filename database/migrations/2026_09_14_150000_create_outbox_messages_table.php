<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional outbox.
     *
     * A row here is written in the same database transaction as the wallet
     * change it describes, so the two cannot diverge: either the debit and
     * its pending notification both exist, or neither does.
     *
     * The alternative — dispatching a queued job from the service — cannot
     * give that guarantee. Laravel's afterCommit() fixes the ordering problem
     * (a worker picking up a job before the transaction commits), but not the
     * crash between commit and dispatch. At that point the money has moved and
     * the notification is gone, with nothing left to say it should have
     * happened.
     */
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: the notification is evidence about a ledger
            // row, and must not outlive or predecease it silently.
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();

            $table->string('event', 32);
            $table->json('payload');

            // pending -> claimed -> delivered, or -> failed once attempts run out.
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);

            // When this message becomes eligible for delivery. Backoff works by
            // pushing this into the future rather than sleeping a worker.
            $table->timestamp('available_at')->useCurrent();

            // Which worker holds the claim, and since when. A claim older than
            // the timeout is assumed dead and can be reclaimed.
            $table->string('claimed_by', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The worker's claim query filters on status and available_at and
            // orders by id. This index is what keeps that cheap as the table
            // grows with delivered rows.
            $table->index(['status', 'available_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
