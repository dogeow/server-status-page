<?php

namespace App\Console\Commands;

use App\Models\AgentEnrollmentToken;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BootstrapOwner extends Command
{
    protected $signature = 'status:bootstrap-owner {email} {--name=Owner} {--password=}';

    protected $description = 'Create or update the first owner and default status page';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: $this->secret('Owner password'));
        if (strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::FAILURE;
        }

        $owner = User::query()->updateOrCreate(
            ['email' => Str::lower((string) $this->argument('email'))],
            ['name' => (string) $this->option('name'), 'password' => $password, 'role' => 'owner', 'email_verified_at' => now()],
        );
        StatusPage::query()->firstOrCreate(['slug' => 'main'], ['name' => config('app.name').' Status', 'timezone' => 'Asia/Shanghai', 'locale' => 'zh_CN']);

        $raw = Str::random(64);
        AgentEnrollmentToken::query()->create([
            'name' => 'bootstrap central-agent',
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addHour(),
        ]);

        $this->info('Owner ready: '.$owner->email);
        $this->warn('One-time central-agent enrollment token (valid 60 minutes):');
        $this->line($raw);

        return self::SUCCESS;
    }
}
