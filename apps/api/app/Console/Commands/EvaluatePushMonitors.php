<?php

namespace App\Console\Commands;

use App\Services\PushMonitorHealthService;
use Illuminate\Console\Command;

class EvaluatePushMonitors extends Command
{
    protected $signature = 'status:evaluate-push-monitors';

    protected $description = 'Evaluate missing Laravel queue canaries and scheduler heartbeats';

    public function handle(PushMonitorHealthService $service): int
    {
        $this->info($service->evaluate().' push monitors changed state.');

        return self::SUCCESS;
    }
}
