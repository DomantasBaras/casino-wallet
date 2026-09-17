<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A transfer moves money between two wallets and so writes two ledger rows —
 * a debit on the source, a credit on the destination. The outbox has to
 * mirror that: one message per row, each self-describing, both carrying the
 * transfer id that lets the partner pair the halves.
 *
 * The bet side of this is covered in OutboxTest. This is its sibling for the
 * two-legged case. Delivery is not covered here — that is OutboxDrainerTest.
 */
class TransferOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function wallet(string $playerId, string $balance = '100.0000'): Wallet
    {
        return Wallet::create([
            'player_id' => $playerId,
            'currency' => 'EUR',
            'balance' => $balance,
        ]);
    }

    private function transfer(int $from, int $to, string $amount, ?string $transferId = null): TestResponse
    {
        return $this->postJson('/api/v1/transfers', array_filter([
            'from_wallet_id' => $from,
            'to_wallet_id' => $to,
            'amount' => $amount,
            'transfer_id' => $transferId,
        ], fn ($v) => $v !== null));
    }

    public function test_a_transfer_leaves_two_pending_messages(): void
    {
        $from = $this->wallet('sender');
        $to = $this->wallet('receiver');

        $this->transfer($from->id, $to->id, '25.00', 't1')->assertCreated();

        $this->assertSame(2, OutboxMessage::query()->count());

        $events = OutboxMessage::query()->orderBy('id')->pluck('event')->all();
        $this->assertEqualsCanonicalizing(['transfer.debit', 'transfer.credit'], $events);

        foreach (OutboxMessage::query()->get() as $message) {
            $this->assertSame(OutboxStatus::Pending, $message->status);
            $this->assertSame(0, $message->attempts);
            $this->assertNull($message->delivered_at);
        }
    }

    /**
     * Both legs carry the same correlation id — the transfer id — so the
     * partner can pair a debit with its credit. Each also names the other
     * party, so neither event has to be joined to the other to be understood.
     */
    public function test_both_legs_share_a_correlation_id_and_name_the_counterparty(): void
    {
        $from = $this->wallet('sender');
        $to = $this->wallet('receiver');

        $this->transfer($from->id, $to->id, '25.00', 't1')->assertCreated();

        $debit = OutboxMessage::query()->where('event', 'transfer.debit')->sole()->payload;
        $credit = OutboxMessage::query()->where('event', 'transfer.credit')->sole()->payload;

        $this->assertSame('t1', $debit['correlation_id']);
        $this->assertSame('t1', $credit['correlation_id']);

        $this->assertSame('receiver', $debit['counterparty_player_id']);
        $this->assertSame('sender', $credit['counterparty_player_id']);
    }

    /**
     * Each leg's payload describes its own wallet. The debit is the source
     * losing money (negative amount, reduced balance); the credit is the
     * destination gaining it. Getting these crossed would tell the partner
     * the money moved the wrong way.
     */
    public function test_each_payload_captures_its_own_leg(): void
    {
        $from = $this->wallet('sender');
        $to = $this->wallet('receiver');

        $this->transfer($from->id, $to->id, '25.00', 't1')->assertCreated();

        $debit = OutboxMessage::query()->where('event', 'transfer.debit')->sole()->payload;
        $credit = OutboxMessage::query()->where('event', 'transfer.credit')->sole()->payload;

        $this->assertSame('sender', $debit['player_id']);
        $this->assertSame('transfer_out', $debit['type']);
        $this->assertSame('-25.0000', $debit['amount']);
        $this->assertSame('75.0000', $debit['balance_after']);

        $this->assertSame('receiver', $credit['player_id']);
        $this->assertSame('transfer_in', $credit['type']);
        $this->assertSame('25.0000', $credit['amount']);
        $this->assertSame('125.0000', $credit['balance_after']);
    }

    /**
     * A retried transfer collides on the unique ledger key and is refused, so
     * it must not enqueue a second pair — otherwise the partner would be told
     * the same movement twice. Mirrors the replayed-bet case in OutboxTest.
     */
    public function test_a_duplicate_transfer_enqueues_nothing_the_second_time(): void
    {
        $from = $this->wallet('sender');
        $to = $this->wallet('receiver');

        $this->transfer($from->id, $to->id, '25.00', 'dup')->assertCreated();
        $this->transfer($from->id, $to->id, '25.00', 'dup')->assertStatus(200);

        $this->assertSame(2, OutboxMessage::query()->count());
    }
}