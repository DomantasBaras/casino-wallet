<?php

namespace App\Http\Controllers\Api;

use App\Enums\OutboxStatus;
use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the outbox's health as numbers a monitoring layer can scrape.
 *
 * Alerting itself is deliberately not here. A service's job is to expose the
 * signal; deciding a threshold has been crossed and paging someone is the
 * monitoring layer's job (Prometheus + Alertmanager, a k8s probe, whatever).
 * Reinventing that in PHP would be the same mistake as reinventing the queue.
 *
 * Three signals, because "failed count" alone is not enough:
 *   - failed        dead-letters accumulating means the partner contract is
 *                   broken or there is a bug — retrying will not fix it.
 *   - oldest_pending_age_seconds
 *                   the age of the oldest deliverable message. Rising means
 *                   nothing is draining — the worker is down — even while the
 *                   failed count sits at zero.
 *   - stale_claimed the number of rows claimed longer ago than the stale
 *                   timeout: workers dying mid-delivery. A few is normal
 *                   churn; a growing number is not.
 */
class OutboxHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $now = now();

        $byStatus = OutboxMessage::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $failed = (int) ($byStatus[OutboxStatus::Failed->value] ?? 0);
        $pending = (int) ($byStatus[OutboxStatus::Pending->value] ?? 0);
        $claimed = (int) ($byStatus[OutboxStatus::Claimed->value] ?? 0);
        $delivered = (int) ($byStatus[OutboxStatus::Delivered->value] ?? 0);

        // Oldest message that is actually deliverable now — pending and past
        // its backoff. A message in backoff is not late, it is waiting, so
        // available_at, not created_at, is the honest clock.
        $oldestAvailableAt = OutboxMessage::query()
            ->where('status', OutboxStatus::Pending)
            ->where('available_at', '<=', $now)
            ->min('available_at');

        // diffInSeconds is signed in Carbon 3; the oldest available_at is in
        // the past, so pass absolute to get a positive age. Guard against a
        // clock-skew negative just in case.
        $oldestPendingAge = $oldestAvailableAt === null
            ? 0
            : (int) max(0, $now->diffInSeconds($oldestAvailableAt, absolute: true));

        $staleClaimed = OutboxMessage::query()
            ->where('status', OutboxStatus::Claimed)
            ->where('claimed_at', '<', $now->copy()->subMinutes(config('outbox.claim_timeout_minutes')))
            ->count();

        $healthy = $failed < config('outbox.health.failed_degraded_at')
            && $oldestPendingAge < config('outbox.health.oldest_pending_degraded_seconds')
            && $staleClaimed === 0;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'counts' => [
                'pending' => $pending,
                'claimed' => $claimed,
                'delivered' => $delivered,
                'failed' => $failed,
            ],
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'stale_claimed' => $staleClaimed,
        ], $healthy ? 200 : 503);
    }
}