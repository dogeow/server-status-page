<?php

namespace App\Console\Commands;

use App\Models\AgentEnrollmentToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAgentToken extends Command
{
    protected $signature = 'status:agent-token {name=central-agent} {--expires=60 : Lifetime in minutes}';

    protected $description = 'Create and print a one-time Agent enrollment token';

    public function handle(): int
    {
        $minutes = (int) $this->option('expires');
        if ($minutes < 5 || $minutes > 10080) {
            $this->error('Expiration must be between 5 and 10080 minutes.');

            return self::FAILURE;
        }

        $raw = Str::random(64);
        AgentEnrollmentToken::query()->create([
            'name' => (string) $this->argument('name'),
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $this->line($raw);

        return self::SUCCESS;
    }
}
