<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPasswordsDniSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = DB::table('USUARIOS_INTRANET')
            ->where('activo', 1)
            ->whereNotNull('dni')
            ->where('dni', '<>', '')
            ->get(['id', 'usuario', 'dni']);

        foreach ($usuarios as $u) {
            DB::table('USUARIOS_INTRANET')
                ->where('id', $u->id)
                ->update([
                    'password_hash' => Hash::make($u->dni),
                    'updated_at'    => now(),
                ]);

            $this->command->info("✓ {$u->usuario} → contraseña = {$u->dni}");
        }

        $this->command->info("Listo: {$usuarios->count()} usuario(s) actualizados.");
    }
}
