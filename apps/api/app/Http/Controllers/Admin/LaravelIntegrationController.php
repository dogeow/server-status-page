<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LaravelIntegration;
use App\Support\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaravelIntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(LaravelIntegration::query()->latest('created_at')->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status_page_id' => ['required', 'exists:status_pages,id'],
            'name' => ['required', 'string', 'max:255'],
            'application_id' => ['required', 'regex:/^[A-Za-z0-9._-]{1,100}$/'],
        ]);
        $secret = bin2hex(random_bytes(32));
        $integration = LaravelIntegration::query()->create([...$data, 'secret_current' => $secret, 'enabled' => true]);
        $this->audit($request, 'create', $integration);

        return response()->json($this->credentials($integration, $secret), 201);
    }

    public function show(LaravelIntegration $integration): JsonResponse
    {
        return response()->json(['data' => $integration, 'endpoint' => $this->endpoint($integration)]);
    }

    public function update(Request $request, LaravelIntegration $integration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'application_id' => ['sometimes', 'regex:/^[A-Za-z0-9._-]{1,100}$/'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $integration->update($data);
        $this->audit($request, 'update', $integration);

        return response()->json(['data' => $integration->fresh(), 'endpoint' => $this->endpoint($integration)]);
    }

    public function rotate(Request $request, LaravelIntegration $integration): JsonResponse
    {
        if ($request->boolean('promote')) {
            abort_unless($integration->secret_next, 422, 'No next secret is configured.');
            $integration->update(['secret_current' => $integration->secret_next, 'secret_next' => null]);
            $this->audit($request, 'promote_secret', $integration);

            return response()->json(['data' => $integration->fresh(), 'message' => 'Next secret promoted.']);
        }

        $next = bin2hex(random_bytes(32));
        $integration->update(['secret_next' => $next]);
        $this->audit($request, 'rotate_secret', $integration);

        return response()->json(['integration_id' => $integration->id, 'secret_next' => $next, 'warning' => 'This next secret is shown once.']);
    }

    public function destroy(Request $request, LaravelIntegration $integration): JsonResponse
    {
        $this->audit($request, 'delete', $integration);
        $integration->delete();

        return response()->json(null, 204);
    }

    private function credentials(LaravelIntegration $integration, string $secret): array
    {
        return [
            'data' => $integration,
            'endpoint' => $this->endpoint($integration),
            'secret_current' => $secret,
            'environment' => [
                'STATUS_PROBE_PUSH_URL' => $this->endpoint($integration),
                'STATUS_PROBE_APP_ID' => $integration->application_id,
                'STATUS_PROBE_SECRET_CURRENT' => $secret,
            ],
            'warning' => 'The secret is shown once.',
        ];
    }

    private function endpoint(LaravelIntegration $integration): string
    {
        return PublicUrl::to('/api/probe/v1/integrations/'.$integration->id.'/events');
    }

    private function audit(Request $request, string $action, LaravelIntegration $integration): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => LaravelIntegration::class,
            'auditable_id' => $integration->id,
            'after' => ['name' => $integration->name, 'application_id' => $integration->application_id, 'enabled' => $integration->enabled],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
