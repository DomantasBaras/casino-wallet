<?php

namespace App\Services;

/**
 * The outcome of one rate-limit check. `retryAfterSeconds` is the key's
 * remaining TTL — how long until the window resets — regardless of whether
 * this attempt was allowed.
 */
class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $retryAfterSeconds,
    ) {}
}
