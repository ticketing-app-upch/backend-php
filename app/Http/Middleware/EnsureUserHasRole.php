<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth('api')->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if (! in_array($user->role->name, $roles, true)) {
            return response()->json(['message' => 'No tienes permiso para acceder a este recurso.'], 403);
        }

        return $next($request);
    }
}
