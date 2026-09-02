<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\WalletNotFound;
use App\Models\Transaction;
use App\Repositories\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;
class BetService
{
    public function __construct(
        private readonly WalletRepositoryInterface $wallets,
    ) {}

    /**
     * NAIVE ON PURPOSE.
     *
     * Reads the balance, decides in PHP whether it is sufficient, then writes
     * back a value computed from what it read. Between the read and the write
     * another request can do exactly the same thing, and both will believe
     * they were affordable.
     *
     * This is not a strawman — it is the shape most wallet code takes on the
     * first pass, and it passes every single-threaded test you can write.
     *
     * Step 5 breaks it. Step 6 replaces it.
     */
    public function place(
        string $playerId,
        string $currency,
        string $amount,
        string $idempotencyKey,
        ?string $roundId = null,
    ): Transaction {
        $wallet = $this->wallets->findForPlayer($playerId, $currency);

        if ($wallet === null) {
            throw new WalletNotFound();
        }

        // bccomp, not <, because these are decimal strings. Comparing them
        // with PHP's operators would be a second bug on top of the race.
        if (bccomp($wallet->balance, $amount, 4) < 0) {
            throw new InsufficientFunds();
        }

        $newBalance = bcsub($wallet->balance, $amount, 4);

        $this->wallets->overwriteBalance($wallet, $newBalance);

        return DB::transaction(function () use ($wallet, $amount, $newBalance, $idempotencyKey, $roundId) {
            $this->wallets->overwriteBalance($wallet, $newBalance);

            return $this->wallets->recordTransaction(
                wallet: $wallet,
                type: TransactionType::Bet,
                amount: bcsub('0', $amount, 4),
                balanceAfter: $newBalance,
                idempotencyKey: $idempotencyKey,
                roundId: $roundId,
            );
        });
    }
}