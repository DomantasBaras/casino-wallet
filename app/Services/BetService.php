<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\WalletNotFound;
use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Transaction;
use App\Repositories\WalletRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
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
    ): BetResult {
        // Already applied under this key. Return the original result and do no
        // work — including no wallet lookup, so a replay costs one indexed read.
        $existing = $this->wallets->findTransactionByKey($idempotencyKey);

        if ($existing !== null) {
            return new BetResult($existing, replayed: true);
        }

        $wallet = $this->wallets->findForPlayer($playerId, $currency);

        if ($wallet === null) {
            throw new WalletNotFound();
        }

        try {
            $transaction = DB::transaction(function () use ($wallet, $amount, $idempotencyKey, $roundId) {
                if (! $this->wallets->debitIfAffordable($wallet, $amount)) {
                    throw new InsufficientFunds();
                }

                $wallet->refresh();

                $transaction = $this->wallets->recordTransaction(
                    wallet: $wallet,
                    type: TransactionType::Bet,
                    amount: bcsub('0', $amount, 4),
                    balanceAfter: $wallet->balance,
                    idempotencyKey: $idempotencyKey,
                    roundId: $roundId,
                );

                // Same transaction as the debit. Either both land or neither does —
                // which is the only reason this table exists instead of a dispatched job.
                OutboxMessage::create([
                    'transaction_id' => $transaction->id,
                    'event' => 'bet.placed',
                    'payload' => [
                        'player_id' => $wallet->player_id,
                        'currency' => $wallet->currency,
                        'type' => TransactionType::Bet->value,
                        'amount' => (string) $transaction->amount,
                        'balance_after' => (string) $transaction->balance_after,
                        'idempotency_key' => $idempotencyKey,
                        'round_id' => $roundId,
                        'occurred_at' => $transaction->created_at->toIso8601String(),
                    ],
                ]);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first. The unique
            // index rejected this insert and the transaction rolled back, so this
            // request's debit was undone — the wallet was charged exactly once.
            $winner = $this->wallets->findTransactionByKey($idempotencyKey);

            if ($winner === null) {
                throw $e;   // not the collision we expected; don't swallow it
            }

            return new BetResult($winner, replayed: true);
        }

        return new BetResult($transaction, replayed: false);
    }
}