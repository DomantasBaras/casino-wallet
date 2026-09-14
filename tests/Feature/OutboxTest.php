<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The outbox exists so that a wallet change and the obligation to report it
 * cannot diverge. These tests assert that in both directions: a successful
 * debit always leaves a pending message, and a refused one never does.
 *
 * Delivery itself is not covered here — nothing drains the outbox yet.
 */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private function wallet(string $balance = '100.0000'): Wallet
    {
        return Wallet::create([
            'player_id' => 'player-test',
            'currency' => 'EUR',
            'balance' => $balance,
        ]);
    }

    private function bet(string $amount, string $key): TestResponse
    {
        return $this->postJson('/api/v1/bets', [
            'player_id' => 'player-test',
            'currency' => 'EUR',
            'amount' => $amount,
            'idempotency_key' => $key,
        ]);
    }

    public function test_a_debit_leaves_a_pending_message(): void
    {
        $this->wallet();

        $this->bet('10.00', 'k1')->assertCreated();

        $message = OutboxMessage::query()->sole();
        $transaction = Transaction::query()->sole();

        $this->assertSame($transaction->id, $message->transaction_id);
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertNull($message->delivered_at);
    }

    /**
     * The payload has to stand alone. A worker delivering it days later
     * cannot rely on joining back to tables that may have moved on, so
     * everything the partner needs is captured at write time.
     */
    public function test_the_payload_captures_what_the_partner_needs(): void
    {
        $this->wallet();

        $this->bet('10.00', 'k1')->assertCreated();

        $payload = OutboxMessage::query()->sole()->payload;

        $this->assertSame('player-test', $payload['player_id']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('bet', $payload['type']);
        $this->assertSame('-10.0000', $payload['amount']);
        $this->assertSame('90.0000', $payload['balance_after']);
        $this->assertSame('k1', $payload['idempotency_key']);
    }

    /**
     * The point of the whole pattern. A refused debit writes no ledger row,
     * so it must write no message either — otherwise a worker would report a
     * bet that never happened.
     */
    public function test_a_refused_debit_leaves_no_message(): void
    {
        $this->wallet('5.0000');

        $this->bet('10.00', 'k1')->assertStatus(422);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    /**
     * A replay returns the original transaction without doing the work again,
     * so it must not enqueue a second notification. Otherwise a provider
     * retrying a timed-out request would cause the partner to be told twice.
     */
    public function test_a_replayed_bet_does_not_enqueue_a_second_message(): void
    {
        $this->wallet();

        $this->bet('10.00', 'dup')->assertCreated();
        $this->bet('10.00', 'dup')->assertOk();

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, OutboxMessage::query()->count());
    }

    /**
     * One message per ledger row, no more and no less. This is the invariant
     * a drained outbox is checked against.
     */
    public function test_every_ledger_row_has_exactly_one_message(): void
    {
        $this->wallet();

        foreach (range(1, 5) as $i) {
            $this->bet('10.00', "k{$i}")->assertCreated();
        }

        $this->assertSame(5, Transaction::query()->count());
        $this->assertSame(5, OutboxMessage::query()->count());

        $this->assertSame(
            Transaction::query()->orderBy('id')->pluck('id')->all(),
            OutboxMessage::query()->orderBy('transaction_id')->pluck('transaction_id')->all(),
        );
    }
}
