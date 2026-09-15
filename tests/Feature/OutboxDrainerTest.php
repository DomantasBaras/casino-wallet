<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\OutboxDrainer;
use App\Services\Partner\PartnerClient;
use App\Services\Partner\PartnerRejected;
use App\Services\Partner\PartnerUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A partner that fails on command. The interesting cases here are all
 * failure cases, and a real endpoint cannot be made to time out on cue.
 */
class FakePartner implements PartnerClient
{
    /** @var array<int, array{event: string, key: string}> */
    public array $received = [];

    public ?\Throwable $throw = null;

    public function send(string $event, array $payload, string $idempotencyKey): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->received[] = ['event' => $event, 'key' => $idempotencyKey];
    }
}

class OutboxDrainerTest extends TestCase
{
    use RefreshDatabase;

    private FakePartner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = new FakePartner();
        $this->app->instance(PartnerClient::class, $this->partner);
    }

    private function message(array $overrides = []): OutboxMessage
    {
        $wallet = Wallet::create([
            'player_id' => 'player-' . uniqid(),
            'currency' => 'EUR',
            'balance' => '100.0000',
        ]);

        $transaction = $wallet->transactions()->create([
            'type' => 'bet',
            'amount' => '-10.0000',
            'balance_after' => '90.0000',
            'idempotency_key' => uniqid(),
        ]);

        return OutboxMessage::create(array_merge([
            'transaction_id' => $transaction->id,
            'event' => 'bet.placed',
            'payload' => ['player_id' => $wallet->player_id],
        ], $overrides));
    }

    private function drainer(): OutboxDrainer
    {
        return $this->app->make(OutboxDrainer::class);
    }

    public function test_a_pending_message_is_delivered_and_marked(): void
    {
        $message = $this->message();

        $result = $this->drainer()->drain();

        $this->assertSame(1, $result['delivered']);
        $this->assertCount(1, $this->partner->received);
        $this->assertSame('bet.placed', $this->partner->received[0]['event']);

        $message->refresh();
        $this->assertSame(OutboxStatus::Delivered, $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNull($message->claimed_by);
    }

    public function test_a_delivered_message_is_not_delivered_again(): void
    {
        $this->message();

        $this->drainer()->drain();
        $this->drainer()->drain();

        $this->assertCount(1, $this->partner->received);
    }

    /**
     * The partner is down. The message must survive, not vanish, and must not
     * be retried instantly — a tight retry loop against a struggling partner
     * makes the outage worse.
     */
    public function test_an_unavailable_partner_schedules_a_retry(): void
    {
        $message = $this->message();
        $this->partner->throw = new PartnerUnavailable('connection refused');

        $result = $this->drainer()->drain();

        $this->assertSame(1, $result['retrying']);

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertTrue($message->available_at->isFuture());
        $this->assertStringContainsString('connection refused', $message->last_error);
    }

    /**
     * A message waiting out its backoff must not be picked up early, or the
     * backoff means nothing.
     */
    public function test_a_message_in_backoff_is_skipped(): void
    {
        $this->message(['available_at' => now()->addMinutes(5)]);

        $result = $this->drainer()->drain();

        $this->assertSame(0, $result['claimed']);
        $this->assertCount(0, $this->partner->received);
    }

    public function test_the_retry_delay_grows_with_each_attempt(): void
    {
        $message = $this->message();
        $this->partner->throw = new PartnerUnavailable('down');

        $delays = [];

        foreach (range(1, 3) as $_) {
            $this->drainer()->drain();
            $message->refresh();
            $delays[] = (int) round(now()->diffInSeconds($message->available_at));

            // Skip forward past the backoff so the next drain can claim it.
            Carbon::setTestNow(now()->addSeconds(600));
        }

        Carbon::setTestNow();

        $this->assertTrue(
            $delays[1] > $delays[0] && $delays[2] > $delays[1],
            'each retry should wait longer than the last, got: ' . implode(', ', $delays),
        );
    }

    /**
     * A partner that understood and refused will refuse again. Retrying wastes
     * attempts and delays the human who needs to look at it.
     */
    public function test_a_rejected_message_is_parked_without_retrying(): void
    {
        $message = $this->message();
        $this->partner->throw = new PartnerRejected('unknown player');

        $result = $this->drainer()->drain();

        $this->assertSame(1, $result['failed']);

        $message->refresh();
        $this->assertSame(OutboxStatus::Failed, $message->status);
        $this->assertSame(1, $message->attempts);
    }

    public function test_a_message_is_parked_after_exhausting_its_attempts(): void
    {
        $message = $this->message(['attempts' => 4]);
        $this->partner->throw = new PartnerUnavailable('still down');

        $result = $this->drainer()->drain();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(OutboxStatus::Failed, $message->refresh()->status);
    }

    /**
     * A worker that dies mid-delivery leaves its claim behind. Without this
     * the message would sit in 'claimed' forever and never be delivered.
     */
    public function test_a_stale_claim_is_released(): void
    {
        $message = $this->message([
            'status' => OutboxStatus::Claimed,
            'claimed_by' => 'dead-worker',
            'claimed_at' => now()->subMinutes(30),
        ]);

        $result = $this->drainer()->drain();

        $this->assertSame(1, $result['delivered']);
        $this->assertSame(OutboxStatus::Delivered, $message->refresh()->status);
    }

    /**
     * A claim that is merely recent belongs to a worker still working. Taking
     * it would mean two workers delivering the same event at once.
     */
    public function test_a_fresh_claim_is_left_alone(): void
    {
        $this->message([
            'status' => OutboxStatus::Claimed,
            'claimed_by' => 'busy-worker',
            'claimed_at' => now()->subSeconds(10),
        ]);

        $result = $this->drainer()->drain();

        $this->assertSame(0, $result['claimed']);
        $this->assertCount(0, $this->partner->received);
    }

    public function test_the_batch_size_is_respected(): void
    {
        foreach (range(1, 5) as $_) {
            $this->message();
        }

        $result = $this->drainer()->drain(batchSize: 2);

        $this->assertSame(2, $result['delivered']);
        $this->assertSame(3, OutboxMessage::query()->where('status', OutboxStatus::Pending)->count());
    }

    /**
     * The key must be stable across retries, or the partner cannot tell a
     * retry of one event from two separate events.
     */
    public function test_the_delivery_key_is_stable_across_retries(): void
    {
        $message = $this->message();

        $this->partner->throw = new PartnerUnavailable('down');
        $this->drainer()->drain();

        Carbon::setTestNow(now()->addMinutes(10));
        $this->partner->throw = null;
        $this->drainer()->drain();
        Carbon::setTestNow();

        $this->assertCount(1, $this->partner->received);
        $this->assertSame('outbox-' . $message->id, $this->partner->received[0]['key']);
    }
}
