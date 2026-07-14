<?php

namespace App\Services;

use App\Models\Empresa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

class EmpresaService
{
    /**
     * Lista empresas sin exponer credenciales de BD.
     */
    public function listar(bool $soloActivas = false): array
    {
        $query = DB::table('EMPRESAS')
            ->select('id', 'codigo', 'nombre', 'ruc', 'cod_erp', 'logo_url', 'activo', 'created_at', 'updated_at');

        if ($soloActivas) {
            $query->where('activo', 1);
        }

        return $query->orderBy('nombre')->get()->toArray();
    }

    /**
     * Crea una empresa con db_password encriptado.
     */
    public function crear(array $data): Empresa
    {
        return Empresa::create($data);
    }

    /**
     * Actualiza datos de una empresa.
     */
    public function actualizar(int $id, array $data): Empresa
    {
        $empresa = Empresa::findOrFail($id);
        $empresa->update($data);

        return $empresa->fresh();
    }

    /**
     * Alterna el estado activo/inactivo de una empresa.
     */
    public function toggleActivo(int $id): Empresa
    {
        $empresa = Empresa::findOrFail($id);
        $empresa->update(['activo' => !$empresa->activo]);

        return $empresa->fresh();
    }

    /**
     * Prueba la conexión a la BD del ERP de la empresa usando sus credenciales.
     *
     * @throws \RuntimeException cuando la conexión falla
     */
    public function probarConexion(int $id): bool
    {
        $empresa = Empresa::findOrFail($id);

        $configKey = 'database.connections.empresa_temp_' . $id;

        config([$configKey => [
            'driver'                  => 'sqlsrv',
            'host'                    => $empresa->db_host,
            'port'                    => $empresa->db_port ?? 1433,
            'database'                => $empresa->db_name,
            'username'                => $empresa->getRawOriginal('db_user'),
            'password'                => $empresa->db_password, // decrypted via accessor
            'charset'                 => 'utf8',
            'prefix'                  => '',
            'prefix_indexes'          => true,
            'encrypt'                 => env('DB_ENCRYPT', 'false'),
            'trust_server_certificate'=> env('DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ]]);

        try {
            $connectionName = 'empresa_temp_' . $id;
            DB::connection($connectionName)->getPdo();
            DB::purge($connectionName);

            return true;
        } catch (\Exception $e) {
            DB::purge('empresa_temp_' . $id);
            throw new \RuntimeException('No se pudo conectar: ' . $e->getMessage());
        }
    }
}
