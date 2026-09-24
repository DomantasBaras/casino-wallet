<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers the limiter's behaviour, not its atomicity under concurrency — a
 * single PHP process cannot produce the interleaving that would prove that.
 * That evidence is scripts/rate-limit-race.sh, per ADR 0005.
 *
 * Runs against real Redis (phpunit.xml points at the same instance the app
 * uses), for the same reason the rest of this suite runs against real MySQL:
 * an in-memory fake would prove nothing about how this Redis script behaves.
 */
class RateLimitBetsTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();

        Redis::del('rate_limit:bets:' . self::IP);
    }

    private function wallet(): Wallet
    {
        return Wallet::create([
            'player_id' => 'player-test',
            'currency' => 'EUR',
            'balance' => '100000.0000',
        ]);
    }

    private function bet(string $key): TestResponse
    {
        return $this->postJson('/api/v1/bets', [
            'player_id' => 'player-test',
            'currency' => 'EUR',
            'amount' => '1.00',
            'idempotency_key' => $key,
        ]);
    }

    public function test_requests_within_the_limit_succeed(): void
    {
        config(['rate_limit.bets' => ['max_attempts' => 3, 'window_seconds' => 60]]);
        $this->wallet();

        foreach (range(1, 3) as $i) {
            $this->bet("k{$i}")->assertCreated();
        }
    }

    public function test_the_request_that_tips_over_the_limit_is_rejected(): void
    {
        config(['rate_limit.bets' => ['max_attempts' => 2, 'window_seconds' => 60]]);
        $this->wallet();

        $this->bet('k1')->assertCreated();
        $this->bet('k2')->assertCreated();

        $this->bet('k3')
            ->assertStatus(429)
            ->assertJson(['error' => 'rate_limited'])
            ->assertHeader('Retry-After');
    }

    /**
     * A rejected request must not reach the controller at all — it is the
     * limiter refusing, not the wallet, so no ledger row should appear.
     */
    public function test_a_rejected_request_never_reaches_the_bet_service(): void
    {
        config(['rate_limit.bets' => ['max_attempts' => 1, 'window_seconds' => 60]]);
        $wallet = $this->wallet();

        $this->bet('k1')->assertCreated();
        $this->bet('k2')->assertStatus(429);

        $this->assertSame('99999.0000', $wallet->fresh()->balance);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_the_window_resets_once_it_elapses(): void
    {
        config(['rate_limit.bets' => ['max_attempts' => 1, 'window_seconds' => 1]]);
        $this->wallet();

        $this->bet('k1')->assertCreated();
        $this->bet('k2')->assertStatus(429);

        sleep(2);

        $this->bet('k3')->assertCreated();
    }
}
