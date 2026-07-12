<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentEnrollmentToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnrollmentTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'expires_in_minutes' => ['nullable', 'integer', 'min:5', 'max:10080']]);
        $raw = Str::random(64);
        $token = AgentEnrollmentToken::query()->create([
            'name' => $data['name'] ?? null,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes($data['expires_in_minutes'] ?? 60),
        ]);

        return response()->json([
            'id' => $token->id,
            'token' => $raw,
            'expires_at' => $token->expires_at->toIso8601String(),
            'warning' => 'This token is shown once.',
        ], 201);
    }
}
