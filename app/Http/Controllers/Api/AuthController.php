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
     * Autentica al usuario y retorna token Sanctum.
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
                $validated['password']
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
     * Cierra la sesión revocando todos los tokens del usuario.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return $this->success(null, 'Sesión cerrada correctamente.');
        } catch (\Exception) {
            return $this->error('Error al cerrar sesión.', 500);
        }
    }

    /**
     * Retorna los datos del usuario autenticado con su empresa.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('empresa');

        return $this->success([
            'id'           => $user->id,
            'usuario'      => $user->usuario,
            'cod_personal' => $user->cod_personal,
            'dni'          => $user->dni,
            'foto_url'     => $user->foto_url,
            'activo'       => $user->activo,
            'empresa'      => [
                'id'       => $user->empresa->id,
                'codigo'   => $user->empresa->codigo,
                'nombre'   => $user->empresa->nombre,
                'ruc'      => $user->empresa->ruc,
                'logo_url' => $user->empresa->logo_url,
            ],
        ]);
    }
}
