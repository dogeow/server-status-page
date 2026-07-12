<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureResultPartitions extends Command
{
    protected $signature = 'status:ensure-partitions {--months=3 : Number of future months to create}';

    protected $description = 'Pre-create monthly PostgreSQL check result partitions';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->info('Monthly partitions are only needed on PostgreSQL.');

            return self::SUCCESS;
        }

        $months = min(24, max(1, (int) $this->option('months')));
        $start = CarbonImmutable::now('UTC')->startOfMonth()->addMonth();
        for ($offset = 0; $offset < $months; $offset++) {
            $from = $start->addMonths($offset);
            $to = $from->addMonth();
            $name = 'check_results_'.$from->format('Ym');
            $exists = DB::table('pg_class')->where('relname', $name)->exists();
            if (! $exists) {
                DB::statement("CREATE TABLE {$name} PARTITION OF check_results FOR VALUES FROM ('{$from->toDateString()}') TO ('{$to->toDateString()}')");
                $this->info('Created '.$name);
            }
        }

        return self::SUCCESS;
    }
}
