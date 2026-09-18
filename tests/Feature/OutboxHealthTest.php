<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The health endpoint turns the outbox's state into numbers a monitor can
 * scrape, and a coarse ok/degraded verdict for a cheap liveness check. These
 * tests pin the three signals and the thresholds at which the verdict flips.
 */
class OutboxHealthTest extends TestCase
{
    use RefreshDatabase;

    private ?Wallet $wallet = null;

    /**
     * Every outbox row needs a transaction to point at (non-null FK). One
     * shared wallet, a fresh transaction per call — these tests care about
     * outbox state, not ledger correctness.
     */
    private function transactionId(): int
    {
        $this->wallet ??= Wallet::create([
            'player_id' => 'player-test',
            'currency' => 'EUR',
            'balance' => '100.0000',
        ]);

        return Transaction::create([
            'wallet_id' => $this->wallet->id,
            'type' => 'bet',
            'amount' => '-10.0000',
            'balance_after' => '90.0000',
            'idempotency_key' => 'k-' . uniqid(),
        ])->id;
    }

    private function message(array $attributes): OutboxMessage
    {
        return OutboxMessage::create(array_merge([
            'transaction_id' => $this->transactionId(),
            'event' => 'bet.placed',
            'payload' => ['x' => 1],
            'status' => OutboxStatus::Pending,
            'available_at' => now(),
        ], $attributes));
    }

    public function test_an_empty_outbox_is_healthy(): void
    {
        $this->getJson('/api/v1/outbox/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'counts' => ['pending' => 0, 'claimed' => 0, 'delivered' => 0, 'failed' => 0],
                'oldest_pending_age_seconds' => 0,
                'stale_claimed' => 0,
            ]);
    }

    public function test_it_counts_messages_by_status(): void
    {
        $this->message(['status' => OutboxStatus::Pending]);
        $this->message(['status' => OutboxStatus::Delivered]);
        $this->message(['status' => OutboxStatus::Delivered]);

        $this->getJson('/api/v1/outbox/health')
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('counts.delivered', 2);
    }

    /**
     * A single dead-letter flips the verdict to degraded and returns 503, so
     * a liveness probe fails loudly rather than the failure sitting silent
     * until someone runs a query.
     */
    public function test_a_failed_message_makes_it_degraded(): void
    {
        $this->message(['status' => OutboxStatus::Failed]);

        $this->getJson('/api/v1/outbox/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('counts.failed', 1);
    }

    /**
     * The age is measured from availability, so a message still in backoff is
     * not counted as late — it is waiting, not stuck.
     */
    public function test_a_message_in_backoff_is_not_counted_as_late(): void
    {
        $this->message([
            'status' => OutboxStatus::Pending,
            'available_at' => now()->addMinutes(10),
        ]);

        $this->getJson('/api/v1/outbox/health')
            ->assertOk()
            ->assertJsonPath('oldest_pending_age_seconds', 0)
            ->assertJsonPath('status', 'ok');
    }

    /**
     * A deliverable message left sitting past the threshold means nothing is
     * draining. Failed count is zero here — this is the signal that catches a
     * dead worker, which the failed count alone would miss.
     */
    public function test_an_old_pending_message_makes_it_degraded(): void
    {
        $threshold = config('outbox.health.oldest_pending_degraded_seconds');

        $this->message([
            'status' => OutboxStatus::Pending,
            'available_at' => now()->subSeconds($threshold + 60),
        ]);

        $response = $this->getJson('/api/v1/outbox/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded');

        $this->assertGreaterThanOrEqual(
            $threshold,
            $response->json('oldest_pending_age_seconds'),
        );
    }

    /**
     * A claim older than the timeout is a worker that died mid-delivery. One
     * is worth surfacing; the drainer will recover it, but a growing count
     * means workers are dying faster than they recover.
     */
    public function test_a_stale_claim_makes_it_degraded(): void
    {
        $timeout = config('outbox.claim_timeout_minutes');

        $this->message([
            'status' => OutboxStatus::Claimed,
            'claimed_by' => 'dead-worker',
            'claimed_at' => now()->subMinutes($timeout + 1),
        ]);

        $this->getJson('/api/v1/outbox/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('stale_claimed', 1);
    }

    /**
     * A claim inside the timeout is a worker still working, not a failure.
     */
    public function test_a_fresh_claim_is_not_stale(): void
    {
        $this->message([
            'status' => OutboxStatus::Claimed,
            'claimed_by' => 'live-worker',
            'claimed_at' => now(),
        ]);

        $this->getJson('/api/v1/outbox/health')
            ->assertOk()
            ->assertJsonPath('stale_claimed', 0)
            ->assertJsonPath('status', 'ok');
    }
}