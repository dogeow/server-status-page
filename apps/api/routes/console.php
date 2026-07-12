<?php

use App\Models\CheckResult;
use App\Models\DailyRollup;
use App\Models\UsedNonce;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::useCache(config('status.scheduler_cache_store', 'database'));
Schedule::command('status:evaluate-agents')->everyMinute()->withoutOverlapping();
Schedule::command('status:evaluate-push-monitors')->everyMinute()->withoutOverlapping();
Schedule::command('status:rollup --date=today')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('status:rollup')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('status:ensure-partitions')->monthlyOn(1, '00:05')->withoutOverlapping();
Schedule::command('status:schedule-reminders')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => CheckResult::query()->where('scheduled_at', '<', now()->subDays(config('status.raw_result_retention_days', 30)))->delete())->dailyAt('02:00');
Schedule::call(fn () => DailyRollup::query()->where('date', '<', now()->subMonths(config('status.rollup_retention_months', 13))->toDateString())->delete())->dailyAt('02:15');
Schedule::call(fn () => UsedNonce::query()->where('expires_at', '<', now())->delete())->hourly();
