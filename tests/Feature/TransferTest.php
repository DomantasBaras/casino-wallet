<?php

namespace Tests\Feature;

use App\Exceptions\CurrencyMismatch;
use App\Exceptions\DuplicateTransfer;
use App\Exceptions\InsufficientFunds;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\TransferFunds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * As with BetEndpointTest, these cover logic rather than concurrency. The
 * deadlock-resistance claim is about lock ordering under simultaneous
 * opposite transfers, which a single process cannot produce.
 *
 * Every wallet starts at 100.00 unless a test needs otherwise, so the
 * expected figures can be checked by eye against the fixtures.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private function wallet(string $player, string $balance = '100.0000', string $currency = 'EUR'): Wallet
    {
        return Wallet::create([
            'player_id' => $player,
            'currency' => $currency,
            'balance' => $balance,
        ]);
    }

    private function transfer(Wallet $from, Wallet $to, string $amount): TestResponse
    {
        return $this->postJson('/api/v1/transfers', [
            'from_wallet_id' => $from->id,
            'to_wallet_id' => $to->id,
            'amount' => $amount,
        ]);
    }

    public function test_a_transfer_moves_funds_and_writes_both_legs(): void
    {
        $from = $this->wallet('alice');   // 100.00
        $to = $this->wallet('bob');       // 100.00

        $this->transfer($from, $to, '25.00')->assertCreated();

        $this->assertSame('75.0000', Wallet::find($from->id)->balance);
        $this->assertSame('125.0000', Wallet::find($to->id)->balance);

        $this->assertSame(2, Transaction::query()->count());

        $out = Transaction::query()->where('wallet_id', $from->id)->sole();
        $in = Transaction::query()->where('wallet_id', $to->id)->sole();

        $this->assertSame('-25.0000', $out->amount);
        $this->assertSame('75.0000', $out->balance_after);
        $this->assertSame('25.0000', $in->amount);
        $this->assertSame('125.0000', $in->balance_after);
    }

    /**
     * Money is conserved: the two legs cancel exactly, and the pair of
     * balances still sums to what it did before.
     */
    public function test_a_transfer_creates_no_money(): void
    {
        $from = $this->wallet('alice');
        $to = $this->wallet('bob');

        $this->transfer($from, $to, '33.3333')->assertCreated();

        $this->assertSame(0, bccomp((string) Transaction::query()->sum('amount'), '0', 4));

        $this->assertSame('66.6667', Wallet::find($from->id)->balance);
        $this->assertSame('133.3333', Wallet::find($to->id)->balance);

        $this->assertSame(
            0,
            bccomp(
                bcadd(
                    Wallet::find($from->id)->balance,
                    Wallet::find($to->id)->balance,
                    4,
                ),
                '200.0000',
                4,
            ),
        );
    }

    public function test_insufficient_funds_changes_nothing(): void
    {
        $from = $this->wallet('alice', '10.0000');
        $to = $this->wallet('bob');

        try {
            app(TransferFunds::class)->execute($from->id, $to->id, '25.00');
            $this->fail('Expected InsufficientFunds.');
        } catch (InsufficientFunds) {
        }

        $this->assertSame('10.0000', Wallet::find($from->id)->balance);
        $this->assertSame('100.0000', Wallet::find($to->id)->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * The debit and the credit are separate statements. If the transfer is
     * refused after one of them, the other must not survive — this asserts
     * the rollback, not merely the refusal.
     */
    public function test_a_currency_mismatch_changes_nothing(): void
    {
        $from = $this->wallet('alice', '100.0000', 'EUR');
        $to = $this->wallet('bob', '100.0000', 'USD');

        try {
            app(TransferFunds::class)->execute($from->id, $to->id, '25.00');
            $this->fail('Expected CurrencyMismatch.');
        } catch (CurrencyMismatch) {
        }

        $this->assertSame('100.0000', Wallet::find($from->id)->balance);
        $this->assertSame('100.0000', Wallet::find($to->id)->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_a_wallet_cannot_transfer_to_itself(): void
    {
        $wallet = $this->wallet('alice');

        $this->expectException(InvalidArgumentException::class);

        app(TransferFunds::class)->execute($wallet->id, $wallet->id, '25.00');
    }

    public function test_non_positive_amounts_are_rejected(): void
    {
        $from = $this->wallet('alice');
        $to = $this->wallet('bob');

        foreach (['0.00', '0.0000'] as $amount) {
            try {
                app(TransferFunds::class)->execute($from->id, $to->id, $amount);
                $this->fail("Expected rejection of {$amount}.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame('100.0000', Wallet::find($from->id)->balance);
        $this->assertSame('100.0000', Wallet::find($to->id)->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * Replaying a transfer id moves the money exactly once. The second call
     * returns the original id marked as a replay rather than throwing —
     * the same idempotent contract the bet path honours (ADR 0003). The
     * pre-check also keeps a duplicate out of the deadlock-retry loop, where
     * it would otherwise be attempted three times before being recognised.
     */
    public function test_a_repeated_transfer_id_is_replayed_not_reapplied(): void
    {
        $from = $this->wallet('alice');
        $to = $this->wallet('bob');

        $first = app(TransferFunds::class)->execute($from->id, $to->id, '25.00');
        $this->assertFalse($first->replayed);

        $second = app(TransferFunds::class)->execute($from->id, $to->id, '25.00', $first->transferId);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->transferId, $second->transferId);

        $this->assertSame('75.0000', Wallet::find($from->id)->balance);
        $this->assertSame('125.0000', Wallet::find($to->id)->balance);
        $this->assertSame(2, Transaction::query()->count());
    }

    /**
     * A distinct transfer id moves money again, confirming the duplicate
     * check keys on the id rather than on the amount or the wallet pair.
     */
    public function test_a_second_transfer_with_a_new_id_is_applied(): void
    {
        $from = $this->wallet('alice');
        $to = $this->wallet('bob');

        app(TransferFunds::class)->execute($from->id, $to->id, '25.00');
        app(TransferFunds::class)->execute($from->id, $to->id, '25.00');

        $this->assertSame('50.0000', Wallet::find($from->id)->balance);
        $this->assertSame('150.0000', Wallet::find($to->id)->balance);
        $this->assertSame(4, Transaction::query()->count());
    }
}
