<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ErpService
{
    /**
     * Datos del trabajador desde SP_INTRANET_GET_TRABAJADOR (vive en cada BD del ERP).
     */
    public function getTrabajador(string $dbName, string $codPersonal): array|null
    {
        Log::debug('[ERP] getTrabajador', ['db_name' => $dbName, 'cod_personal' => $codPersonal]);

        try {
            $rows = DB::select(
                "EXEC [{$dbName}].dbo.SP_INTRANET_GET_TRABAJADOR @cod_personal = ?",
                [$codPersonal]
            );

            Log::debug('[ERP] getTrabajador resultado', ['filas' => count($rows)]);

            return $rows ? (array) $rows[0] : null;
        } catch (\Exception $e) {
            Log::error('[ERP] getTrabajador error', [
                'db_name'      => $dbName,
                'cod_personal' => $codPersonal,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Períodos disponibles con boleta para el trabajador.
     * Consulta PLA_MOVI_MES en la BD del ERP vía nombre de 3 partes.
     *
     * @return array<int, array{ANO_PROCESO:string, MES_PROCESO:string, periodo_clave:string}>
     */
    public function getPeriodos(string $dbName, string $codEmpresaErp, string $codPersonal, int $limite = 18): array
    {
        $limite = max(1, min(60, $limite));

        $sql = "SELECT DISTINCT TOP {$limite}
                    CAST(ANO_PROCESO  AS INT) AS ANO_PROCESO,
                    CAST(MES_PROCESO  AS INT) AS MES_PROCESO,
                    CAST(ANO_PROCESO  AS VARCHAR(4))
                        + RIGHT('0' + CAST(MES_PROCESO AS VARCHAR(2)), 2) AS periodo_clave
                FROM [{$dbName}].dbo.PLA_MOVI_MES
                WHERE COD_EMPRESA  = ?
                  AND COD_PERSONAL = ?
                  AND ISNUMERIC(MES_PROCESO) = 1
                  AND CAST(MES_PROCESO AS INT) BETWEEN 1 AND 12
                ORDER BY ANO_PROCESO DESC, MES_PROCESO DESC";

        $rows = DB::select($sql, [$codEmpresaErp, $codPersonal]);

        return array_map(fn($r) => (array) $r, $rows);
    }

    /**
     * Boleta de pago procesada a partir de SP_PLA_BOLETA_REMUNERACION.
     * Se llama al SP con nombre de 3 partes: [{$dbName}].dbo.SP_PLA_BOLETA_REMUNERACION
     * Filtra COPIA='1' (copia del empleado) y agrupa conceptos.
     *
     * @param string $periodo Formato AAAAMM (ej: 202501)
     */
    public function getBoleta(
        string $dbName,
        string $codEmpresaErp,
        string $codPersonal,
        string $periodo
    ): array|null {
        $anio = substr($periodo, 0, 4);
        $mes  = substr($periodo, 4, 2);

        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_PLA_BOLETA_REMUNERACION ?, ?, ?, ?, ?",
            [$codEmpresaErp, $anio, $mes, $codPersonal, $codPersonal]
        );

        if (empty($rows)) {
            return null;
        }

        // Solo copia del empleado (COPIA = '1')
        $filas = array_values(array_filter($rows, fn($r) => ($r->COPIA ?? '') === '1'));

        if (empty($filas)) {
            return null;
        }

        $primera = $filas[0];

        $ingresos    = [];
        $descuentos  = [];
        $aportaciones = [];

        foreach ($filas as $fila) {
            if (isset($fila->INGRESO) && $fila->INGRESO !== null && $fila->INGRESO !== '') {
                $ingresos[] = [
                    'concepto' => trim($fila->INGRESO),
                    'importe'  => (float) ($fila->IMP_INGRESO ?? 0),
                ];
            }
            if (isset($fila->DESCUENTO) && $fila->DESCUENTO !== null && $fila->DESCUENTO !== '') {
                $descuentos[] = [
                    'concepto' => trim($fila->DESCUENTO),
                    'importe'  => (float) ($fila->IMP_DESCUENTO ?? 0),
                ];
            }
            if (isset($fila->APORTACION) && $fila->APORTACION !== null && $fila->APORTACION !== '') {
                $aportaciones[] = [
                    'concepto' => trim($fila->APORTACION),
                    'importe'  => (float) ($fila->IMP_APORTE ?? 0),
                ];
            }
        }

        $totalIngresos   = array_sum(array_column($ingresos,    'importe'));
        $totalDescuentos = array_sum(array_column($descuentos,  'importe'));
        $totalAportes    = array_sum(array_column($aportaciones,'importe'));

        return [
            'cabecera' => [
                'empresa_nombre'      => $primera->DES_NOMBRE_COMERCIAL ?? null,
                'empresa_ruc'         => $primera->NUEVO_RUC ?? null,
                'empresa_direccion'   => $primera->DES_DIRECCION ?? null,
                'empresa_reg_patronal'=> $primera->NUM_REG_PATRONAL ?? null,
                'rango_fecha'         => $primera->RANGO_FECHA ?? null,
                'trabajador'          => $primera->c_nom_personal ?? null,
                'cod_personal'        => $primera->COD_PERSONAL ?? null,
                'dni'                 => $primera->NUM_DOC_IDENTIDAD ?? null,
                'categoria'           => $primera->DES_CATEGORIA ?? null,
                'cargo'               => $primera->CARGO ?? null,
                'area'                => $primera->C_COD_C_COSTOS ?? null,
                'fecha_ingreso'       => $primera->FEC_INGRESO ?? null,
                'tipo_empleado'       => 'EMPLEADO',
                'sueldo'              => $primera->C_SUELDO ?? null,
                'num_dias'            => $primera->NUM_DIAS ?? null,
                'num_dias_mes'        => $primera->NUM_DIAS_MES ?? null,
                'horas_trabajadas'    => $primera->HORAS_TRABAJADAS ?? null,
                'hextra'              => $primera->HEXTRA ?? null,
                'dias_vac'            => $primera->DIAS_VAC ?? null,
                'afp'                 => $primera->AFP ?? $primera->C_NOM_AFP ?? null,
                'per_confianza'       => $primera->IND_PER_CONFIANZA ?? null,
                'cuspp'               => $primera->COD_UNICO_SPP ?? null,
                'cuenta_haberes'      => $primera->C_TPO_PAGO ?? null,
                'periodo_vac'         => $primera->PERIODO ?? null,
            ],
            'ingresos'     => $ingresos,
            'descuentos'   => $descuentos,
            'aportaciones' => $aportaciones,
            'totales' => [
                'ingresos'   => $totalIngresos,
                'descuentos' => $totalDescuentos,
                'aportes'    => $totalAportes,
                'neto'       => $totalIngresos - $totalDescuentos,
            ],
        ];
    }

    /**
     * Vacaciones registradas y validadas por RRHH (desde PLA_VACACIONES_MES_CAB).
     * Retorna resumen anual + historial de sesiones procesadas en planilla.
     */
    public function getVacaciones(string $dbName, string $codPersonal): array|null
    {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_GET_VACACIONES @cod_personal = ?",
            [$codPersonal]
        );

        if (empty($rows)) {
            return null;
        }

        $primera = $rows[0];

        $tipoMap = [
            'VG' => 'Vacaciones Gozadas',
            'VC' => 'Compra de Vacaciones',
            'VN' => 'Vacaciones Normales',
        ];

        $historial = [];
        foreach ($rows as $fila) {
            if (empty($fila->COD_CORR_VAC)) {
                continue;
            }

            $historial[] = [
                'cod_corr_vac'      => $fila->COD_CORR_VAC,
                'fec_inicio'        => $fila->FEC_INICIO
                    ? date('d/m/Y', strtotime($fila->FEC_INICIO)) : '',
                'fec_final'         => $fila->FEC_FIN
                    ? date('d/m/Y', strtotime($fila->FEC_FIN)) : '',
                'num_dias'          => (int) ($fila->NUM_TOT_DIAS ?? 0),
                'importe'           => (float) ($fila->IMP_TOT_VACACION ?? 0),
                'tipo'              => trim($fila->TIP_VACACIONES ?? ''),
                'tipo_label'        => $tipoMap[trim($fila->TIP_VACACIONES ?? '')] ?? ($fila->TIP_VACACIONES ?? '—'),
                'ano_proceso'       => $fila->ANO_PROCESO ?? '',
                'mes_proceso'       => $fila->MES_PROCESO ?? '',
                'cod_periodo'       => $fila->COD_PERIODO ?? '',
                'transf_planilla'   => ($fila->IND_TRANSF_PLAN ?? '') === 'S',
            ];
        }

        return [
            'empleado' => [
                'nombre_completo' => trim($primera->NOMBRE_COMPLETO ?? ''),
                'dni'             => $primera->NUM_DOC_IDENTIDAD ?? '',
                'cargo'           => $primera->DES_CARGO ?? '',
                'area'            => $primera->DES_AREAS ?? '',
                'empresa'         => $primera->EMPRESA ?? '',
                'fecha_ingreso'   => $primera->FEC_INGRESO ?? '',
            ],
            'ano_actual'        => $primera->ANO_ACTUAL ?? (string) date('Y'),
            'dias_gozados_anio' => (int) ($primera->DIAS_GOZADOS_ANIO ?? 0),
            'dias_pendientes'   => (int) ($primera->DIAS_PENDIENTES   ?? 0),
            'historial'         => $historial,
        ];
    }

    /**
     * Solicitudes de vacaciones generadas en la intranet (PLA_SOL_VACACIONES).
     * Incluye el flujo de aprobación: PE → AJ/RJ → AR/RR/CA.
     */
    public function getSolicitudesVac(string $dbName, string $codPersonal): array
    {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_GET_SOLICITUDES_VAC @cod_personal = ?",
            [$codPersonal]
        );

        $estadoMap = [
            'PE' => ['clave' => 'pendiente',       'label' => 'Pendiente'],
            'AJ' => ['clave' => 'aprobado_jefe',   'label' => 'Aprobado por Jefe'],
            'RJ' => ['clave' => 'rechazado_jefe',  'label' => 'Rechazado por Jefe'],
            'AR' => ['clave' => 'aprobado_rh',     'label' => 'Aprobado por RRHH'],
            'RR' => ['clave' => 'rechazado_rh',    'label' => 'Rechazado por RRHH'],
            'CA' => ['clave' => 'cancelado',        'label' => 'Cancelado'],
        ];

        $tipoMap = [
            'VG' => 'Vacaciones Gozadas',
            'VC' => 'Compra de Vacaciones',
        ];

        return array_map(function ($fila) use ($estadoMap, $tipoMap) {
            $estado     = trim($fila->ESTADO_APROBACION ?? 'PE');
            $estadoInfo = $estadoMap[$estado] ?? ['clave' => strtolower($estado), 'label' => $estado];

            return [
                'cod_corr_sol'      => trim($fila->COD_CORR_SOL ?? ''),
                'tipo'              => trim($fila->TIP_VACACIONES ?? ''),
                'tipo_label'        => $tipoMap[trim($fila->TIP_VACACIONES ?? '')] ?? ($fila->TIP_VACACIONES ?? '—'),
                'ano_proceso'       => $fila->ANO_PROCESO ?? '',
                'mes_proceso'       => $fila->MES_PROCESO ?? '',
                'fec_inicio'        => $fila->FEC_INICIO
                    ? date('d/m/Y', strtotime($fila->FEC_INICIO)) : '',
                'fec_final'         => $fila->FEC_FINAL
                    ? date('d/m/Y', strtotime($fila->FEC_FINAL)) : '',
                'num_dias'          => (int) ($fila->NUM_DIAS ?? 0),
                'estado'            => $estadoInfo['clave'],
                'estado_label'      => $estadoInfo['label'],
                'fec_actualiza'     => $fila->FEC_ACTUALIZA ?? null,
                'imp_adelanto'      => $fila->IMP_ADELANTO_VACAC ? (float) $fila->IMP_ADELANTO_VACAC : null,
                'cancelable'        => in_array($estado, ['PE', 'AJ']),
            ];
        }, $rows);
    }

    /**
     * Cancela una solicitud intranet (PE o AJ → CA) en PLA_SOL_VACACIONES.
     */
    public function cancelarSolVac(
        string $dbName,
        string $codEmpresaErp,
        string $codPersonal,
        string $codCorrSol
    ): bool {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_CANCELAR_SOL_VAC @cod_empresa = ?, @cod_personal = ?, @cod_corr_sol = ?",
            [$codEmpresaErp, $codPersonal, $codCorrSol]
        );

        return (int) ($rows[0]->FILAS_AFECTADAS ?? 0) > 0;
    }

    /**
     * Crea una solicitud de vacaciones en PLA_SOL_VACACIONES del ERP
     * vía SP_INTRANET_CREAR_SOL_VAC (Patrón B). Retorna el COD_CORR_SOL generado.
     */
    public function crearSolicitudVac(
        string   $dbName,
        string   $codEmpresaErp,
        string   $codPersonal,
        string   $tipVac,
        int      $anoProceso,
        int      $mesProceso,
        string   $fecInicio,
        string   $fecFinal,
        string   $codUserActual,
        ?string  $codPersonalJefe   = null,
        ?float   $impAdelantoVacac  = null,
        ?float   $descuentoAfp      = null,
        ?string  $periodoVac        = null
    ): string {
        $sql = "
            DECLARE @sol VARCHAR(5), @res INT, @msg VARCHAR(500);
            EXEC [{$dbName}].dbo.SP_INTRANET_CREAR_SOL_VAC
                @COD_PERSONAL       = ?,
                @COD_EMPRESA        = ?,
                @TIP_VACACIONES     = ?,
                @ANO_PROCESO        = ?,
                @MES_PROCESO        = ?,
                @FEC_INICIO         = ?,
                @FEC_FINAL          = ?,
                @COD_USER_ACTUAL    = ?,
                @COD_PERSONAL_JEFE  = ?,
                @IMP_ADELANTO_VACAC = ?,
                @DESCUENTO_AFP      = ?,
                @PERIODO_VAC        = ?,
                @COD_CORR_SOL       = @sol OUTPUT,
                @RESULTADO          = @res OUTPUT,
                @MENSAJE            = @msg OUTPUT;
            SELECT @sol AS cod_corr_sol, @res AS resultado, @msg AS mensaje;
        ";

        $result = DB::selectOne($sql, [
            $codPersonal,
            $codEmpresaErp,
            $tipVac,
            $anoProceso,
            $mesProceso,
            $fecInicio,
            $fecFinal,
            $codUserActual,
            $codPersonalJefe,
            $impAdelantoVacac,
            $descuentoAfp,
            $periodoVac,
        ]);

        if (($result->resultado ?? -1) !== 0) {
            throw new \RuntimeException($result->mensaje ?? 'Error al crear la solicitud.');
        }

        return (string) $result->cod_corr_sol;
    }

    /**
     * Aprueba o rechaza una solicitud de vacaciones.
     * $accion: 'APROBAR_JEFE' | 'RECHAZAR_JEFE' | 'APROBAR_RRHH' | 'RECHAZAR_RRHH'
     */
    public function aprobarVac(
        string  $dbName,
        string  $codEmpresaErp,
        string  $codPersonal,
        int     $codCorrVac,
        string  $accion,
        ?string $obs = null,
        ?string $periodoVac = null
    ): string {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_APROBAR_VAC @cod_empresa = ?, @cod_personal = ?, @cod_corr_vac = ?, @accion = ?, @obs = ?, @periodo_vac = ?",
            [$codEmpresaErp, $codPersonal, $codCorrVac, $accion, $obs, $periodoVac]
        );

        return $rows[0]->NUEVO_ESTADO ?? '';
    }

    /**
     * Cancela una solicitud de vacaciones (PE o AJ → CA).
     */
    public function cancelarVac(
        string $dbName,
        string $codEmpresaErp,
        string $codPersonal,
        int    $codCorrVac
    ): bool {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_CANCELAR_VAC @cod_empresa = ?, @cod_personal = ?, @cod_corr_vac = ?",
            [$codEmpresaErp, $codPersonal, $codCorrVac]
        );

        return (int) ($rows[0]->FILAS_AFECTADAS ?? 0) > 0;
    }

    /**
     * Horarios del trabajador por mes (ej: 202501).
     */
    public function getHorarios(string $dbName, string $codPersonal, string $mes): array|null
    {
        $rows = DB::select(
            'EXEC SP_INTRANET_GET_HORARIOS @db_name = ?, @cod_personal = ?, @mes = ?',
            [$dbName, $codPersonal, $mes]
        );

        return $rows ? array_map(fn($r) => (array) $r, $rows) : null;
    }
}
