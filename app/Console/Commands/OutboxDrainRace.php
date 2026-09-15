<?php

namespace App\Console\Commands;

use App\Services\OutboxDrainer;
use App\Services\Partner\InstantSuccessPartnerClient;
use Illuminate\Console\Command;

/**
 * Drains the outbox against a fake, always-succeeding partner client and
 * logs every delivery to a shared file.
 *
 * Dev/test tooling only — never run this against a real partner or in
 * production. It exists to answer one question for scripts/outbox-race.sh:
 * can two concurrent workers ever deliver the same outbox message twice?
 */
class OutboxDrainRace extends Command
{
    protected $signature = 'outbox:drain-race
        {--batch=10 : Max messages to claim in this call}
        {--worker= : Worker identifier; defaults to hostname:pid}
        {--log= : Path to the shared delivery log}';

    protected $description = 'Drain the outbox with a fake instant-success partner client, for concurrency testing only';

    public function handle(): int
    {
        $logPath = $this->option('log') ?? storage_path('logs/outbox-race.log');

        $drainer = new OutboxDrainer(new InstantSuccessPartnerClient($logPath));

        $result = $drainer->drain(
            batchSize: (int) $this->option('batch'),
            workerId: $this->option('worker'),
        );

        $this->info(json_encode($result));

        return self::SUCCESS;
    }
}