<?php

namespace App\Http\Middleware;

use App\Models\SesionIntranet;
use Closure;
use Illuminate\Http\Request;

class CheckSessionActivity
{
    private const INACTIVITY_MINUTES = 30;
    private const UPDATE_THRESHOLD_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $tokenId = $user->currentAccessToken()->id;

        $sesion = SesionIntranet::where('token_sanctum_id', $tokenId)
            ->where('activo', true)
            ->first();

        if ($sesion) {
            $lastActivity = $sesion->fec_ultimo_acceso ?? $sesion->fec_login;

            if ($lastActivity && $lastActivity->diffInMinutes(now()) >= self::INACTIVITY_MINUTES) {
                $sesion->update([
                    'activo'     => false,
                    'fec_logout' => now(),
                ]);
                $user->tokens()->where('id', $tokenId)->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Sesión expirada por inactividad.',
                    'code'    => 'SESSION_EXPIRED',
                ], 401);
            }

            // Solo actualizar si pasó más de 1 minuto desde el último registro (reduce writes)
            if (!$sesion->fec_ultimo_acceso ||
                now()->diffInSeconds($sesion->fec_ultimo_acceso) >= self::UPDATE_THRESHOLD_SECONDS) {
                $sesion->update(['fec_ultimo_acceso' => now()]);
            }
        }

        return $next($request);
    }
}
