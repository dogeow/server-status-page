<?php

namespace Database\Seeders;

use App\Enums\ComponentStatus;
use App\Models\Agent;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\DailyRollup;
use App\Models\Monitor;
use App\Models\StatusInterval;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $page = StatusPage::query()->updateOrCreate(
            ['slug' => 'main'],
            ['name' => '系统状态', 'description' => '服务可用性与事件历史', 'timezone' => 'Asia/Shanghai', 'locale' => 'zh_CN', 'is_public' => true],
        );
        $agent = Agent::query()->updateOrCreate(
            ['id' => '00000000-0000-4000-8000-000000000001'],
            ['name' => 'central-agent', 'status' => 'offline', 'secret' => bin2hex(random_bytes(32)), 'capabilities' => ['http', 'tcp', 'dns', 'tls'], 'plan_version' => 1],
        );

        $definitions = [
            '应用服务' => [
                ['API', 'api', 'laravel', ['url' => 'http://api/api/readiness?nonce={{nonce}}']],
                ['Web', 'web', 'nextjs', ['url' => 'http://web/api/readiness?nonce={{nonce}}']],
                ['实时推送', 'realtime', 'reverb', ['url' => 'ws://reverb:8080']],
            ],
            '数据服务' => [
                ['PostgreSQL', 'postgresql', 'postgresql', ['host' => 'postgres', 'port' => 5432, 'secretRef' => 'postgres/main']],
                ['Redis', 'redis', 'redis', ['host' => 'redis', 'port' => 6379, 'mode' => 'ping']],
            ],
            '后台任务' => [
                ['Laravel Queue', 'queue', 'laravel_queue', ['heartbeat' => 'queue/default']],
                ['Laravel Scheduler', 'scheduler', 'laravel_scheduler', ['heartbeat' => 'scheduler/tick']],
            ],
        ];

        $position = 0;
        foreach ($definitions as $groupName => $items) {
            $group = ComponentGroup::query()->updateOrCreate(
                ['status_page_id' => $page->id, 'slug' => str($groupName)->slug()->value() ?: 'group-'.$position],
                ['name' => $groupName, 'position' => $position++],
            );
            foreach ($items as $componentPosition => [$name, $slug, $type, $config]) {
                $component = Component::query()->updateOrCreate(
                    ['component_group_id' => $group->id, 'slug' => $slug],
                    ['name' => $name, 'status' => ComponentStatus::Operational->value, 'status_changed_at' => now(), 'position' => $componentPosition],
                );
                Monitor::query()->updateOrCreate(
                    ['component_id' => $component->id, 'name' => $name.' 主检查'],
                    ['agent_id' => $agent->id, 'type' => $type, 'interval_seconds' => 60, 'timeout_seconds' => $type === 'reverb' ? 10 : 5, 'enabled' => true, 'config' => $config, 'status' => ComponentStatus::Operational->value],
                );
                StatusInterval::query()->firstOrCreate(
                    ['component_id' => $component->id, 'ended_at' => null],
                    ['status' => ComponentStatus::Operational->value, 'started_at' => now()->subDays(90)],
                );
                $this->seedHistory($component);
            }
        }
    }

    private function seedHistory(Component $component): void
    {
        $start = CarbonImmutable::today('UTC')->subDays(89);
        for ($day = 0; $day < 90; $day++) {
            $date = $start->addDays($day);
            $signal = ($component->id * 31 + $day * 17) % 97;
            $status = $signal === 0 ? ComponentStatus::PartialOutage->value : ($signal < 5 ? ComponentStatus::Degraded->value : ComponentStatus::Operational->value);
            $uptime = $status === ComponentStatus::Operational->value ? 100 : ($status === ComponentStatus::Degraded->value ? 99.5 : 96.0);
            DailyRollup::query()->updateOrCreate(
                ['component_id' => $component->id, 'date' => $date->toDateString()],
                ['uptime_percentage' => $uptime, 'average_latency_ms' => 35 + (($component->id + $day) % 45), 'checks_total' => 1440, 'checks_failed' => $status === ComponentStatus::Operational->value ? 0 : 7, 'observed_seconds' => 86400, 'available_seconds' => (int) round(86400 * $uptime / 100), 'worst_status' => $status],
            );
        }
    }
}
