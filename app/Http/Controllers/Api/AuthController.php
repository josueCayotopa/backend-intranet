<?php

namespace App\Http\Controllers\Api;

use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseController
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * Autentica al usuario, registra sesión y retorna token Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'usuario'  => 'required|string|max:100',
                'password' => 'required|string|min:4',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $result = $this->authService->login(
                $validated['usuario'],
                $validated['password'],
                $request
            );

            if ($result === null) {
                return $this->error('Credenciales incorrectas.', 401);
            }

            return $this->success($result, 'Login exitoso.');
        } catch (\Exception $e) {
            return $this->error('Error al autenticar: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cierra la sesión actual (revoca el token en uso).
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $tokenId = $request->user()->currentAccessToken()->id;
            $this->authService->logout($request->user(), $tokenId);

            return $this->success(null, 'Sesión cerrada correctamente.');
        } catch (\Exception) {
            return $this->error('Error al cerrar sesión.', 500);
        }
    }

    /**
     * Cierra TODAS las sesiones activas del usuario en todos los dispositivos.
     */
    public function logoutTodos(Request $request): JsonResponse
    {
        try {
            $this->authService->logoutTodos($request->user());

            return $this->success(null, 'Todas las sesiones han sido cerradas.');
        } catch (\Exception) {
            return $this->error('Error al cerrar sesiones.', 500);
        }
    }

    /**
     * Retorna los datos del usuario autenticado con su empresa.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('empresa');

        $this->authService->sincronizarNombreDesdeErp($user);

        return $this->success([
            'usuario' => $this->authService->formatearUsuario($user),
            'empresa' => [
                'id'       => $user->empresa->id,
                'codigo'   => $user->empresa->codigo,
                'nombre'   => $user->empresa->nombre,
                'ruc'      => $user->empresa->ruc,
                'logo_url' => $user->empresa->logo_url,
            ],
        ]);
    }

    /**
     * Lista las sesiones del usuario autenticado.
     * ?solo_activas=1 para filtrar solo las activas.
     */
    public function sesiones(Request $request): JsonResponse
    {
        $soloActivas    = $request->boolean('solo_activas', false);
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $sesiones       = $this->authService->listarSesiones($request->user(), $soloActivas, $currentTokenId);

        return $this->success($sesiones, 'Sesiones obtenidas.');
    }

    /**
     * Cierra una sesión específica (revoca ese dispositivo).
     */
    public function cerrarSesion(Request $request, int $id): JsonResponse
    {
        $ok = $this->authService->cerrarSesion($request->user(), $id);

        if (!$ok) {
            return $this->error('Sesión no encontrada o ya cerrada.', 404);
        }

        return $this->success(null, 'Sesión cerrada correctamente.');
    }

    /**
     * Sube o reemplaza la foto de perfil del usuario autenticado.
     * Guarda en storage/app/public/fotos-perfil/ y actualiza foto_url.
     */
    public function subirFoto(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Archivo inválido.', 422, $e->errors());
        }

        try {
            $user = $request->user();

            // Borrar foto anterior si existe
            if ($user->foto_url) {
                // Extraer path relativo desde URL absoluta o relativa
                $pathAnterior = preg_replace('#^.*/storage/#', 'public/', $user->foto_url);
                \Illuminate\Support\Facades\Storage::delete($pathAnterior);
            }

            $path    = $request->file('foto')->store('fotos-perfil', 'public');
            $fotoUrl = url('storage/' . $path);   // URL absoluta: http://127.0.0.1:8000/storage/...

            $user->update(['foto_url' => $fotoUrl]);

            return $this->success(['foto_url' => $fotoUrl], 'Foto actualizada.');
        } catch (\Exception $e) {
            return $this->error('Error al subir la foto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Restablece la contraseña de un usuario a su DNI (flujo público, sin autenticación).
     * Activa debe_cambiar_password para forzar cambio al siguiente ingreso.
     */
    public function recuperarPassword(Request $request): JsonResponse
    {
        try {
            $v = $request->validate([
                'usuario' => 'required|string|max:100',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $this->authService->recuperarPassword($v['usuario']);
            return $this->success(null, 'Contraseña restablecida a tu DNI. Al ingresar deberás cambiarla.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception) {
            return $this->error('Error al restablecer la contraseña.', 500);
        }
    }

    /**
     * El usuario autenticado cambia su propia contraseña.
     * Requiere la contraseña actual para confirmar identidad.
     */
    public function cambiarPassword(Request $request): JsonResponse
    {
        try {
            $v = $request->validate([
                'password_actual'              => 'required|string|min:4',
                'password_nuevo'               => 'required|string|min:6|confirmed',
                'password_nuevo_confirmation'  => 'required|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $this->authService->cambiarPassword(
                $request->user(),
                $v['password_actual'],
                $v['password_nuevo']
            );

            return $this->success(null, 'Contraseña actualizada correctamente.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception) {
            return $this->error('Error al actualizar la contraseña.', 500);
        }
    }

    /**
     * Actualiza fec_ultimo_acceso (el frontend llama periódicamente).
     */
    public function ping(Request $request): JsonResponse
    {
        $tokenId = $request->user()->currentAccessToken()->id;
        $this->authService->ping($tokenId);

        return $this->success(null, 'OK');
    }
}
