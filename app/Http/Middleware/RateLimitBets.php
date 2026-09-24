<?php

namespace App\Http\Middleware;

use App\Services\RateLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps bets per source IP per window. Keyed on IP rather than the request's
 * player_id field: a client could set that field to anything, but not the
 * address the request actually came from.
 */
class RateLimitBets
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->limiter->hit(
            key: 'rate_limit:bets:' . $request->ip(),
            maxAttempts: (int) config('rate_limit.bets.max_attempts'),
            windowSeconds: (int) config('rate_limit.bets.window_seconds'),
        );

        if (! $result->allowed) {
            return response()->json(['error' => 'rate_limited'], 429)
                ->header('Retry-After', (string) $result->retryAfterSeconds);
        }

        return $next($request);
    }
}
