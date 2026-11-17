<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

/**
 * "Evaluar a mi personal": el jefe califica la sección del jefe de las
 * evaluaciones para renovación de contrato que RR.HH. asignó a su personal.
 *
 * Usa los SP multiempresa del hub INTRANETCLL (los mismos de la app de RR.HH.):
 *   SP_RRHH_JEFE_EQUIPO              personal a cargo y estado de su evaluación
 *   SP_RRHH_JEFE_EVALUACION_DETALLE  ficha (valida que el trabajador esté a cargo)
 *   SP_RRHH_JEFE_EVALUACION_GUARDAR  guarda solo los criterios del jefe
 * "A cargo" = USUARIOS_INTRANET.COD_PERSONAL_JEFE del trabajador es el login
 * del jefe (o su cod_personal en la misma empresa).
 */
class EvaluacionEquipoService
{
    /** ¿El usuario tiene personal a su cargo en la intranet? */
    public function esJefe(string $usuario, ?string $codPersonal, int $empresaId): bool
    {
        return DB::table('USUARIOS_INTRANET')
            ->where('activo', 1)
            ->where(function ($q) use ($usuario, $codPersonal, $empresaId) {
                $q->where('COD_PERSONAL_JEFE', $usuario);
                if ($codPersonal) {
                    $q->orWhere(fn ($q2) => $q2->where('COD_PERSONAL_JEFE', $codPersonal)->where('empresa_id', $empresaId));
                }
            })
            ->exists();
    }

    public function equipo(string $usuario): array
    {
        return $this->filas($this->ejecutar('SP_RRHH_JEFE_EQUIPO @usuario = ?', [$usuario])[0] ?? []);
    }

    public function detalle(string $usuario, string $empresa, string $codPersonal, ?int $periodo): array
    {
        $rs = $this->ejecutar(
            'SP_RRHH_JEFE_EVALUACION_DETALLE @usuario = ?, @empresa = ?, @cod_personal = ?, @periodo = ?',
            [$usuario, $empresa, $codPersonal, $periodo]
        );

        return [
            'cabecera'     => $this->filas($rs[0] ?? [])[0] ?? null,
            'criterios'    => $this->filas($rs[1] ?? []),
            'aprobaciones' => $this->filas($rs[2] ?? []),
            'periodos'     => $this->filas($rs[3] ?? []),
            'contratos'    => $this->filas($rs[4] ?? []),
        ];
    }

    /** @param array<int, array{criterio_id:int, puntaje:int, observacion:?string}> $puntajes */
    public function guardar(string $usuario, string $empresa, string $codPersonal, int $periodo, array $puntajes, ?string $recomendaciones, ?string $recomendacionFinal, ?string $justificacion): array
    {
        $rs = $this->ejecutar(
            'SP_RRHH_JEFE_EVALUACION_GUARDAR @usuario = ?, @empresa = ?, @cod_personal = ?, @periodo = ?, @puntajes = ?, @recomendaciones = ?, @recomendacion_final = ?, @justificacion = ?',
            [$usuario, $empresa, $codPersonal, $periodo, json_encode(array_values($puntajes), JSON_UNESCAPED_UNICODE), $recomendaciones, $recomendacionFinal, $justificacion]
        );

        return $this->filas($rs[0] ?? [])[0] ?? [];
    }

    // ── Aprobador de evaluaciones (SP_RRHH_APROBADOR_* + SP_RRHH_EVALUACION_APROBAR) ─

    /** Evaluaciones donde el usuario es aprobador (users.usuario = su login en cada empresa). */
    public function aprobaciones(string $usuario, bool $soloPendientes = false): array
    {
        $filas = $this->filas($this->ejecutar(
            'SP_RRHH_APROBADOR_EVALUACIONES @usuario = ?, @solo_pendientes = ?',
            [$usuario, $soloPendientes ? 1 : 0]
        )[0] ?? []);

        foreach ($filas as &$f) {
            $f['aprobadores'] = json_decode((string) ($f['aprobadores'] ?? '[]'), true) ?: [];
        }

        return $filas;
    }

