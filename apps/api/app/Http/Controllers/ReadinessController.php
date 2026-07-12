<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReadinessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->merge([
            'nonce' => $request->input('nonce')
                ?: $request->header('X-Status-Nonce')
                ?: $request->header('X-Status-Probe-Nonce'),
        ]);
        $nonce = $request->validate(['nonce' => ['required', 'string', 'max:128']])['nonce'];
        DB::select('select 1');

        return response()->json([
            'ok' => true,
            'nonce' => $nonce,
            'service' => config('app.name'),
            'time' => now()->toIso8601String(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Status-Nonce' => $nonce,
        ]);
    }
}
