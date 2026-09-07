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

        return DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $roundId) {
            // The decision and the write are the same statement. If this returns
            // false the balance was insufficient at the moment of the write —
            // not at the moment of some earlier read.
            if (! $this->wallets->debitIfAffordable($wallet, $amount)) {
                throw new InsufficientFunds();
            }

            // Re-read to get the balance the database actually landed on. The
            // application no longer computes it, so it has to ask.
            $wallet->refresh();

            return $this->wallets->recordTransaction(
                wallet: $wallet,
                type: TransactionType::Bet,
                amount: bcsub('0', $amount, 4),
                balanceAfter: $wallet->balance,
                idempotencyKey: $idempotencyKey,
                roundId: $roundId,
            );
        });
    }
}