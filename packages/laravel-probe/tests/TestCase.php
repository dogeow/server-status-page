<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use StatusPage\LaravelProbe\Contracts\PushClient;
use StatusPage\LaravelProbe\StatusProbeManager;
use StatusPage\LaravelProbe\StatusProbeServiceProvider;
use StatusPage\LaravelProbe\Tests\Fakes\RecordingPushClient;

abstract class TestCase extends Orchestra
{
    protected RecordingPushClient $pushClient;

    protected function getPackageProviders($app): array
    {
        return [StatusProbeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('status-probe.enabled', true);
        $app['config']->set('status-probe.routes.enabled', true);
        $app['config']->set('status-probe.routes.prefix', 'health');
        $app['config']->set('status-probe.routes.middleware', []);
        $app['config']->set('status-probe.secrets.current', 'current-test-secret');
        $app['config']->set('status-probe.secrets.next', 'next-test-secret');
        $app['config']->set('status-probe.security.nonce_cache_store', 'array');
        $app['config']->set('status-probe.security.timestamp_tolerance_seconds', 300);
        $app['config']->set('status-probe.reverb.enabled', true);
        $app['config']->set('status-probe.reverb.rate_limit_per_minute', 100);
        $app['config']->set('status-probe.scheduler.enabled', true);
        $app['config']->set('status-probe.scheduler.queue_probes_enabled', true);
        $app['config']->set('status-probe.queues', [
            'default' => ['connection' => 'sync', 'queue' => 'default'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->pushClient = new RecordingPushClient;
        $this->app->instance(PushClient::class, $this->pushClient);
        $this->app->forgetInstance(StatusProbeManager::class);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function serverHeaders(array $headers): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
