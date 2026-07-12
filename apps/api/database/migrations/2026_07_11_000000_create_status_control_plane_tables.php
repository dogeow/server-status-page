<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('viewer')->index();
        });

        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('timezone')->default('Asia/Shanghai');
            $table->string('locale', 10)->default('zh_CN');
            $table->boolean('is_public')->default(true);
            $table->json('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('component_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
            $table->unique(['status_page_id', 'slug']);
        });

        Schema::create('components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('unknown')->index();
            $table->timestampTz('status_changed_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->timestampsTz();
            $table->unique(['component_group_id', 'slug']);
        });

        Schema::create('agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('secret')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('plan_version')->default(1);
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampTz('enrolled_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('agent_enrollment_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('laravel_integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('application_id')->index();
            $table->text('secret_current');
            $table->text('secret_next')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['status_page_id', 'application_id']);
        });

        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('name');
            $table->string('type', 32)->index();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('timeout_seconds')->default(5);
            $table->unsignedInteger('slow_threshold_ms')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->text('secret_config')->nullable();
            $table->unsignedBigInteger('config_version')->default(1);
            $table->string('status', 32)->default('unknown')->index();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_successes')->default(0);
            $table->unsignedSmallInteger('consecutive_slow')->default(0);
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('last_alerted_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->createPostgresCheckResults();
        } else {
            Schema::create('check_results', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
                $table->timestampTz('scheduled_at');
                $table->unsignedBigInteger('config_version');
                $table->string('status', 24)->index();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->text('message')->nullable();
                $table->json('metrics')->nullable();
                $table->timestampTz('received_at')->useCurrent();
                $table->unique(['monitor_id', 'agent_id', 'scheduled_at', 'config_version'], 'check_results_idempotency');
                $table->index(['monitor_id', 'scheduled_at']);
            });
        }

        Schema::create('queue_probe_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('laravel_integration_id')->constrained('laravel_integrations')->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->uuid('probe_id');
            $table->string('target');
            $table->timestampTz('enqueued_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('degraded_at')->nullable();
            $table->timestampTz('down_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestampsTz();
            $table->unique(['laravel_integration_id', 'monitor_id', 'probe_id'], 'queue_probe_monitor_unique');
            $table->index(['monitor_id', 'status', 'enqueued_at']);
        });

        Schema::create('status_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->boolean('is_maintenance')->default(false);
            $table->index(['component_id', 'started_at']);
        });

        Schema::create('daily_rollups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('uptime_percentage', 7, 4)->default(100);
            $table->unsignedInteger('average_latency_ms')->nullable();
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);
            $table->unsignedInteger('maintenance_seconds')->default(0);
            $table->unsignedInteger('observed_seconds')->default(0);
            $table->unsignedInteger('available_seconds')->default(0);
            $table->string('worst_status', 32)->default('operational');
            $table->timestampsTz();
            $table->unique(['component_id', 'date']);
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 24)->default('investigating')->index();
            $table->string('impact', 32)->default('degraded_performance')->index();
            $table->boolean('is_automatic')->default(false);
            $table->boolean('is_public')->default(true);
            $table->timestampTz('started_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('incident_components', function (Blueprint $table): void {
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->primary(['incident_id', 'component_id']);
        });

        Schema::create('incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->text('message');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('message')->nullable();
            $table->string('status', 24)->default('scheduled')->index();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('exclude_from_uptime')->default(true);
            $table->timestampsTz();
        });

        Schema::create('maintenance_window_components', function (Blueprint $table): void {
            $table->foreignId('maintenance_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->primary(['maintenance_window_id', 'component_id'], 'maintenance_component_pk');
        });

        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->text('config');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('notification_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('events')->nullable();
            $table->json('component_ids')->nullable();
            $table->unsignedInteger('repeat_minutes')->default(60);
            $table->json('quiet_hours')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('outbox_event_id')->nullable();
            $table->foreignId('notification_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient')->nullable();
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['outbox_event_id', 'notification_channel_id'], 'delivery_outbox_channel_unique');
            $table->index(['aggregate_type', 'aggregate_id', 'delivered_at'], 'delivery_aggregate_index');
        });

        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('confirmation_token_hash', 64)->nullable()->unique();
            $table->string('unsubscribe_token_hash', 64)->unique();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['status_page_id', 'email']);
        });

        Schema::create('subscriber_components', function (Blueprint $table): void {
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->primary(['subscriber_id', 'component_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 100)->index();
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent()->index();
            $table->timestampTz('processed_at')->nullable()->index();
            $table->timestampTz('claimed_at')->nullable()->index();
            $table->uuid('claim_token')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('used_nonces', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->foreignUuid('agent_id')->nullable()->constrained('agents')->cascadeOnDelete();
            $table->foreignId('monitor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('nonce', 128);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['scope', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_nonces');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('subscriber_components');
        Schema::dropIfExists('subscribers');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_policies');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('maintenance_window_components');
        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incident_components');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('daily_rollups');
        Schema::dropIfExists('status_intervals');
        Schema::dropIfExists('queue_probe_runs');
        Schema::dropIfExists('check_results');
        Schema::dropIfExists('monitors');
        Schema::dropIfExists('laravel_integrations');
        Schema::dropIfExists('agent_enrollment_tokens');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('components');
        Schema::dropIfExists('component_groups');
        Schema::dropIfExists('status_pages');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    private function createPostgresCheckResults(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE check_results (
                id bigint GENERATED BY DEFAULT AS IDENTITY,
                monitor_id bigint NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
                agent_id uuid NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
                scheduled_at timestamptz NOT NULL,
                config_version bigint NOT NULL,
                status varchar(24) NOT NULL,
                latency_ms integer NULL,
                error_code varchar(64) NULL,
                message text NULL,
                metrics jsonb NULL,
                received_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, scheduled_at),
                CONSTRAINT check_results_idempotency UNIQUE (monitor_id, agent_id, scheduled_at, config_version)
            ) PARTITION BY RANGE (scheduled_at)
            SQL);
        DB::statement('CREATE INDEX check_results_status_index ON check_results (status)');
        DB::statement('CREATE INDEX check_results_monitor_scheduled_index ON check_results (monitor_id, scheduled_at)');

        $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonth();
        for ($offset = 0; $offset < 4; $offset++) {
            $from = $month->addMonths($offset);
            $to = $from->addMonth();
            $name = 'check_results_'.$from->format('Ym');
            DB::statement("CREATE TABLE {$name} PARTITION OF check_results FOR VALUES FROM ('{$from->toDateString()}') TO ('{$to->toDateString()}')");
        }
        DB::statement('CREATE TABLE check_results_default PARTITION OF check_results DEFAULT');
    }
};
