<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * These tests cover the logic of a debit: what gets written, what gets
 * rejected, and whether the ledger reconciles.
 *
 * They deliberately do NOT attempt to prove concurrency safety. A single
 * PHP process cannot produce the interleaving that makes a race visible, and
 * a test that pretends otherwise would give false confidence. The concurrency
 * evidence is scripts/race.sh, captured in docs/race-condition.md.
 *
 * Runs against MySQL, not SQLite: this project is about how a specific
 * database behaves, so testing against a different engine would prove nothing
 * about the thing being claimed.
 */
class BetEndpointTest extends TestCase
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

    public function test_a_bet_debits_the_wallet_and_records_the_ledger(): void
    {
        $wallet = $this->wallet('100.0000');

        $this->bet('10.00', 'k1')
            ->assertCreated()
            ->assertJson(['balance' => '90.0000']);

        $this->assertSame('90.0000', $wallet->fresh()->balance);

        $transaction = Transaction::query()->sole();
        $this->assertSame('-10.0000', $transaction->amount);
        $this->assertSame('90.0000', $transaction->balance_after);
    }

    /**
     * The important half of this is the second assertion. A debit that is
     * refused must leave no trace at all — not a zero-amount row, not a
     * partial write.
     */
    public function test_insufficient_funds_is_rejected_and_writes_nothing(): void
    {
        $wallet = $this->wallet('5.0000');

        $this->bet('10.00', 'k1')
            ->assertStatus(422)
            ->assertJson(['error' => 'insufficient_funds']);

        $this->assertSame('5.0000', $wallet->fresh()->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * Guards the boundary in `balance >= ?`. Spending the balance exactly is
     * allowed; spending the smallest representable amount more is not.
     */
    public function test_the_full_balance_can_be_spent_but_not_a_fraction_more(): void
    {
        $wallet = $this->wallet('10.0000');

        $this->bet('10.00', 'k1')->assertCreated();
        $this->assertSame('0.0000', $wallet->fresh()->balance);

        $this->bet('0.0001', 'k2')->assertStatus(422);
        $this->assertSame('0.0000', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::query()->count());
    }

    /**
     * The invariant from docs/race-condition.md, asserted directly.
     *
     * Note the accepted-count assertion. An earlier version of the
     * concurrency script reported the invariant as holding while every single
     * request was failing with a 500 — zero bets processed satisfies
     * consistency trivially. A reconciliation check alone cannot distinguish
     * a correct system from a dead one, so the expected work must be asserted
     * too.
     */
    public function test_the_ledger_reconciles_across_a_sequence_of_bets(): void
    {
        $wallet = $this->wallet('100.0000');

        $accepted = 0;
        foreach (range(1, 7) as $i) {
            if ($this->bet('10.00', "k{$i}")->status() === 201) {
                $accepted++;
            }
        }

        $this->assertSame(7, $accepted, 'every affordable bet should be accepted');
        $this->assertSame(7, Transaction::query()->count());

        $balance = $wallet->fresh()->balance;
        $ledgerSum = (string) Transaction::query()->sum('amount');

        $this->assertSame('30.0000', $balance);
        $this->assertSame(
            0,
            bccomp(bcadd('100.0000', $ledgerSum, 4), $balance, 4),
            'starting balance plus ledger movement must equal the stored balance',
        );
    }

    /**
     * Each ledger row must record the balance as it stood immediately after
     * that row was applied, so a reviewer can follow the sequence by eye.
     */
    public function test_balance_after_follows_the_sequence(): void
    {
        $this->wallet('100.0000');

        $this->bet('10.00', 'k1');
        $this->bet('25.00', 'k2');
        $this->bet('5.50', 'k3');

        $this->assertSame(
            ['90.0000', '65.0000', '59.5000'],
            Transaction::query()->orderBy('id')->pluck('balance_after')->all(),
        );
    }

    public function test_an_unknown_wallet_is_rejected(): void
    {
        $this->postJson('/api/v1/bets', [
            'player_id' => 'nobody',
            'currency' => 'EUR',
            'amount' => '10.00',
            'idempotency_key' => 'k1',
        ])->assertStatus(404)->assertJson(['error' => 'wallet_not_found']);
    }

    public function test_malformed_amounts_are_rejected_before_reaching_the_service(): void
    {
        $this->wallet('100.0000');

        foreach (['abc', '-10.00', '10.00000', ''] as $i => $amount) {
            $this->bet($amount, "k{$i}")->assertStatus(422);
        }

        $this->assertSame('100.0000', Wallet::query()->sole()->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * Defence in depth from ADR 0002. The application should never attempt
     * this, but if it regresses the database must refuse to persist it.
     */
    public function test_the_database_refuses_a_negative_balance(): void
    {
        $wallet = $this->wallet('10.0000');

        $this->expectException(QueryException::class);

        DB::table('wallets')
            ->where('id', $wallet->id)
            ->update(['balance' => '-1.0000']);
    }
}
