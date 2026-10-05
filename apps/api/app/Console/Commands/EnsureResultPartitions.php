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
        $start = CarbonImmutable::now('UTC')->startOfMonth();
        $targets = [];
        for ($offset = 0; $offset < $months + 1; $offset++) {
            $targets[] = $start->addMonths($offset);
        }
        if (DB::table('pg_class')->where('relname', 'check_results_default')->exists()) {
            $occupied = DB::table('check_results_default')
                ->selectRaw("date_trunc('month', scheduled_at) as month")
                ->groupByRaw("date_trunc('month', scheduled_at)")
                ->pluck('month');
            foreach ($occupied as $month) {
                $targets[] = CarbonImmutable::parse($month, 'UTC')->startOfMonth();
            }
        }

        $seen = [];
        foreach ($targets as $from) {
            $key = $from->format('Ym');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->ensureMonth($from);
        }

        return self::SUCCESS;
    }

    private function ensureMonth(CarbonImmutable $from): void
    {
        $to = $from->addMonth();
        $name = 'check_results_'.$from->format('Ym');
        if (! preg_match('/^check_results_[0-9]{6}$/', $name)) {
            return;
        }
        if (DB::table('pg_class')->where('relname', $name)->exists()) {
            return;
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        DB::transaction(function () use ($name, $fromDate, $toDate): void {
            if (DB::table('pg_class')->where('relname', 'check_results_default')->exists()) {
                DB::statement('CREATE TEMP TABLE check_results_partition_move (LIKE check_results_default INCLUDING DEFAULTS) ON COMMIT DROP');
                DB::statement(
                    'INSERT INTO check_results_partition_move SELECT * FROM check_results_default WHERE scheduled_at >= ? AND scheduled_at < ?',
                    [$fromDate, $toDate],
                );
                DB::statement(
                    'DELETE FROM check_results_default WHERE scheduled_at >= ? AND scheduled_at < ?',
                    [$fromDate, $toDate],
                );
            }
            DB::statement("CREATE TABLE {$name} PARTITION OF check_results FOR VALUES FROM ('{$fromDate}') TO ('{$toDate}')");
            if (DB::table('pg_class')->where('relname', 'check_results_partition_move')->exists()) {
                DB::statement('INSERT INTO check_results SELECT * FROM check_results_partition_move');
            }
            $this->info('Created '.$name);
        });
    }
}
