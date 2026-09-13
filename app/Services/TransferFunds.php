<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Exceptions\CurrencyMismatch;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\WalletNotFound;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Exceptions\DuplicateTransfer;

/**
 * Moves funds between two wallets.
 *
 * This is the case ADR 0002 named as the boundary of the atomic conditional
 * UPDATE: the decision spans two rows, so it cannot live in a single WHERE
 * clause. Pessimistic locking is the right tool here, which brings lock
 * ordering and deadlocks along with it.
 */
class TransferFunds
{
    /**
     * @param  string  $amount  positive decimal string, e.g. "25.00"
     * @param  string|null  $transferId  caller-supplied id makes the transfer
     *                                   idempotent; generated if omitted
     * @return string the transfer id, which both ledger keys derive from
     */
    public function execute(
        int $fromId,
        int $toId,
        string $amount,
        ?string $transferId = null,
    ): string {
        if ($fromId === $toId) {
            throw new InvalidArgumentException('Cannot transfer to the same wallet.');
        }

        if (bccomp($amount, '0', 4) <= 0) {
            throw new InvalidArgumentException('Transfer amount must be positive.');
        }

        $transferId ??= (string) Str::uuid();

        if (Transaction::query()->where('idempotency_key', $transferId . ':out')->exists()) {
            throw new DuplicateTransfer();
        }

        DB::transaction(function () use ($fromId, $toId, $amount, $transferId) {
            // Both rows in one statement, ordered by primary key.
            //
            // The ordering is the whole point. A transfer 10 -> 20 and a
            // concurrent transfer 20 -> 10 would, if each locked its own
            // source first, end up holding the lock the other needs. Sorting
            // by id means every transfer asks for locks in ascending order
            // regardless of direction, so the second one queues behind the
            // first instead of deadlocking against it.
            //
            // Caveat worth knowing: ORDER BY constrains the result order, and
            // InnoDB happens to acquire locks in the order it scans. With a
            // primary-key IN list that scan is ascending, so this works — but
            // it rests on the access path, not on a guarantee from ORDER BY.
            $wallets = Wallet::query()
                ->whereIn('id', [$fromId, $toId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($wallets->count() !== 2) {
                throw new WalletNotFound();
            }

            $from = $wallets->get($fromId);
            $to = $wallets->get($toId);

            if ($from->currency !== $to->currency) {
                throw new CurrencyMismatch();
            }

            // Read, decide, then write — the exact shape that
            // docs/race-condition.md shows losing money. It is correct here
            // and only here, because the rows are locked for the duration.
            // The lock is doing the work the conditional UPDATE does in
            // BetService; neither technique is safe without one of them.
            if (bccomp($from->balance, $amount, 4) < 0) {
                throw new InsufficientFunds();
            }

            $fromAfter = bcsub($from->balance, $amount, 4);
            $toAfter = bcadd($to->balance, $amount, 4);

            $from->update(['balance' => $fromAfter]);
            $to->update(['balance' => $toAfter]);

            $now = now();

            // Two rows, one insert. Both legs share a transfer id so the pair
            // can be found together, and the derived keys make a retried
            // transfer collide on the unique index rather than duplicate.
            Transaction::insert([
                [
                    'wallet_id' => $from->id,
                    'type' => TransactionType::TransferOut->value,
                    'amount' => bcsub('0', $amount, 4),
                    'balance_after' => $fromAfter,
                    'idempotency_key' => $transferId . ':out',
                    'created_at' => $now,
                ],
                [
                    'wallet_id' => $to->id,
                    'type' => TransactionType::TransferIn->value,
                    'amount' => $amount,
                    'balance_after' => $toAfter,
                    'idempotency_key' => $transferId . ':in',
                    'created_at' => $now,
                ],
            ]);

            // attempts: 3 below retries only genuine concurrency errors —
            // Laravel checks the driver error code and rethrows anything else
            // immediately. InsufficientFunds and CurrencyMismatch above are
            // not retried, which is correct: retrying them would just fail
            // again three times as slowly.
        }, attempts: 3);

        return $transferId;
    }
}