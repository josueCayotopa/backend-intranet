<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPasswordsDni extends Command
{
    protected $signature   = 'intranet:reset-passwords-dni
                                {--solo-sin-bcrypt : Solo resetear los que NO tienen bcrypt (recomendado)}
                                {--usuario= : Resetear solo un usuario específico}';

    protected $description = 'Resetea las contraseñas de los usuarios al su DNI y activa cambio obligatorio';

    public function handle(): int
    {
        $soloBcrypt = $this->option('solo-sin-bcrypt');
        $usuarioFiltro = $this->option('usuario');

        $query = DB::table('USUARIOS_INTRANET')->where('activo', 1);

        if ($usuarioFiltro) {
            $query->whereRaw('LOWER(usuario) = LOWER(?)', [$usuarioFiltro]);
        }

        $usuarios = $query->get(['id', 'usuario', 'dni', 'password_hash']);

        if ($usuarios->isEmpty()) {
            $this->warn('No se encontraron usuarios activos.');
            return self::SUCCESS;
        }

        $filtrados = $soloBcrypt
            ? $usuarios->filter(fn($u) => !str_starts_with((string)($u->password_hash ?? ''), '$2'))
            : $usuarios;

        if ($filtrados->isEmpty()) {
            $this->info('Todos los usuarios ya tienen contraseña bcrypt. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Usuario', 'DNI', 'Hash actual (primeros 10)'],
            $filtrados->map(fn($u) => [
                $u->id,
                $u->usuario,
                $u->dni ?? '(sin DNI)',
                substr((string)($u->password_hash ?? ''), 0, 10) . '...',
            ])->toArray()
        );

        if (!$this->confirm("¿Resetear contraseña al DNI para {$filtrados->count()} usuario(s)?")) {
            $this->line('Cancelado.');
            return self::SUCCESS;
        }

        $ok = 0;
        $sinDni = [];

        foreach ($filtrados as $u) {
            $dni = trim((string)($u->dni ?? ''));

            if (empty($dni)) {
                $sinDni[] = $u->usuario;
                continue;
            }

            DB::table('USUARIOS_INTRANET')
                ->where('id', $u->id)
                ->update([
                    'password_hash'         => Hash::make($dni),
                    'debe_cambiar_password' => true,
                    'updated_at'            => now(),
                ]);

            $this->line("  ✓ {$u->usuario} → contraseña = DNI ({$dni})");
            $ok++;
        }

        $this->newLine();
        $this->info("Completado: {$ok} usuario(s) reseteado(s).");

        if (!empty($sinDni)) {
            $this->warn('Sin DNI registrado (no se resetearon): ' . implode(', ', $sinDni));
        }

        return self::SUCCESS;
    }
}
