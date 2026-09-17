<?php

namespace App\Services;

/**
 * The outcome of a transfer. `replayed` distinguishes a fresh transfer from
 * a duplicate that returned the original id without moving money again, so
 * the controller can answer 201 or 200 the way the bet path does.
 */
class TransferResult
{
    public function __construct(
        public readonly string $transferId,
        public readonly bool $replayed,
    ) {}
}