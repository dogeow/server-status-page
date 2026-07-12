<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use StatusPage\LaravelProbe\Contracts\PushClient;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Support\SafeLogger;

final class StatusProbeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/status-probe.php', 'status-probe');

        $this->app->singleton(SafeLogger::class);
        $this->app->singleton(HmacSigner::class, static fn (): HmacSigner => new HmacSigner(
            is_string(config('status-probe.secrets.current')) ? config('status-probe.secrets.current') : null,
            is_string(config('status-probe.secrets.next')) ? config('status-probe.secrets.next') : null,
        ));
        $this->app->singleton(PushClient::class, HttpPushClient::class);
        $this->app->singleton(StatusProbeManager::class);
        $this->app->alias(StatusProbeManager::class, 'status-probe');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/status-probe.php' => config_path('status-probe.php'),
        ], 'status-probe-config');

        $this->configureRateLimiter();

        if ((bool) config('status-probe.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/status-probe.php');
        }

        $this->registerSchedule();
    }

    private function configureRateLimiter(): void
    {
        RateLimiter::for('status-probe-reverb', static function (Request $request): Limit {
            $perMinute = max(1, (int) config('status-probe.reverb.rate_limit_per_minute', 10));

            return Limit::perMinute($perMinute)->by($request->ip() ?: 'unknown');
        });
    }

    private function registerSchedule(): void
    {
        if (! (bool) config('status-probe.scheduler.enabled', true) || ! $this->supportsLaravelVersion()) {
            return;
        }

        // callAfterResolving is supported throughout Laravel 10-13 and avoids
        // forcing the console scheduler into ordinary web requests.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $heartbeat = $schedule
                ->call(static fn (): bool => app(StatusProbeManager::class)->schedulerTick())
                ->name('status-probe:scheduler-heartbeat')
                ->everyMinute();
            $this->maybeOneServer($heartbeat);

            if (
                (bool) config('status-probe.scheduler.queue_probes_enabled', true)
                && (array) config('status-probe.queues', []) !== []
            ) {
                $queues = $schedule
                    ->call(static fn (): array => app(StatusProbeManager::class)->dispatchQueueProbes())
                    ->name('status-probe:queue-canaries')
                    ->everyMinute();
                $this->maybeOneServer($queues);
            }
        });
    }

    private function maybeOneServer(CallbackEvent $event): void
    {
        if ((bool) config('status-probe.scheduler.on_one_server', false)) {
            $event->onOneServer();
        }
    }

    private function supportsLaravelVersion(): bool
    {
        $major = (int) explode('.', $this->app->version())[0];

        return $major >= 10 && $major <= 13;
    }
}
