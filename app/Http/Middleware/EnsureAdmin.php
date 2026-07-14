<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->rol !== 'ADMIN') {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. Se requiere rol ADMIN.',
            ], 403);
        }

        return $next($request);
    }
}
