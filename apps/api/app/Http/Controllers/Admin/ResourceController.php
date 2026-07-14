<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationPolicy;
use App\Models\OutboxEvent;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceController extends Controller
{
    private const RESOURCES = [
        'status-pages' => StatusPage::class,
        'component-groups' => ComponentGroup::class,
        'components' => Component::class,
        'monitors' => Monitor::class,
        'agents' => Agent::class,
        'incidents' => Incident::class,
        'incident-updates' => IncidentUpdate::class,
        'maintenance-windows' => MaintenanceWindow::class,
        'notification-channels' => NotificationChannel::class,
        'notification-policies' => NotificationPolicy::class,
        'users' => User::class,
        'audit-logs' => AuditLog::class,
    ];

    private const READ_ONLY = ['audit-logs'];

    public function index(Request $request, string $resource): JsonResponse
    {
        $model = $this->model($resource);
        $query = $model::query();

        foreach (['status_page_id', 'component_group_id', 'component_id', 'agent_id', 'status', 'type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('search')) {
            $column = $resource === 'users' ? 'email' : ($resource === 'audit-logs' ? 'action' : 'name');
            $query->where($column, 'like', '%'.$request->query('search').'%');
        }

        return response()->json($query->latest($resource === 'audit-logs' ? 'created_at' : 'id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $item = $this->model($resource)::query()->findOrFail($id);
        $this->loadRelations($resource, $item);

        return response()->json(['data' => $item]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        abort_if(in_array($resource, [...self::READ_ONLY, 'agents'], true), 405);
        $data = $request->validate($this->rules($resource));
        $oneTimeSecret = null;
        if ($resource === 'notification-channels') {
            [$data, $oneTimeSecret] = $this->prepareNotificationChannel($data);
        }

        $item = DB::transaction(function () use ($request, $resource, $data): Model {
            $pivot = Arr::pull($data, 'component_ids');
            if ($resource === 'incident-updates') {
                $data['created_by'] ??= $request->user()->id;
            }
            $item = $this->model($resource)::query()->create($data);
            $this->syncPivot($resource, $item, $pivot);
            $this->touchAgentPlan($resource, null, $item);
            $this->audit($request, 'create', $item, null, $data);
            $this->emitDomainEvent($resource, $item, true);

            return $item;
        });

        $response = ['data' => $item->fresh()];
        if ($oneTimeSecret) {
            $response['webhook_secret'] = $oneTimeSecret;
            $response['warning'] = 'This webhook signing secret is shown once.';
        }

        return response()->json($response, 201);
    }

    public function update(Request $request, string $resource, string $id): JsonResponse
    {
        abort_if(in_array($resource, self::READ_ONLY, true), 405);
        $item = $this->model($resource)::query()->findOrFail($id);
        $data = $request->validate($this->rules($resource, true, $item));
        $oneTimeSecret = null;
        if ($resource === 'notification-channels') {
            [$data, $oneTimeSecret] = $this->prepareNotificationChannel($data, $item);
        }
        $before = $item->toArray();

        DB::transaction(function () use ($request, $resource, $item, $data, $before): void {
            $pivot = Arr::pull($data, 'component_ids');
            if ($resource === 'monitors' && $data !== []) {
                $data['config_version'] = $item->config_version + 1;
            }
            $item->update($data);
            $this->syncPivot($resource, $item, $pivot);
            $this->touchAgentPlan($resource, $before['agent_id'] ?? null, $item);
            $this->audit($request, 'update', $item, $before, $data);
            $this->emitDomainEvent($resource, $item, false);
        });

        $response = ['data' => $item->fresh()];
        if ($oneTimeSecret) {
            $response['webhook_secret'] = $oneTimeSecret;
            $response['warning'] = 'This webhook signing secret is shown once.';
        }

        return response()->json($response);
    }

    public function destroy(Request $request, string $resource, string $id): JsonResponse
    {
        abort_if(in_array($resource, self::READ_ONLY, true), 405);
        $item = $this->model($resource)::query()->findOrFail($id);
        abort_if($resource === 'users' && (string) $request->user()->getKey() === (string) $item->getKey(), 422, 'You cannot delete your own account.');
        $before = $item->toArray();

        DB::transaction(function () use ($request, $resource, $item, $before): void {
            $this->audit($request, 'delete', $item, $before, null);
            $oldAgent = $resource === 'monitors' ? $item->agent_id : null;
            $item->delete();
            if ($oldAgent) {
                Agent::query()->whereKey($oldAgent)->increment('plan_version');
            }
        });

        return response()->json(null, 204);
    }

    private function model(string $resource): string
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);

        return self::RESOURCES[$resource];
    }

    private function rules(string $resource, bool $partial = false, ?Model $item = null): array
    {
        $sometimes = $partial ? ['sometimes'] : ['required'];
        $rules = match ($resource) {
            'status-pages' => [
                'name' => [...$sometimes, 'string', 'max:255'],
                'slug' => [...$sometimes, 'string', 'max:255', Rule::unique('status_pages', 'slug')->ignore($item?->getKey())],
                'description' => ['nullable', 'string'], 'timezone' => ['sometimes', 'string', 'max:64'],
                'locale' => ['sometimes', 'string', 'max:10'], 'is_public' => ['sometimes', 'boolean'], 'settings' => ['nullable', 'array'],
            ],
            'component-groups' => [
                'status_page_id' => [...$sometimes, 'exists:status_pages,id'], 'name' => [...$sometimes, 'string', 'max:255'],
                'slug' => [...$sometimes, 'string', 'max:255'], 'position' => ['sometimes', 'integer', 'min:0'],
            ],
            'components' => [
                'component_group_id' => [...$sometimes, 'exists:component_groups,id'], 'name' => [...$sometimes, 'string', 'max:255'],
                'slug' => [...$sometimes, 'string', 'max:255'], 'description' => ['nullable', 'string'],
                'status' => ['sometimes', Rule::in(['operational', 'degraded_performance', 'partial_outage', 'major_outage', 'under_maintenance', 'unknown'])],
                'position' => ['sometimes', 'integer', 'min:0'], 'is_hidden' => ['sometimes', 'boolean'],
            ],
            'monitors' => [
                'component_id' => [...$sometimes, 'exists:components,id'], 'agent_id' => [...$sometimes, 'nullable', 'exists:agents,id'],
                'name' => [...$sometimes, 'string', 'max:255'],
                'type' => [...$sometimes, Rule::in(['http', 'tcp', 'dns', 'tls', 'squid', 'mysql', 'postgresql', 'redis', 'nextjs', 'laravel', 'reverb', 'laravel_queue', 'laravel_scheduler', 'heartbeat'])],
                'interval_seconds' => ['sometimes', 'integer', 'min:15', 'max:86400'], 'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:300'],
                'slow_threshold_ms' => ['nullable', 'integer', 'min:1'], 'enabled' => ['sometimes', 'boolean'],
                'config' => ['nullable', 'array'], 'secret_config' => ['nullable', 'array'],
            ],
            'agents' => ['name' => ['sometimes', 'string', 'max:255'], 'status' => ['sometimes', Rule::in(['online', 'offline', 'revoked'])]],
            'incidents' => [
                'status_page_id' => [...$sometimes, 'exists:status_pages,id'], 'title' => [...$sometimes, 'string', 'max:255'],
                'status' => ['sometimes', Rule::in(['investigating', 'identified', 'monitoring', 'resolved'])],
                'impact' => ['sometimes', Rule::in(['degraded_performance', 'partial_outage', 'major_outage'])],
                'is_public' => ['sometimes', 'boolean'], 'started_at' => [...$sometimes, 'date'], 'resolved_at' => ['nullable', 'date'],
                'component_ids' => ['sometimes', 'array'], 'component_ids.*' => ['integer', 'exists:components,id'],
            ],
            'incident-updates' => [
                'incident_id' => [...$sometimes, 'exists:incidents,id'], 'status' => [...$sometimes, Rule::in(['investigating', 'identified', 'monitoring', 'resolved'])],
                'message' => [...$sometimes, 'string', 'max:10000'], 'created_by' => ['nullable', 'exists:users,id'],
            ],
            'maintenance-windows' => [
                'status_page_id' => [...$sometimes, 'exists:status_pages,id'], 'name' => [...$sometimes, 'string', 'max:255'], 'message' => ['nullable', 'string'],
                'status' => ['sometimes', Rule::in(['scheduled', 'in_progress', 'completed', 'cancelled'])],
                'starts_at' => [...$sometimes, 'date'], 'ends_at' => [...$sometimes, 'date', 'after:starts_at'], 'exclude_from_uptime' => ['sometimes', 'boolean'],
                'component_ids' => ['sometimes', 'array'], 'component_ids.*' => ['integer', 'exists:components,id'],
            ],
            'notification-channels' => [
                'status_page_id' => [...$sometimes, 'exists:status_pages,id'], 'name' => [...$sometimes, 'string', 'max:255'],
                'type' => [...$sometimes, Rule::in(['email', 'webhook'])], 'config' => [...$sometimes, 'array'], 'enabled' => ['sometimes', 'boolean'],
                'config.to' => ['nullable', 'array', 'min:1'], 'config.to.*' => ['email:rfc', 'max:255'],
                'config.url' => ['nullable', 'url:http,https', 'max:2048'],
            ],
            'notification-policies' => [
                'status_page_id' => [...$sometimes, 'exists:status_pages,id'], 'notification_channel_id' => [...$sometimes, 'exists:notification_channels,id'],
                'name' => [...$sometimes, 'string', 'max:255'], 'events' => ['nullable', 'array'], 'component_ids' => ['nullable', 'array'],
                'repeat_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'], 'quiet_hours' => ['nullable', 'array'], 'enabled' => ['sometimes', 'boolean'],
            ],
            'users' => [
                'name' => [...$sometimes, 'string', 'max:255'], 'email' => [...$sometimes, 'email', Rule::unique('users', 'email')->ignore($item?->getKey())],
                'password' => [...$sometimes, 'string', 'min:12'], 'role' => [...$sometimes, Rule::in(['owner', 'admin', 'viewer'])],
            ],
            default => [],
        };

        return $rules;
    }

    private function syncPivot(string $resource, Model $item, mixed $ids): void
    {
        if ($ids === null) {
            return;
        }
        if (in_array($resource, ['incidents', 'maintenance-windows'], true)) {
            $item->components()->sync($ids);
        }
    }

    private function prepareNotificationChannel(array $data, ?NotificationChannel $existing = null): array
    {
        $type = $data['type'] ?? $existing?->type;
        $config = $data['config'] ?? $existing?->config ?? [];
        if ($type === 'email') {
            $recipients = $config['to'] ?? null;
            if (! is_array($recipients) || $recipients === []) {
                throw ValidationException::withMessages(['config.to' => 'At least one email recipient is required.']);
            }
            unset($config['secret']);
            $data['config'] = $config;

            return [$data, null];
        }
        if ($type === 'webhook') {
            $url = $config['url'] ?? null;
            $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
                throw ValidationException::withMessages(['config.url' => 'A valid HTTP or HTTPS webhook URL is required.']);
            }
            $generated = null;
            $existingSecret = $existing?->type === 'webhook' ? ($existing->config['secret'] ?? null) : null;
            if (is_string($existingSecret) && $existingSecret !== '') {
                $config['secret'] = $existingSecret;
            } else {
                $generated = bin2hex(random_bytes(32));
                $config['secret'] = $generated;
            }
            $data['config'] = $config;

            return [$data, $generated];
        }

        return [$data, null];
    }

    private function touchAgentPlan(string $resource, mixed $oldAgentId, Model $item): void
    {
        if ($resource !== 'monitors') {
            return;
        }
        $ids = collect([$oldAgentId, $item->agent_id])->filter()->unique();
        Agent::query()->whereIn('id', $ids)->increment('plan_version');
    }

    private function loadRelations(string $resource, Model $item): void
    {
        match ($resource) {
            'status-pages' => $item->load('groups.components'),
            'component-groups' => $item->load('components'),
            'components' => $item->load('monitors'),
            'incidents' => $item->load('components', 'updates'),
            'maintenance-windows' => $item->load('components'),
            default => null,
        };
    }

    private function audit(Request $request, string $action, Model $item, ?array $before, ?array $after): void
    {
        $redact = fn (?array $values) => $values === null ? null : Arr::except($values, ['password', 'secret', 'secret_config', 'config', 'remember_token']);
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => $item::class,
            'auditable_id' => (string) $item->getKey(),
            'before' => $redact($before),
            'after' => $redact($after),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    private function emitDomainEvent(string $resource, Model $item, bool $created): void
    {
        if ($resource === 'incident-updates') {
            /** @var IncidentUpdate $item */
            $incident = $item->incident()->with('components')->firstOrFail();
            $changes = ['status' => $item->status];
            if ($item->status === 'resolved') {
                $changes['resolved_at'] = now();
            }
            $incident->update($changes);
            $item = $incident;
            $resource = 'incidents';
            $created = false;
        }

        if ($resource === 'incidents') {
            /** @var Incident $item */
            $item->loadMissing('components');
            $type = $item->status === 'resolved' ? 'incident.resolved' : ($created ? 'incident.created' : 'incident.updated');
            OutboxEvent::query()->create([
                'type' => $type,
                'aggregate_type' => 'incident',
                'aggregate_id' => (string) $item->id,
                'payload' => [
                    'incident_id' => $item->id,
                    'status_page_id' => $item->status_page_id,
                    'title' => $item->title,
                    'status' => $item->status,
                    'severity' => $item->impact,
                    'component_ids' => $item->components->pluck('id')->all(),
                    'occurred_at' => now()->toIso8601String(),
                ],
                'available_at' => now(),
            ]);
        }

        if ($resource === 'maintenance-windows') {
            /** @var MaintenanceWindow $item */
            $item->loadMissing('components');
            OutboxEvent::query()->create([
                'type' => $created ? 'maintenance.scheduled' : 'maintenance.updated',
                'aggregate_type' => 'maintenance_window',
                'aggregate_id' => (string) $item->id,
                'payload' => [
                    'maintenance_id' => $item->id,
                    'status_page_id' => $item->status_page_id,
                    'title' => $item->name,
                    'status' => $item->status,
                    'component_ids' => $item->components->pluck('id')->all(),
                    'starts_at' => $item->starts_at->toIso8601String(),
                    'ends_at' => $item->ends_at->toIso8601String(),
                    'occurred_at' => now()->toIso8601String(),
                ],
                'available_at' => now(),
            ]);
        }
    }
}
