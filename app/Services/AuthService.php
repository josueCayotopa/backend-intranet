<?php

namespace App\Services;

use App\Models\UsuarioIntranet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\QueryException;

class AuthService
{
    /**
     * Autentica usuario, genera token Sanctum y retorna datos completos.
     *
     * @return array{token: string, usuario: array, empresa: array}|null
     */
    public function login(string $usuario, string $password): ?array
    {
        // Buscar con DB::table (no Eloquent) según arquitectura
        // No se filtra por empresa_id: el usuario es único en el sistema
        $row = DB::table('USUARIOS_INTRANET')
            ->where('usuario', $usuario)
            ->where('activo', 1)
            ->first();  
        if (!$row) {
            return null;
        }

        if (!Hash::check($password, $row->password_hash)) {
            return null;
        }

        // Cargar Eloquent solo para crear el token Sanctum
        /** @var UsuarioIntranet $userModel */
        $userModel = UsuarioIntranet::with('empresa')->find($row->id);

        // Revocar tokens previos (optional: uncomment para sesión única)
        // $userModel->tokens()->delete();

        $token = $userModel->createToken('intranet_token')->plainTextToken;

        return [
            'token'   => $token,
            'usuario' => [
                'id'           => $userModel->id,
                'usuario'      => $userModel->usuario,
                'cod_personal' => $userModel->cod_personal,
                'dni'          => $userModel->dni,
                'foto_url'     => $userModel->foto_url,
            ],
            'empresa' => [
                'id'      => $userModel->empresa->id,
                'codigo'  => $userModel->empresa->codigo,
                'nombre'  => $userModel->empresa->nombre,
                'ruc'     => $userModel->empresa->ruc,
                'logo_url'=> $userModel->empresa->logo_url,
            ],
        ];
    }

    /**
     * Revoca todos los tokens del usuario autenticado.
     */
    public function logout(UsuarioIntranet $usuario): void
    {
        $usuario->tokens()->delete();
    }
}
