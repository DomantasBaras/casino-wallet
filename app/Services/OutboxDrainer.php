<?php

namespace App\Services;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Services\Partner\PartnerClient;
use App\Services\Partner\PartnerRejected;
use App\Services\Partner\PartnerUnavailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxDrainer
{
    /**
     * After this many attempts a message is parked as failed rather than
     * retried forever. Something is wrong that retrying will not fix, and a
     * human should look at it.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * A worker that dies mid-delivery leaves its claim behind. After this
     * long the claim is assumed dead and the message becomes eligible again.
     *
     * This is why delivery is at-least-once rather than exactly-once: the
     * original worker may have completed the HTTP call before dying, so the
     * partner can see the same event twice.
     */
    private const CLAIM_TIMEOUT_MINUTES = 5;

    public function __construct(
        private readonly PartnerClient $partner,
    ) {}

    /**
     * @return array{claimed: int, delivered: int, retrying: int, failed: int}
     */
    public function drain(int $batchSize = 10, ?string $workerId = null): array
    {
        $workerId ??= gethostname() . ':' . getmypid();

        $this->releaseStaleClaims();

        $messages = $this->claim($batchSize, $workerId);

        $result = ['claimed' => $messages->count(), 'delivered' => 0, 'retrying' => 0, 'failed' => 0];

        // Delivery happens here, outside any transaction. Holding row locks
        // across a network call to a partner that might be timing out would
        // block every other worker for the duration.
        foreach ($messages as $message) {
            try {
                $this->partner->send(
                    $message->event,
                    $message->payload,
                    // The partner dedupes on this. Because delivery is
                    // at-least-once, it has to.
                    $this->deliveryKey($message),
                );

                $this->markDelivered($message);
                $result['delivered']++;
            } catch (PartnerRejected $e) {
                // The partner understood and refused. Retrying changes
                // nothing, so park it immediately.
                $this->markFailed($message, $e->getMessage());
                $result['failed']++;
            } catch (PartnerUnavailable $e) {
                if ($message->attempts >= self::MAX_ATTEMPTS) {
                    $this->markFailed($message, $e->getMessage());
                    $result['failed']++;
                } else {
                    $this->scheduleRetry($message, $e->getMessage());
                    $result['retrying']++;
                }
            }
        }

        return $result;
    }

    /**
     * Claim a batch with SKIP LOCKED.
     *
     * A second worker running this concurrently skips any row we already hold
     * rather than queueing behind it, so the two get disjoint batches in one
     * round trip each.
     *
     * The conditional UPDATE from ADR 0002 would also be correct here — the
     * condition "still pending" fits in a WHERE clause. It would just waste
     * work: both workers would target the same ids and one would lose every
     * race. SKIP LOCKED is built for this shape specifically.
     *
     * @return Collection<int, OutboxMessage>
     */
    private function claim(int $limit, string $workerId): Collection
    {
        return DB::transaction(function () use ($limit, $workerId) {
            $messages = OutboxMessage::query()
                ->where('status', OutboxStatus::Pending)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            if ($messages->isEmpty()) {
                return $messages;
            }

            OutboxMessage::query()
                ->whereIn('id', $messages->pluck('id'))
                ->update([
                    'status' => OutboxStatus::Claimed,
                    'claimed_by' => $workerId,
                    'claimed_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            // Re-read rather than patching the in-memory models. They predate the
            // bulk update, and a stale model that later calls update() writes from
            // the wrong baseline.
            return OutboxMessage::query()
                ->whereIn('id', $messages->pluck('id'))
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * A claim older than the timeout belonged to a worker that is not coming
     * back. Return it to the pool.
     */
    private function releaseStaleClaims(): void
    {
        $released = OutboxMessage::query()
            ->where('status', OutboxStatus::Claimed)
            ->where('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))
            ->update([
                'status' => OutboxStatus::Pending,
                'claimed_by' => null,
                'claimed_at' => null,
            ]);

        if ($released > 0) {
            Log::warning('Released stale outbox claims', ['count' => $released]);
        }
    }

    private function markDelivered(OutboxMessage $message): void
    {
        $message->update([
            'status' => OutboxStatus::Delivered,
            'delivered_at' => now(),
            'claimed_by' => null,
            'claimed_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Exponential backoff, expressed by pushing availability into the future
     * rather than by sleeping a worker. A sleeping worker is a worker not
     * draining anything else.
     */
    private function scheduleRetry(OutboxMessage $message, string $error): void
    {
        $delaySeconds = min(2 ** $message->attempts, 300);

        $message->update([
            'status' => OutboxStatus::Pending,
            'available_at' => now()->addSeconds($delaySeconds),
            'claimed_by' => null,
            'claimed_at' => null,
            'last_error' => $error,
        ]);
    }

    private function markFailed(OutboxMessage $message, string $error): void
    {
        $message->update([
            'status' => OutboxStatus::Failed,
            'claimed_by' => null,
            'claimed_at' => null,
            'last_error' => $error,
        ]);

        Log::error('Outbox message parked as failed', [
            'outbox_message_id' => $message->id,
            'attempts' => $message->attempts,
            'error' => $error,
        ]);
    }

    /**
     * Stable across retries of the same message, so the partner can tell a
     * retry from a genuinely new event.
     */
    private function deliveryKey(OutboxMessage $message): string
    {
        return 'outbox-' . $message->id;
    }
}
