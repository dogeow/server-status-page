<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $roles = $roles ?: ['owner', 'admin'];

        abort_unless($request->user() && in_array($request->user()->role, $roles, true), 403, 'Insufficient role.');

        return $next($request);
    }
}
