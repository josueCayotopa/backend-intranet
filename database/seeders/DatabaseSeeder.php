<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Empresa de prueba ──────────────────────────────────────────────
        $empresaId = DB::table('EMPRESAS')->insertGetId([
            'codigo'      => 'CLL001',
            'nombre'      => 'CLINICA LA LUZ SAC',
            'ruc'         => '20100070291',
            'logo_url'    => null,
            'db_host'     => '45.71.34.172',
            'db_port'     => 1433,
            'db_name'     => 'BDV0004',
            'cod_erp'     => '0001',          // COD_EMPRESA siempre es 0001 en cada BD del ERP
            'db_user'     => 'USER_JOSUE',
            'db_password' => Crypt::encryptString('Verano2025'),
            'activo'      => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // ── Usuario de prueba ──────────────────────────────────────────────
        DB::table('USUARIOS_INTRANET')->insert([
            'empresa_id'   => $empresaId,
            'cod_personal' => '000101',
            'dni'          => '00000001',
            'usuario'      => 'admin',
            'password_hash'=> Hash::make('admin123'),
            'foto_url'     => null,
            'activo'       => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->command->info("Empresa creada: CLINICA LA LUZ SAC (id={$empresaId})");
        $this->command->info('Usuario creado: admin / admin123');
    }
}
