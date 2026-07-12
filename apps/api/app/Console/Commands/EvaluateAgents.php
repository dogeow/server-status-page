<?php

namespace App\Console\Commands;

use App\Services\AgentStatusService;
use Illuminate\Console\Command;

class EvaluateAgents extends Command
{
    protected $signature = 'status:evaluate-agents';

    protected $description = 'Mark stale agents and their exclusively observed components unknown';

    public function handle(AgentStatusService $service): int
    {
        $this->info($service->markStaleAgents().' stale agents marked offline.');

        return self::SUCCESS;
    }
}
