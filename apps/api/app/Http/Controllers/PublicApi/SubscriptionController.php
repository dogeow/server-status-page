<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Support\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'page' => ['nullable', 'string', 'max:255'],
            'component_ids' => ['nullable', 'array'],
            'component_ids.*' => ['integer'],
        ]);
        $page = StatusPage::query()->where('is_public', true)->when($data['page'] ?? null, fn ($query, $slug) => $query->where('slug', $slug))->firstOrFail();
        $confirmationToken = Str::random(64);
        $unsubscribeToken = Str::random(64);

        DB::transaction(function () use ($data, $page, $confirmationToken, $unsubscribeToken): void {
            $subscriber = Subscriber::query()->updateOrCreate(
                ['status_page_id' => $page->id, 'email' => Str::lower($data['email'])],
                [
                    'confirmation_token_hash' => hash('sha256', $confirmationToken),
                    'unsubscribe_token_hash' => hash('sha256', $unsubscribeToken),
                    'confirmed_at' => null,
                    'unsubscribed_at' => null,
                ],
            );
            $allowedIds = $page->groups()->with('components:id,component_group_id')->get()->flatMap->components->pluck('id');
            $subscriber->components()->sync(collect($data['component_ids'] ?? [])->intersect($allowedIds)->values());

            OutboxEvent::query()->create([
                'type' => 'subscriber.confirmation_requested',
                'aggregate_type' => 'subscriber',
                'aggregate_id' => (string) $subscriber->id,
                'payload' => [
                    'email' => $subscriber->email,
                    'subject' => $page->name.' 状态通知订阅确认',
                    'confirmation_url' => PublicUrl::to('/api/public/v1/subscriptions/confirm/'.$confirmationToken),
                    'unsubscribe_url' => PublicUrl::to('/api/public/v1/subscriptions/unsubscribe/'.$unsubscribeToken),
                ],
                'available_at' => now(),
            ]);
        });

        return response()->json(['message' => 'If the address is valid, a confirmation email will be sent.'], 202);
    }

    public function confirm(string $token): JsonResponse
    {
        $subscriber = Subscriber::query()->where('confirmation_token_hash', hash('sha256', $token))->firstOrFail();
        $subscriber->update(['confirmed_at' => now(), 'confirmation_token_hash' => null, 'unsubscribed_at' => null]);

        return response()->json(['message' => 'Subscription confirmed.']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'min:32']])['token'];
        $subscriber = Subscriber::query()->where('unsubscribe_token_hash', hash('sha256', $token))->firstOrFail();
        $subscriber->update(['unsubscribed_at' => now()]);

        return response()->json(['message' => 'Subscription cancelled.']);
    }

    public function unsubscribeLink(string $token): JsonResponse
    {
        $subscriber = Subscriber::query()->where('unsubscribe_token_hash', hash('sha256', $token))->firstOrFail();
        $subscriber->update(['unsubscribed_at' => now()]);

        return response()->json(['message' => 'Subscription cancelled.']);
    }
}
