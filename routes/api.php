<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BetController;
use App\Http\Controllers\Api\TransferController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\OutboxHealthController;
use App\Http\Middleware\RateLimitBets;

/*
 | Laravel already ships a /up endpoint, but it only proves PHP booted.
 | This one proves the three things session 1 is actually about: nginx reaches
 | php-fpm, php-fpm reaches MySQL, and php-fpm reaches Redis. If any of those
 | is wrong, this returns 503 and says which.
 */
Route::post('/v1/bets', [BetController::class, 'store'])
    ->middleware(RateLimitBets::class);
Route::post('/v1/transfers', [TransferController::class, 'store']);
Route::get('/health', function () {
    $checks = [];

    try {
        DB::select('SELECT 1');
        $checks['mysql'] = 'ok';
    } catch (\Throwable $e) {
        $checks['mysql'] = 'fail: ' . $e->getMessage();
    }

    try {
        Redis::ping();
        $checks['redis'] = 'ok';
    } catch (\Throwable $e) {
        $checks['redis'] = 'fail: ' . $e->getMessage();
    }

    $healthy = ! in_array(false, array_map(
        fn (string $r) => $r === 'ok',
        $checks
    ), true);

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'checks' => $checks,
    ], $healthy ? 200 : 503);
});
if (app()->environment('local')) {
    Route::post('/stub/partner/events', function (Request $request) {
        $mode = env('PARTNER_STUB_MODE', 'ok');

        Log::info('Partner stub received', [
            'key' => $request->header('Idempotency-Key'),
            'event' => $request->input('event'),
            'mode' => $mode,
        ]);

        return match ($mode) {
            'down' => response()->json(['error' => 'unavailable'], 503),
            'reject' => response()->json(['error' => 'unknown player'], 422),
            'slow' => tap(response()->json(['ok' => true]), fn () => sleep(10)),
            default => response()->json(['ok' => true]),
        };
    });
}
Route::get('/v1/outbox/health', [OutboxHealthController::class, 'show']);
