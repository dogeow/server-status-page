<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use Illuminate\Http\JsonResponse;

class NotificationChannelSecretController extends Controller
{
    public function __invoke(NotificationChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'webhook', 422, 'Only webhook channels have signing secrets.');
        $secret = bin2hex(random_bytes(32));
        $channel->update(['config' => [...$channel->config, 'secret' => $secret]]);

        return response()->json([
            'notification_channel_id' => $channel->id,
            'webhook_secret' => $secret,
            'warning' => 'This webhook signing secret is shown once.',
        ]);
    }
}
