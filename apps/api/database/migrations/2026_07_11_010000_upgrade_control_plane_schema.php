<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laravel_integrations')) {
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
        }

        $this->addMissingColumns('monitors', [
            'last_success_at' => fn (Blueprint $table) => $table->timestampTz('last_success_at')->nullable(),
            'last_event_at' => fn (Blueprint $table) => $table->timestampTz('last_event_at')->nullable(),
            'last_error_code' => fn (Blueprint $table) => $table->string('last_error_code', 64)->nullable(),
            'last_alerted_at' => fn (Blueprint $table) => $table->timestampTz('last_alerted_at')->nullable(),
        ]);

        if (! Schema::hasTable('queue_probe_runs')) {
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
        }

        $this->addMissingColumns('daily_rollups', [
            'observed_seconds' => fn (Blueprint $table) => $table->unsignedInteger('observed_seconds')->default(0),
            'available_seconds' => fn (Blueprint $table) => $table->unsignedInteger('available_seconds')->default(0),
        ]);
        $this->addMissingColumns('notification_deliveries', [
            'recipient' => fn (Blueprint $table) => $table->string('recipient')->nullable(),
            'aggregate_type' => fn (Blueprint $table) => $table->string('aggregate_type')->nullable(),
            'aggregate_id' => fn (Blueprint $table) => $table->string('aggregate_id')->nullable(),
        ]);
        $this->addMissingColumns('outbox_events', [
            'claimed_at' => fn (Blueprint $table) => $table->timestampTz('claimed_at')->nullable()->index(),
            'claim_token' => fn (Blueprint $table) => $table->uuid('claim_token')->nullable()->index(),
        ]);

        if (! Schema::hasColumn('used_nonces', 'scope')) {
            Schema::table('used_nonces', fn (Blueprint $table) => $table->string('scope', 100)->default('agent'));
        }
        if (! Schema::hasColumn('used_nonces', 'monitor_id')) {
            Schema::table('used_nonces', fn (Blueprint $table) => $table->foreignId('monitor_id')->nullable()->constrained()->cascadeOnDelete());
        }
        $agentIdColumn = collect(Schema::getColumns('used_nonces'))->firstWhere('name', 'agent_id');
        if ($agentIdColumn && ! $agentIdColumn['nullable']) {
            Schema::table('used_nonces', fn (Blueprint $table) => $table->foreignUuid('agent_id')->nullable()->change());
        }

        $indexExists = DB::connection()->getDriverName() === 'pgsql'
            ? DB::table('pg_indexes')->where('indexname', 'used_nonces_scope_nonce_unique')->exists()
            : collect(DB::select("PRAGMA index_list('used_nonces')"))->contains(fn ($index) => $index->name === 'used_nonces_scope_nonce_unique');
        if (! $indexExists) {
            Schema::table('used_nonces', fn (Blueprint $table) => $table->unique(['scope', 'nonce']));
        }
    }

    public function down(): void
    {
        // This compatibility migration only fills schema introduced before the
        // first release. Destructive rollback belongs to the base migration.
    }

    /** @param array<string, callable(Blueprint): mixed> $columns */
    private function addMissingColumns(string $tableName, array $columns): void
    {
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, fn (Blueprint $table) => $definition($table));
            }
        }
    }
};
