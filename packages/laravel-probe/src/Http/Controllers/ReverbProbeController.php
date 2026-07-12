<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use StatusPage\LaravelProbe\Events\StatusProbeBroadcast;
use StatusPage\LaravelProbe\Support\SafeLogger;
use Throwable;

final class ReverbProbeController
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly SafeLogger $logger,
    ) {}

    public function __invoke(): JsonResponse
    {
        $nonce = bin2hex(random_bytes(32));
        $sentAt = now()->toISOString();

        try {
            // ShouldBroadcastNow forces the broadcast through the sync queue even
            // when the monitored application uses an asynchronous default queue.
            $this->events->dispatch(new StatusProbeBroadcast($nonce, $sentAt));
        } catch (Throwable $exception) {
            $this->logger->warning('reverb_broadcast_failed', ['exception' => $exception::class]);

            return response()->json([
                'status' => 'failed',
                'code' => 'reverb_broadcast_failed',
            ], 503)->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json([
            'status' => 'accepted',
            'code' => 'broadcast_triggered',
            'nonce' => $nonce,
            'channel' => StatusProbeBroadcast::CHANNEL,
            'event' => StatusProbeBroadcast::EVENT,
            'sent_at' => $sentAt,
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }
}
