<?php

namespace App\Repositories;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;

interface WalletRepositoryInterface
{
    public function findForPlayer(string $playerId, string $currency): ?Wallet;

    public function findById(int $id): ?Wallet;

    /**
     * Atomically debit the wallet if, and only if, it can afford the amount.
     *
     * The affordability check and the write are a single statement, so no other
     * request can act on a balance this one has already decided against. Returns
     * true if the debit was applied, false if the balance was insufficient.
     *
     * Replaces overwriteBalance(), which computed the new balance in PHP from a
     * value it had read earlier — see docs/race-condition.md.
     */
    public function debitIfAffordable(Wallet $wallet, string $amount): bool;
    /**
     * Append a row to the ledger. Amount is signed — negative for debits.
     */
    public function recordTransaction(
        Wallet $wallet,
        TransactionType $type,
        string $amount,
        string $balanceAfter,
        string $idempotencyKey,
        ?string $roundId = null,
    ): Transaction;
}