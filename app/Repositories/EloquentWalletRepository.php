<?php

namespace App\Repositories;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class EloquentWalletRepository implements WalletRepositoryInterface
{
    public function findForPlayer(string $playerId, string $currency): ?Wallet
    {
        return Wallet::query()
            ->where('player_id', $playerId)
            ->where('currency', $currency)
            ->first();
    }

    public function findById(int $id): ?Wallet
    {
        return Wallet::query()->find($id);
    }

    public function debitIfAffordable(Wallet $wallet, string $amount): bool
    {
        $affected = Wallet::query()
            ->whereKey($wallet->id)
            ->whereRaw('balance >= ?', [$amount])
            ->update([
                'balance' => DB::raw('balance - ' . $this->quoteAmount($amount)),
            ]);

        return $affected === 1;
    }

    /**
     * Amounts reach here already validated as a decimal string by
     * PlaceBetRequest, but this is going into raw SQL, so re-assert the shape
     * rather than trusting a caller further up.
     */
    private function quoteAmount(string $amount): string
    {
        if (! preg_match('/^\d{1,16}(\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException('Invalid money amount: ' . $amount);
        }

        return $amount;
    }

    public function findTransactionByKey(string $idempotencyKey): ?Transaction
    {
        return Transaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function recordTransaction(
        Wallet $wallet,
        TransactionType $type,
        string $amount,
        string $balanceAfter,
        string $idempotencyKey,
        ?string $roundId = null,
    ): Transaction {
        return $wallet->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'idempotency_key' => $idempotencyKey,
            'round_id' => $roundId,
        ]);
    }
}