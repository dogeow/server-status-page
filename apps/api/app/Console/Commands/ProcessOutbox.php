<?php

namespace App\Console\Commands;

use App\Services\OutboxProcessor;
use Illuminate\Console\Command;

class ProcessOutbox extends Command
{
    protected $signature = 'status:outbox-work {--once : Process one batch and exit} {--limit=50}';

    protected $description = 'Deliver pending email and signed webhook events from the PostgreSQL outbox';

    public function handle(OutboxProcessor $processor): int
    {
        do {
            $count = $processor->process((int) $this->option('limit'));
            if (! $this->option('once') && $count === 0) {
                sleep(2);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
