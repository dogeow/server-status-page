<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\EnrollmentTokenController;
use App\Http\Controllers\Admin\LaravelIntegrationController;
use App\Http\Controllers\Admin\MonitorSecretController;
use App\Http\Controllers\Admin\NotificationChannelSecretController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Agent\AgentController;
use App\Http\Controllers\LaravelProbeEventController;
use App\Http\Controllers\ProbeHeartbeatController;
use App\Http\Controllers\PublicApi\StatusController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/readiness', ReadinessController::class)->name('readiness');
Route::post('/probe/v1/heartbeat/{monitor}', ProbeHeartbeatController::class)->middleware('throttle:120,1');
Route::post('/probe/v1/integrations/{integration}/events', LaravelProbeEventController::class)->middleware('throttle:240,1');

Route::prefix('public/v1')->group(function (): void {
    Route::get('/status', [StatusController::class, 'status']);
    Route::get('/history', [StatusController::class, 'history']);
    Route::get('/incidents/{incident}', [StatusController::class, 'incident'])->whereNumber('incident');
});

Route::prefix('agent/v1')->group(function (): void {
    Route::post('/enroll', [AgentController::class, 'enroll'])->middleware('throttle:10,1');
    Route::middleware('agent.signed')->group(function (): void {
        Route::get('/plan', [AgentController::class, 'plan']);
        Route::post('/heartbeat', [AgentController::class, 'heartbeat']);
        Route::post('/results/batch', [AgentController::class, 'results']);
    });
});

Route::prefix('admin/v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/overview', OverviewController::class);
    Route::get('/laravel-integrations', [LaravelIntegrationController::class, 'index']);
    Route::get('/laravel-integrations/{integration}', [LaravelIntegrationController::class, 'show']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/agent-enrollment-tokens', [EnrollmentTokenController::class, 'store'])->middleware('role:owner,admin');
    Route::post('/monitors/{monitor}/rotate-heartbeat-secret', [MonitorSecretController::class, 'rotate'])->middleware('role:owner,admin');
    Route::post('/notification-channels/{channel}/rotate-secret', NotificationChannelSecretController::class)->middleware('role:owner,admin');

    Route::get('/{resource}', [ResourceController::class, 'index'])->whereIn('resource', ['status-pages', 'component-groups', 'components', 'monitors', 'agents', 'incidents', 'incident-updates', 'maintenance-windows', 'notification-channels', 'notification-policies', 'users', 'audit-logs']);
    Route::get('/{resource}/{id}', [ResourceController::class, 'show'])->whereIn('resource', ['status-pages', 'component-groups', 'components', 'monitors', 'agents', 'incidents', 'incident-updates', 'maintenance-windows', 'notification-channels', 'notification-policies', 'users', 'audit-logs']);

    Route::middleware('role:owner,admin')->group(function (): void {
        Route::post('/laravel-integrations', [LaravelIntegrationController::class, 'store']);
        Route::patch('/laravel-integrations/{integration}', [LaravelIntegrationController::class, 'update']);
        Route::post('/laravel-integrations/{integration}/rotate-secret', [LaravelIntegrationController::class, 'rotate']);
        Route::delete('/laravel-integrations/{integration}', [LaravelIntegrationController::class, 'destroy']);
        Route::post('/{resource}', [ResourceController::class, 'store'])->whereIn('resource', ['status-pages', 'component-groups', 'components', 'monitors', 'incidents', 'incident-updates', 'maintenance-windows', 'notification-channels', 'notification-policies', 'users']);
        Route::put('/{resource}/{id}', [ResourceController::class, 'update'])->whereIn('resource', ['status-pages', 'component-groups', 'components', 'monitors', 'agents', 'incidents', 'incident-updates', 'maintenance-windows', 'notification-channels', 'notification-policies', 'users']);
        Route::patch('/{resource}/{id}', [ResourceController::class, 'update'])->whereIn('resource', ['status-pages', 'component-groups', 'components', 'monitors', 'agents', 'incidents', 'incident-updates', 'maintenance-windows', 'notification-channels', 'notification-policies', 'users']);
        Route::delete('/{resource}/{id}', [ResourceController::class, 'destroy'])->whereIn('resource', ['status-pages', 'component-groups', 'components', 'monitors', 'agents', 'incidents', 'incident-updates', 'maintenance-windows', 'notification-channels', 'notification-policies', 'users']);
    });
});
