<?php

namespace Database\Seeders;

use App\Models\StatusPage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        StatusPage::query()->firstOrCreate(
            ['slug' => 'main'],
            ['name' => '系统状态', 'description' => '服务可用性与事件历史', 'timezone' => 'Asia/Shanghai', 'locale' => 'zh_CN', 'is_public' => true],
        );

        if (filter_var(env('STATUS_SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoSeeder::class);
        }
    }
}
