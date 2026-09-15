<?php

namespace App\Services\Partner;

/**
 * Always succeeds immediately. Exists only for scripts/outbox-race.sh, which
 * proves that concurrent OutboxDrainer workers never deliver the same
 * message twice.
 *
 * Every call is appended to a shared log file under an exclusive lock, so
 * the log — not the outbox table — is the record of how many times send()
 * was actually invoked for a given idempotency key. The table only ever
 * shows final state; a message delivered twice by two racing workers still
 * ends up with exactly one row in exactly one final status, so the table
 * alone cannot catch the bug this class exists to catch.
 */
class InstantSuccessPartnerClient implements PartnerClient
{
    public function __construct(
        private readonly string $logPath,
    ) {}

    public function send(string $event, array $payload, string $idempotencyKey): void
    {
        $handle = fopen($this->logPath, 'a');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open race log at {$this->logPath}");
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $idempotencyKey . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}