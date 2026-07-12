<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use ReflectionProperty;
use Tests\TestCase;

class SchedulerResilienceTest extends TestCase
{
    public function test_scheduler_mutexes_are_pinned_to_the_database_cache_store(): void
    {
        $schedule = app(Schedule::class);
        $property = new ReflectionProperty($schedule, 'eventMutex');
        $mutex = $property->getValue($schedule);

        $this->assertSame('database', $mutex->store);
    }
}
