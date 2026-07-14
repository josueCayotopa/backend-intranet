<?php

namespace App\Services;

use App\Models\SesionIntranet;
use App\Models\UsuarioIntranet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private readonly ErpService $erpService) {}

    /**
     * Autentica usuario, registra sesión y retorna token + datos completos.
     *
     * @return array{token:string, usuario:array, empresa:array}|null
     */
    public function login(string $usuario, string $password, Request $request): ?array
    {
        $row = DB::table('USUARIOS_INTRANET')
            ->whereRaw('LOWER(usuario) = LOWER(?)', [$usuario])
            ->where('activo', 1)
            ->first();

        if (!$row) {
            return null;
        }

        if (!$this->verificarPassword($password, $row->password_hash, $row->id)) {
            return null;
        }

        /** @var UsuarioIntranet $userModel */
        $userModel = UsuarioIntranet::with('empresa')->find($row->id);

        $this->sincronizarNombreDesdeErp($userModel);

        $tokenObj = $userModel->createToken('intranet_token');

        // Registrar sesión con info del cliente
        $ua       = $request->header('User-Agent', '');
        $uaInfo   = $this->parsearUserAgent($ua);
        $ip       = $request->ip();
        $hostname = $this->resolverHostname($ip);

        SesionIntranet::create([
            'usuario_id'        => $userModel->id,
            'token_sanctum_id'  => $tokenObj->accessToken->id,
            'ip_address'        => $ip,
            'hostname'          => $hostname,
            'user_agent'        => $ua,
            'dispositivo'       => $uaInfo['dispositivo'],
            'navegador'         => $uaInfo['navegador'],
            'sistema_operativo' => $uaInfo['sistema_operativo'],
            'fec_login'         => now(),
            'fec_ultimo_acceso' => now(),
            'activo'            => true,
        ]);

        return [
            'token'   => $tokenObj->plainTextToken,
            'usuario' => $this->formatearUsuario($userModel),
            'empresa' => [
                'id'       => $userModel->empresa->id,
                'codigo'   => $userModel->empresa->codigo,
                'nombre'   => $userModel->empresa->nombre,
                'ruc'      => $userModel->empresa->ruc,
                'logo_url' => $userModel->empresa->logo_url,
            ],
        ];
    }

    /**
     * Cierra la sesión actual (revoca solo el token en uso).
     */
    public function logout(UsuarioIntranet $usuario, int $tokenId): void
    {
        SesionIntranet::where('token_sanctum_id', $tokenId)
            ->where('activo', true)
            ->update([
                'activo'            => false,
                'fec_logout'        => now(),
                'fec_ultimo_acceso' => now(),
            ]);

        $usuario->tokens()->where('id', $tokenId)->delete();
    }

    /**
     * Cierra todas las sesiones activas del usuario.
     */
    public function logoutTodos(UsuarioIntranet $usuario): void
    {
        SesionIntranet::where('usuario_id', $usuario->id)
            ->where('activo', true)
            ->update([
                'activo'            => false,
                'fec_logout'        => now(),
                'fec_ultimo_acceso' => now(),
            ]);

        $usuario->tokens()->delete();
    }

    /**
     * Lista las sesiones del usuario (activas primero).
     */
    public function listarSesiones(UsuarioIntranet $usuario, bool $soloActivas = false, ?int $currentTokenId = null): array
    {
        $query = SesionIntranet::where('usuario_id', $usuario->id)
            ->orderBy('activo', 'desc')
            ->orderBy('fec_login', 'desc')
            ->limit(50);

        if ($soloActivas) {
            $query->where('activo', true);
        }

        return $query->get()->map(fn($s) => [
            'id'                => $s->id,
            'ip_address'        => $s->ip_address,
            'hostname'          => $s->hostname,
            'dispositivo'       => $s->dispositivo,
            'navegador'         => $s->navegador,
            'sistema_operativo' => $s->sistema_operativo,
            'fec_login'         => $s->fec_login?->format('d/m/Y H:i:s'),
            'fec_ultimo_acceso' => $s->fec_ultimo_acceso?->format('d/m/Y H:i:s'),
            'fec_logout'        => $s->fec_logout?->format('d/m/Y H:i:s'),
            'activo'            => $s->activo,
            'es_actual'         => $currentTokenId !== null && $s->token_sanctum_id === $currentTokenId,
        ])->toArray();
    }

    /**
     * Cierra una sesión específica (el usuario revoca un dispositivo).
     * Solo puede cerrar sus propias sesiones.
     */
    public function cerrarSesion(UsuarioIntranet $usuario, int $sesionId): bool
    {
        $sesion = SesionIntranet::where('id', $sesionId)
            ->where('usuario_id', $usuario->id)
            ->where('activo', true)
            ->first();

        if (!$sesion) {
            return false;
        }

        if ($sesion->token_sanctum_id) {
            $usuario->tokens()->where('id', $sesion->token_sanctum_id)->delete();
        }

        $sesion->update([
            'activo'            => false,
            'fec_logout'        => now(),
            'fec_ultimo_acceso' => now(),
        ]);

        return true;
    }

    /**
     * Restablece la contraseña al DNI del trabajador y activa el flag de cambio obligatorio.
     * No requiere autenticación — es el flujo de recuperación desde el login.
     *
     * @throws \RuntimeException si el usuario no existe, está inactivo o no tiene DNI registrado
     */
    public function recuperarPassword(string $usuario): void
    {
        $row = DB::table('USUARIOS_INTRANET')
            ->whereRaw('LOWER(usuario) = LOWER(?)', [$usuario])
            ->where('activo', 1)
            ->first();

        if (!$row) {
            throw new \RuntimeException('Usuario no encontrado o inactivo.');
        }

        $dni = $row->dni ?? null;

        if (empty($dni)) {
            throw new \RuntimeException('No se puede restablecer: el usuario no tiene DNI registrado.');
        }

        DB::table('USUARIOS_INTRANET')
            ->where('id', $row->id)
            ->update([
                'password_hash'         => Hash::make($dni),
                'debe_cambiar_password' => true,
                'updated_at'            => now(),
            ]);
    }

    /**
     * Cambia la contraseña del usuario autenticado verificando la actual.
     * Limpia el flag debe_cambiar_password al completarse.
     *
     * @throws \RuntimeException si la contraseña actual es incorrecta
     */
    public function cambiarPassword(UsuarioIntranet $usuario, string $passwordActual, string $passwordNuevo): void
    {
        if (!$this->verificarPassword($passwordActual, $usuario->password_hash, $usuario->id)) {
            throw new \RuntimeException('La contraseña actual es incorrecta.');
        }

        $usuario->update([
            'password_hash'         => Hash::make($passwordNuevo),
            'debe_cambiar_password' => false,
        ]);
    }

    /**
     * Actualiza fec_ultimo_acceso de la sesión activa (llamar desde middleware o ping).
     */
    public function ping(int $tokenId): void
    {
        SesionIntranet::where('token_sanctum_id', $tokenId)
            ->where('activo', true)
            ->update(['fec_ultimo_acceso' => now()]);
    }

    /**
     * Datos completos del usuario formateados para la respuesta.
     */
    public function formatearUsuario(UsuarioIntranet $user): array
    {
        $nombreCompleto = trim(implode(' ', array_filter([
            $user->ape_paterno,
            $user->ape_materno . ',',
            $user->nom_trabajador,
        ])));

        // Fallback si aún no tiene los campos de nombre
        if (rtrim($nombreCompleto, ', ') === '') {
            $nombreCompleto = $user->usuario;
        }

        return [
            'id'                => $user->id,
            'usuario'           => $user->usuario,
            'nombre_completo'   => $nombreCompleto,
            'ape_paterno'       => $user->ape_paterno,
            'ape_materno'       => $user->ape_materno,
            'nom_trabajador'    => $user->nom_trabajador,
            'cod_personal'          => $user->cod_personal,
            'cod_personal_jefe'     => $user->cod_personal_jefe,
            'dni'                   => $user->dni,
            'foto_url'              => $user->foto_url,
            'rol'                   => $user->rol ?? 'EMPLEADO',
            'debe_cambiar_password' => (bool) $user->debe_cambiar_password,
        ];
    }

    /**
     * Si el usuario no tiene nombre en la BD, lo obtiene del ERP y lo guarda.
     * Llama al ERP una sola vez; las veces siguientes los campos ya estarán rellenos.
     */
    public function sincronizarNombreDesdeErp(UsuarioIntranet $userModel): void
    {
        if (!empty($userModel->nom_trabajador)) {
            return;
        }

        if (!$userModel->cod_personal || !$userModel->empresa?->db_name) {
            return;
        }

        try {
            $erp = $this->erpService->getTrabajador(
                $userModel->empresa->db_name,
                $userModel->cod_personal
            );
            if ($erp) {
                $campos = array_filter([
                    'nom_trabajador' => $erp['NOM_TRABAJADOR'] ?? null,
                    'ape_paterno'    => $erp['APE_PATERNO']    ?? null,
                    'ape_materno'    => $erp['APE_MATERNO']    ?? null,
                ]);
                if ($campos) {
                    $userModel->update($campos);
                    $userModel->refresh();
                }
            }
        } catch (\Exception) {
            // ERP no disponible, continuar sin nombre
        }
    }

    // ── Verificación y migración de contraseña ───────────────────────────

    /**
     * Verifica la contraseña soportando 3 formatos heredados y migra a bcrypt automáticamente:
     *   1. bcrypt   → formato normal de Laravel ($2y$...)
     *   2. SHA-256  → 64 hex chars en mayúsculas (HASHBYTES SQL Server)
     *   3. Texto plano → legacy sin hashear
     *
     * En los casos 2 y 3, si la contraseña es correcta se guarda como bcrypt para
     * que los logins siguientes usen el algoritmo seguro.
     */
    private function verificarPassword(string $password, string $hash, int $userId): bool
    {
        $valido = false;

        // 1. bcrypt ($2y$, $2a$, $2b$) — intentar primero; capturar si el hash
        //    empieza con $2 pero no es bcrypt válido (insertado manualmente desde SQL)
        if (str_starts_with($hash, '$2')) {
            try {
                if (Hash::check($password, $hash)) {
                    return true;  // bcrypt válido y contraseña correcta — no migrar
                }
                return false;     // bcrypt válido pero contraseña incorrecta
            } catch (\RuntimeException) {
                // Hash empieza con $2 pero no es bcrypt real → caer a texto plano
            }
        }

        // 2. SHA-256 hex (HASHBYTES SQL Server) → exactamente 64 chars hex
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $hash)) {
            $valido = hash_equals(
                strtolower($hash),
                hash('sha256', $password)
            );
        }
        // 3. Texto plano (legacy) — comparación exacta (case-sensitive)
        else {
            $valido = hash_equals($hash, $password);
        }

        // Migrar a bcrypt silenciosamente (sin forzar cambio de contraseña)
        if ($valido) {
            DB::table('USUARIOS_INTRANET')
                ->where('id', $userId)
                ->update(['password_hash' => Hash::make($password)]);
        }

        return $valido;
    }

    // ── Parser de User-Agent ──────────────────────────────────────────────

    private function parsearUserAgent(string $ua): array
    {
        return [
            'dispositivo'       => $this->detectarDispositivo($ua),
            'navegador'         => $this->detectarNavegador($ua),
            'sistema_operativo' => $this->detectarSO($ua),
        ];
    }

    private function detectarDispositivo(string $ua): string
    {
        if (preg_match('/Mobile|Android.*Mobile|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
            return 'Móvil';
        }
        if (preg_match('/Tablet|iPad|Android(?!.*Mobile)|Kindle|Silk/i', $ua)) {
            return 'Tablet';
        }
        return 'PC';
    }

    private function detectarNavegador(string $ua): string
    {
        if (preg_match('/Edg\/(\d+)/i', $ua, $m)) {
            return 'Edge ' . $m[1];
        }
        if (preg_match('/OPR\/(\d+)|Opera\/(\d+)/i', $ua, $m)) {
            return 'Opera ' . ($m[1] ?: $m[2]);
        }
        if (preg_match('/Chrome\/(\d+)/i', $ua, $m)) {
            return 'Chrome ' . $m[1];
        }
        if (preg_match('/Firefox\/(\d+)/i', $ua, $m)) {
            return 'Firefox ' . $m[1];
        }
        if (preg_match('/Version\/[\d.]+ Safari/i', $ua)) {
            return 'Safari';
        }
        if (preg_match('/MSIE (\d+)|Trident.*rv:(\d+)/i', $ua, $m)) {
            return 'IE ' . ($m[1] ?: $m[2]);
        }
        return 'Desconocido';
    }

    /**
     * Resuelve el hostname a partir de la IP via reverse DNS.
     * Retorna null si no hay PTR o la resolución falla.
     */
    private function resolverHostname(string $ip): ?string
    {
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return 'localhost';
        }

        $host = @gethostbyaddr($ip);

        // gethostbyaddr devuelve la IP original cuando no encuentra PTR
        return ($host && $host !== $ip) ? $host : null;
    }

    private function detectarSO(string $ua): string
    {
        $soMap = [
            '10.0' => 'Windows 10/11',
            '6.3'  => 'Windows 8.1',
            '6.2'  => 'Windows 8',
            '6.1'  => 'Windows 7',
        ];

        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            return $soMap[$m[1]] ?? 'Windows';
        }
        if (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
            return 'macOS ' . str_replace('_', '.', $m[1]);
        }
        if (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
            return 'iOS ' . str_replace('_', '.', $m[1]);
        }
        if (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            return 'Android ' . $m[1];
        }
        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }
        return 'Desconocido';
    }
}