    /** Ficha para el aprobador (valida que sea aprobador del período). */
    public function detalleAprobacion(string $usuario, string $empresa, string $codPersonal, int $periodo): array
    {
        $rs = $this->ejecutar(
            'SP_RRHH_APROBADOR_EVALUACION_DETALLE @usuario = ?, @empresa = ?, @cod_personal = ?, @periodo = ?',
            [$usuario, $empresa, $codPersonal, $periodo]
        );

        return [
            'cabecera'     => $this->filas($rs[0] ?? [])[0] ?? null,
            'criterios'    => $this->filas($rs[1] ?? []),
            'aprobaciones' => $this->filas($rs[2] ?? []),
            'periodos'     => $this->filas($rs[3] ?? []),
            'contratos'    => $this->filas($rs[4] ?? []),
        ];
    }

    /**
     * Registra la decisión del aprobador. El SP valida que la aprobación sea suya
     * y que RR.HH. y el jefe ya hayan calificado.
     * $accion: APROBAR | OBSER_PERIODO (aprobar con otro período) | RECHAZAR
     */
    public function resolverAprobacion(string $usuario, string $empresa, int $aprobacionId, string $accion, ?string $comentario, ?string $recomendacion, ?string $justificacion): array
    {
        return $this->filas($this->ejecutar(
            'SP_RRHH_EVALUACION_APROBAR @empresa = ?, @aprobacion_id = ?, @cod_usuario = ?, @accion = ?, @comentario = ?, @recomendacion_final = ?, @justificacion_cambio = ?',
            [$empresa, $aprobacionId, $usuario, $accion, $comentario, $recomendacion, $justificacion]
        )[0] ?? [])[0] ?? [];
    }

    // ── Vacaciones del personal a cargo (SP_RRHH_JEFE_SOL_VAC_*) ─────────

    /** Solicitudes de vacaciones (goce / compra) del personal a cargo, con su saldo. */
    public function solicitudesVacaciones(string $usuario, bool $soloPendientes = false): array
    {
        return $this->filas($this->ejecutar(
            'SP_RRHH_JEFE_SOL_VAC_LISTAR @usuario = ?, @solo_pendientes = ?',
            [$usuario, $soloPendientes ? 1 : 0]
        )[0] ?? []);
    }

    /** $accion: APROBAR_JEFE | RECHAZAR_JEFE */
    public function resolverVacaciones(string $usuario, string $empresa, string $codPersonal, string $codCorrSol, string $accion, ?string $obs): array
    {
        return $this->filas($this->ejecutar(
            'SP_RRHH_JEFE_SOL_VAC_RESOLVER @usuario = ?, @empresa = ?, @cod_personal = ?, @cod_corr_sol = ?, @accion = ?, @obs = ?',
            [$usuario, $empresa, $codPersonal, $codCorrSol, $accion, $obs]
        )[0] ?? [])[0] ?? [];
    }

    /**
     * Ejecuta un SP del hub y devuelve TODOS sus result sets.
     * Los THROW 50000+HTTP del SP llegan como RuntimeException con ese código HTTP.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function ejecutar(string $llamada, array $params): array
    {
        $pdo = DB::connection()->getPdo();

        try {
            $stmt = $pdo->prepare('SET NOCOUNT ON; EXEC dbo.' . $llamada);
            foreach (array_values($params) as $i => $v) {
                $stmt->bindValue($i + 1, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
            }
            $stmt->execute();

            $resultados = [];
            do {
                if ($stmt->columnCount() > 0) {
                    $resultados[] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } while ($stmt->nextRowset());

            return $resultados;
        } catch (PDOException $e) {
            $numero = (int) ($e->errorInfo[1] ?? 0);
            if ($numero >= 50000 && $numero < 51000) {
                $mensaje = preg_replace('/^(\[[^\]]*\])+\s*/', '', (string) ($e->errorInfo[2] ?? $e->getMessage()));
                throw new RuntimeException(trim($mensaje), $numero - 50000, $e);
            }
            throw $e;
        }
    }

    /** Columnas en minúsculas, textos sin espacios finales. */
    private function filas(array $filas): array
    {
        return array_map(function ($f) {
            $salida = [];
            foreach ($f as $k => $v) {
                $salida[strtolower($k)] = is_string($v) ? rtrim($v) : $v;
            }
            return $salida;
        }, $filas);
    }
}
