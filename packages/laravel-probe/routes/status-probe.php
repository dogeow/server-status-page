<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use StatusPage\LaravelProbe\Http\Controllers\ReadinessController;
use StatusPage\LaravelProbe\Http\Controllers\ReverbProbeController;
use StatusPage\LaravelProbe\Http\Middleware\VerifyProbeSignature;

$prefix = trim((string) config('status-probe.routes.prefix', 'health'), '/');
$middleware = (array) config('status-probe.routes.middleware', []);

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function (): void {
        Route::get('/ready', ReadinessController::class)
            ->name('status-probe.ready');

        if ((bool) config('status-probe.reverb.enabled', true)) {
            Route::post('/reverb/probe', ReverbProbeController::class)
                ->middleware([
                    'throttle:status-probe-reverb',
                    VerifyProbeSignature::class,
                ])
                ->name('status-probe.reverb.probe');
        }
    });
