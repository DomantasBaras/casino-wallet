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
     * Overwrite the balance with a value the caller has already computed.
     *
     * Named "overwrite" rather than "update" on purpose: this is the unsafe
     * write that step 5 will break. Step 6 replaces it with a conditional
     * UPDATE that computes the new balance inside the database.
     */
    public function overwriteBalance(Wallet $wallet, string $newBalance): void;

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