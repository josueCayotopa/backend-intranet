<?php

namespace App\Http\Controllers\Api;

use App\Services\UserService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends BaseController
{
    public function __construct(private readonly UserService $userService) {}

    /**
     * Lista usuarios con filtros opcionales: empresa_id, activo, buscar.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters   = $request->only(['empresa_id', 'activo', 'rol', 'buscar', 'pagina', 'por_pagina']);
            $resultado = $this->userService->listar($filters);

            return $this->success($resultado, 'Usuarios obtenidos.');
        } catch (\Exception) {
            return $this->error('Error al obtener usuarios.', 500);
        }
    }

    /**
     * Crea un nuevo usuario de la intranet.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'empresa_id'        => 'required|integer|exists:EMPRESAS,id',
                'cod_personal'      => 'required|string|max:20',
                'ape_paterno'       => 'nullable|string|max:100',
                'ape_materno'       => 'nullable|string|max:100',
                'nom_trabajador'    => 'nullable|string|max:200',
                'cod_personal_jefe' => 'nullable|string|max:20',
                'dni'               => 'required|string|max:15',
                'usuario'           => 'required|string|max:100|unique:USUARIOS_INTRANET,usuario',
                'password'          => 'required|string|min:6|confirmed',
                'foto_url'          => 'nullable|url|max:500',
                'rol'               => 'nullable|string|in:ADMIN,EMPLEADO',
                'activo'            => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $usuario = $this->userService->crear($validated);

            return $this->success(
                ['id' => $usuario->id, 'usuario' => $usuario->usuario],
                'Usuario creado correctamente.',
                201
            );
        } catch (QueryException $e) {
            return $this->error('Error de base de datos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Actualiza datos de un usuario (sin contraseña).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'empresa_id'        => 'integer|exists:EMPRESAS,id',
                'cod_personal'      => 'string|max:20',
                'ape_paterno'       => 'nullable|string|max:100',
                'ape_materno'       => 'nullable|string|max:100',
                'nom_trabajador'    => 'nullable|string|max:200',
                'cod_personal_jefe' => 'nullable|string|max:20',
                'dni'               => 'string|max:15',
                'usuario'           => "string|max:100|unique:USUARIOS_INTRANET,usuario,{$id}",
                'foto_url'          => 'nullable|url|max:500',
                'rol'               => 'nullable|string|in:ADMIN,EMPLEADO',
                'activo'            => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $usuario = $this->userService->editar($id, $validated);

            return $this->success(
                ['id' => $usuario->id, 'usuario' => $usuario->usuario],
                'Usuario actualizado correctamente.'
            );
        } catch (ModelNotFoundException) {
            return $this->error('Usuario no encontrado.', 404);
        } catch (QueryException $e) {
            return $this->error('Error de base de datos.', 500);
        }
    }

    /**
     * Cambia la contraseña de un usuario.
     */
    public function cambiarPassword(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'password' => 'required|string|min:6|confirmed',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $ok = $this->userService->cambiarPassword($id, $validated['password']);

            if (!$ok) {
                return $this->error('Usuario no encontrado.', 404);
            }

            return $this->success(null, 'Contraseña actualizada correctamente.');
        } catch (QueryException $e) {
            return $this->error('Error de base de datos.', 500);
        }
    }

    /**
     * Alterna el estado activo/inactivo de un usuario.
     */
    public function toggleActivo(int $id): JsonResponse
    {
        try {
            $usuario = $this->userService->toggleActivo($id);
            $estado  = $usuario->activo ? 'activado' : 'desactivado';

            return $this->success(
                ['id' => $usuario->id, 'activo' => $usuario->activo],
                "Usuario {$estado} correctamente."
            );
        } catch (ModelNotFoundException) {
            return $this->error('Usuario no encontrado.', 404);
        } catch (QueryException) {
            return $this->error('Error de base de datos.', 500);
        }
    }
}
