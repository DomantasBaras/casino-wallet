<?php

namespace App\Console\Commands;

use App\Services\OutboxDrainer;
use Illuminate\Console\Command;

class DrainOutbox extends Command
{
    protected $signature = 'outbox:drain {--batch=10} {--loop}';

    protected $description = 'Deliver pending outbox messages to the partner';

    public function handle(OutboxDrainer $drainer): int
    {
        do {
            $result = $drainer->drain((int) $this->option('batch'));

            if ($result['claimed'] > 0) {
                $this->line(json_encode($result));
            }

            if ($this->option('loop')) {
                sleep(1);
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }
}