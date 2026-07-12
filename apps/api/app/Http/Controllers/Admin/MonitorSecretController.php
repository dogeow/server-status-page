<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Monitor;
use App\Support\PublicUrl;
use Illuminate\Http\JsonResponse;

class MonitorSecretController extends Controller
{
    public function rotate(Monitor $monitor): JsonResponse
    {
        abort_unless($monitor->type === 'heartbeat', 422, 'Heartbeat secrets are only available for heartbeat monitors.');

        $secret = bin2hex(random_bytes(32));
        $monitor->update([
            'secret_config' => [...($monitor->secret_config ?: []), 'heartbeat_secret' => $secret],
            'config_version' => $monitor->config_version + 1,
        ]);
        if ($monitor->agent_id) {
            Agent::query()->whereKey($monitor->agent_id)->increment('plan_version');
        }

        return response()->json([
            'monitor_id' => $monitor->id,
            'heartbeat_secret' => $secret,
            'heartbeat_url' => PublicUrl::to('/api/probe/v1/heartbeat/'.$monitor->id),
            'warning' => 'This secret is shown once.',
        ]);
    }
}
