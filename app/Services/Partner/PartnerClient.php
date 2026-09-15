<?php

namespace App\Services\Partner;

use App\Services\Partner\Exceptions\PartnerUnavailable;
use App\Services\Partner\Exceptions\PartnerRejected;
/**
 * The boundary this whole step exists to cross.
 *
 * An interface rather than a concrete client so the drainer can be tested
 * against a partner that fails on demand — the interesting cases are all
 * failure cases, and you cannot get a real HTTP endpoint to time out on cue.
 */
interface PartnerClient
{
    /**
     * Deliver one event.
     *
     * Returns normally on success. Throws PartnerUnavailable for anything
     * retryable — timeouts, 5xx, connection refused. A 4xx means the partner
     * understood and rejected it, which retrying will not fix.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws PartnerUnavailable
     * @throws PartnerRejected
     */
    public function send(string $event, array $payload, string $idempotencyKey): void;
}
