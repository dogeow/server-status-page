<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\UsedNonce;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAgentSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $agentId = (string) $request->header('X-Agent-Id');
        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $signature = strtolower((string) $request->header('X-Signature'));

        if ($agentId === '' || ! ctype_digit($timestamp) || strlen($nonce) < 8 || strlen($nonce) > 128 || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->unauthorized('missing_or_invalid_headers');
        }

        if (abs(now()->timestamp - (int) $timestamp) > (int) config('status.agent_signature_ttl', 300)) {
            return $this->unauthorized('timestamp_expired');
        }

        $agent = Agent::query()->find($agentId);
        if (! $agent || ! $agent->secret || $agent->status === 'revoked') {
            return $this->unauthorized('unknown_agent');
        }

        $bodyHash = hash('sha256', $request->getContent());
        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$bodyHash, $agent->secret);
        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized('invalid_signature');
        }

        UsedNonce::query()->where('expires_at', '<', now())->delete();
        try {
            UsedNonce::query()->create([
                'scope' => 'agent:'.$agent->id,
                'agent_id' => $agent->id,
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds((int) config('status.agent_signature_ttl', 300)),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Nonce already used.', 'code' => 'replayed_nonce'], 409);
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }

    private function unauthorized(string $code): JsonResponse
    {
        return response()->json(['message' => 'Agent authentication failed.', 'code' => $code], 401);
    }
}
