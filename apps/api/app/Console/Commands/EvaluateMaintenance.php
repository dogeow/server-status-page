<?php

namespace App\Console\Commands;

use App\Services\StateEvaluator;
use Illuminate\Console\Command;

class EvaluateMaintenance extends Command
{
    protected $signature = 'status:evaluate-maintenance';

    protected $description = 'Apply maintenance windows that started or ended without a new sample';

    public function handle(StateEvaluator $evaluator): int
    {
        $this->info($evaluator->reconcileMaintenanceBoundaries().' components reconciled at a maintenance boundary.');

        return self::SUCCESS;
    }
}
