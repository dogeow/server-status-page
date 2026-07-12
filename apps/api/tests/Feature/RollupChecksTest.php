<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Monitor;
use App\Models\StatusInterval;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RollupChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_uptime_is_time_weighted_and_monitor_frequency_does_not_rewrite_history(): void
    {
        $day = CarbonImmutable::parse('2026-07-10 00:00:00 UTC');
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main', 'timezone' => 'UTC']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'API', 'slug' => 'api']);
        $agent = Agent::query()->create(['id' => (string) Str::uuid(), 'name' => 'edge']);
        $monitor = Monitor::query()->create(['component_id' => $component->id, 'agent_id' => $agent->id, 'name' => 'HTTP', 'type' => 'http', 'interval_seconds' => 15]);
        StatusInterval::query()->create(['component_id' => $component->id, 'status' => 'operational', 'started_at' => $day, 'ended_at' => $day->addHours(12)]);
        StatusInterval::query()->create(['component_id' => $component->id, 'status' => 'major_outage', 'started_at' => $day->addHours(12), 'ended_at' => $day->addDay()]);
        foreach ([['ok', 1], ['failed', 13], ['failed', 14], ['failed', 15]] as [$status, $hour]) {
            CheckResult::query()->create(['monitor_id' => $monitor->id, 'agent_id' => $agent->id, 'scheduled_at' => $day->addHours($hour), 'config_version' => 1, 'status' => $status, 'received_at' => $day->addHours($hour)]);
        }

        $this->artisan('status:rollup', ['--date' => '2026-07-10'])->assertSuccessful();
        $this->assertDatabaseHas('daily_rollups', [
            'component_id' => $component->id,
            'uptime_percentage' => 50,
            'checks_total' => 4,
            'checks_failed' => 3,
            'observed_seconds' => 86400,
            'available_seconds' => 43200,
        ]);

        $monitor->update(['interval_seconds' => 300]);
        $this->artisan('status:rollup', ['--date' => '2026-07-10'])->assertSuccessful();
        $this->assertSame('50.0000', $component->rollups()->whereDate('date', '2026-07-10')->sole()->uptime_percentage);
    }

    public function test_unknown_time_is_not_misclassified_as_downtime(): void
    {
        $day = CarbonImmutable::parse('2026-07-10 00:00:00 UTC');
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main', 'timezone' => 'UTC']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'API', 'slug' => 'api']);
        StatusInterval::query()->create(['component_id' => $component->id, 'status' => 'unknown', 'started_at' => $day, 'ended_at' => $day->addHours(12)]);
        StatusInterval::query()->create(['component_id' => $component->id, 'status' => 'operational', 'started_at' => $day->addHours(12), 'ended_at' => $day->addDay()]);

        $this->artisan('status:rollup', ['--date' => '2026-07-10'])->assertSuccessful();
        $this->assertDatabaseHas('daily_rollups', [
            'component_id' => $component->id,
            'uptime_percentage' => 100,
            'observed_seconds' => 43200,
            'available_seconds' => 43200,
            'worst_status' => 'unknown',
        ]);
    }

    public function test_today_rollup_uses_page_timezone_and_stops_at_now(): void
    {
        $now = CarbonImmutable::parse('2026-07-12 04:00:00 UTC');
        $localDayStart = CarbonImmutable::parse('2026-07-12 00:00:00 Asia/Shanghai')->utc();
        $this->travelTo($now);

        $page = StatusPage::query()->create([
            'name' => 'Status',
            'slug' => 'main',
            'timezone' => 'Asia/Shanghai',
        ]);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'API', 'slug' => 'api']);
        $agent = Agent::query()->create(['id' => (string) Str::uuid(), 'name' => 'edge']);
        $monitor = Monitor::query()->create(['component_id' => $component->id, 'agent_id' => $agent->id, 'name' => 'HTTP', 'type' => 'http', 'interval_seconds' => 60]);
        StatusInterval::query()->create([
            'component_id' => $component->id,
            'status' => 'operational',
            'started_at' => $localDayStart,
            'ended_at' => null,
        ]);
        CheckResult::query()->create([
            'monitor_id' => $monitor->id,
            'agent_id' => $agent->id,
            'scheduled_at' => $now->subHour(),
            'config_version' => 1,
            'status' => 'ok',
            'received_at' => $now->subHour(),
        ]);

        $this->artisan('status:rollup', ['--date' => 'today'])->assertSuccessful();

        $this->assertDatabaseHas('daily_rollups', [
            'component_id' => $component->id,
            'date' => '2026-07-12',
            'checks_total' => 1,
            'observed_seconds' => 43200,
            'available_seconds' => 43200,
            'worst_status' => 'operational',
        ]);
        $this->assertDatabaseMissing('daily_rollups', [
            'component_id' => $component->id,
            'date' => '2026-07-11',
        ]);

        $this->travelBack();
    }
}
