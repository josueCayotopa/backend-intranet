<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\UsuarioIntranet;

class UserService
{
    /**
     * Lista usuarios con filtros opcionales.
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
                'u.dni',
                'u.usuario',
                'u.foto_url',
                'u.activo',
                'u.created_at',
                'u.updated_at'
            );

        if (!empty($filters['empresa_id'])) {
            $query->where('u.empresa_id', $filters['empresa_id']);
        }

        if (!empty($filters['activo']) !== false && isset($filters['activo'])) {
            $query->where('u.activo', $filters['activo']);
        }

        if (!empty($filters['buscar'])) {
            $buscar = '%' . $filters['buscar'] . '%';
            $query->where(function ($q) use ($buscar) {
                $q->where('u.usuario', 'like', $buscar)
                  ->orWhere('u.dni', 'like', $buscar)
                  ->orWhere('u.cod_personal', 'like', $buscar);
            });
        }

        return $query->orderBy('u.usuario')->get()->toArray();
    }

    /**
     * Crea un usuario con password hasheado.
     */
    public function crear(array $data): UsuarioIntranet
    {
        $data['password_hash'] = Hash::make($data['password']);
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
