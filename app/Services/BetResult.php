<?php

namespace App\Services;

use App\Models\Transaction;

/**
 * Distinguishes a debit that was applied from one that was already applied
 * under the same key, so the controller can answer 201 or 200 accordingly.
 */
final readonly class BetResult
{
    public function __construct(
        public Transaction $transaction,
        public bool $replayed,
    ) {}
}