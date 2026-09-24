<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Fixed-window rate limiting via a single atomic Redis operation.
 *
 * The obvious implementation — INCR, then EXPIRE only if this was the first
 * hit — is two round trips. If the process dies between them, the key is
 * left with no TTL and never expires, locking that key out forever. A Lua
 * script runs as one atomic unit on the Redis server, so there is no gap
 * between the increment and the expiry for a crash to land in. See ADR 0005.
 */
class RateLimiter
{
    private const SCRIPT = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        if tonumber(current) == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        local ttl = redis.call('TTL', KEYS[1])
        return {current, ttl}
        LUA;

    public function hit(string $key, int $maxAttempts, int $windowSeconds): RateLimitResult
    {
        [$current, $ttl] = Redis::eval(self::SCRIPT, 1, $key, $windowSeconds);

        return new RateLimitResult(
            allowed: $current <= $maxAttempts,
            retryAfterSeconds: max((int) $ttl, 1),
        );
    }
}
