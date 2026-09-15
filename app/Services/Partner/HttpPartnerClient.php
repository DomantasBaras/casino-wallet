<?php

namespace App\Services\Partner;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpPartnerClient implements PartnerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function send(string $event, array $payload, string $idempotencyKey): void
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                // Deliberately no ->retry(). The drainer owns retry and
                // backoff. Retrying here too would multiply the two schedules
                // together and make the backoff meaningless.
                ->withHeaders([
                    'Idempotency-Key' => $idempotencyKey,
                    'X-Event-Type' => $event,
                ])
                ->post($this->baseUrl . '/events', [
                    'event' => $event,
                    'data' => $payload,
                ]);
        } catch (ConnectionException $e) {
            // Refused, DNS failure, or timed out. The partner may be fine in
            // a minute; nothing has been said about whether it processed the
            // event, so this has to be retried.
            throw new PartnerUnavailable($e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return;
        }

        // 408 and 429 are 4xx but explicitly mean "try again" — a timeout and
        // a rate limit. Treating them as permanent rejections would park
        // messages the partner expects to receive.
        if ($response->status() === 408 || $response->status() === 429) {
            throw new PartnerUnavailable(
                "Partner asked us to back off: HTTP {$response->status()}"
            );
        }

        if ($response->clientError()) {
            // The partner understood and refused. The same payload will be
            // refused again, so retrying only delays a human looking at it.
            throw new PartnerRejected(
                "Partner rejected the event: HTTP {$response->status()} {$response->body()}"
            );
        }

        throw new PartnerUnavailable(
            "Partner failed: HTTP {$response->status()}"
        );
    }
}