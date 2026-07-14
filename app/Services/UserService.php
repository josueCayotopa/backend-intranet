<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\UsuarioIntranet;

class UserService
{
    /**
     * Lista usuarios con filtros y paginación.
     * Devuelve ['items' => [...], 'meta' => [total, pagina, por_pagina, ultima_pagina]].
     */
    public function listar(array $filters = []): array
    {
        $query = DB::table('USUARIOS_INTRANET AS u')
            ->join('EMPRESAS AS e', 'e.id', '=', 'u.empresa_id')
            ->select(
                'u.id',
                'u.empresa_id',
                'e.nombre as empresa_nombre',
                'u.cod_personal',
                'u.ape_paterno',
                'u.ape_materno',
                'u.nom_trabajador',
                'u.cod_personal_jefe',
                'u.dni',
                'u.usuario',
                'u.foto_url',
                'u.rol',
                'u.activo',
                'u.created_at',
                'u.updated_at'
            );

        if (!empty($filters['empresa_id'])) {
            $query->where('u.empresa_id', $filters['empresa_id']);
        }

        if (isset($filters['activo']) && $filters['activo'] !== '') {
            $query->where('u.activo', $filters['activo'] == '1' ? 1 : 0);
        }

        if (!empty($filters['rol'])) {
            $query->where('u.rol', $filters['rol']);
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . $filters['buscar'] . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('u.usuario', 'like', $buscar)
                  ->orWhere('u.dni', 'like', $buscar)
                  ->orWhere('u.cod_personal', 'like', $buscar)
                  ->orWhere('u.nom_trabajador', 'like', $buscar)
                  ->orWhere('u.ape_paterno', 'like', $buscar)
                  ->orWhere('u.ape_materno', 'like', $buscar);
            });
        }

        $porPagina = max(1, min(200, (int) ($filters['por_pagina'] ?? 15)));
        $pagina    = max(1, (int) ($filters['pagina'] ?? 1));

        $paginado = $query->orderBy('u.usuario')->paginate($porPagina, ['*'], 'page', $pagina);

        return [
            'items' => $paginado->items(),
            'meta'  => [
                'total'         => $paginado->total(),
                'pagina'        => $paginado->currentPage(),
                'por_pagina'    => $paginado->perPage(),
                'ultima_pagina' => $paginado->lastPage(),
            ],
        ];
    }

    /**
     * Crea un usuario con password hasheado.
     * Siempre se marca debe_cambiar_password = true para que el usuario
     * elija su propia contraseña en el primer login.
     */
    public function crear(array $data): UsuarioIntranet
    {
        $data['password_hash']        = Hash::make($data['password']);
        $data['debe_cambiar_password'] = true;
        unset($data['password']);

        return UsuarioIntranet::create($data);
    }

    /**
     * Edita datos de un usuario (sin cambiar contraseña).
     */
    public function editar(int $id, array $data): UsuarioIntranet
    {
        unset($data['password'], $data['password_hash']);

        $usuario = UsuarioIntranet::findOrFail($id);
        $usuario->update($data);

        return $usuario->fresh();
    }

    /**
     * Cambia la contraseña de un usuario.
     */
    public function cambiarPassword(int $id, string $nuevaPassword): bool
    {
        return DB::table('USUARIOS_INTRANET')
            ->where('id', $id)
            ->update(['password_hash' => Hash::make($nuevaPassword)]) > 0;
    }

    /**
     * Alterna el estado activo/inactivo de un usuario.
     */
    public function toggleActivo(int $id): UsuarioIntranet
    {
        $usuario = UsuarioIntranet::findOrFail($id);
        $usuario->update(['activo' => !$usuario->activo]);

        return $usuario->fresh();
    }
}
