<?php

namespace App\Services;

use App\Models\Empresa;
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
     * Vacaciones acumuladas de un trabajador considerando TODAS las empresas
     * del corporativo en las que trabajó (personal que pasó de una razón
     * social a otra dentro del grupo). Por cada empresa se calcula un bloque
     * independiente (años completos en esa empresa × 30 días - días gozados
     * ahí) y se suman los bloques — así el saldo no queda negativo cuando el
     * historial de una empresa antigua se procesó en planilla y el cálculo
     * de "años cumplidos" solo miraba la empresa actual.
     *
     * Recorre EMPRESAS activas de la intranet (no una lista fija de BDs) y
     * usa SP_INTRANET_GET_VACACIONES_GRUPO (busca por DNI, incluye cesados)
     * en cada una. Si el SP aún no está instalado en alguna BD, esa empresa
     * simplemente se omite del consolidado (no bloquea la respuesta).
     */
    public function getVacacionesConsolidado(string $dni): array
    {
        $empresasGrupo = Empresa::activas()->get(['db_name', 'nombre', 'codigo']);

        $detalle = [];

        foreach ($empresasGrupo as $empresaGrupo) {
            try {
                $rows = DB::select(
                    "EXEC [{$empresaGrupo->db_name}].dbo.SP_INTRANET_GET_VACACIONES_GRUPO @num_doc_identidad = ?",
                    [$dni]
                );
            } catch (\Exception $e) {
                Log::warning('[ERP] getVacacionesConsolidado: SP no disponible en esta BD', [
                    'db_name' => $empresaGrupo->db_name,
                    'error'   => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($rows as $fila) {
                $activo   = ($fila->TIP_ESTADO ?? 'AC') === 'AC';
                $fechaFin = $activo ? null : ($fila->FEC_CESADO ?? null);
                $anios    = $this->aniosCompletos($fila->FEC_INGRESO, $fechaFin);

                $diasAcumulados = $anios * 30;
                $diasGozados    = (int) ($fila->DIAS_GOZADOS_TOTAL ?? 0);

                $detalle[] = [
                    'empresa'         => $empresaGrupo->nombre,
                    'cod_personal'    => trim($fila->COD_PERSONAL ?? ''),
                    'activo'          => $activo,
                    'fecha_ingreso'   => $fila->FEC_INGRESO ?? '',
                    'fecha_cesado'    => $fila->FEC_CESADO ?? null,
                    'anios_completos' => $anios,
                    'dias_acumulados' => $diasAcumulados,
                    'dias_gozados'    => $diasGozados,
                    'dias_pendientes' => (int) ($fila->DIAS_PENDIENTES ?? 0),
                    'saldo'           => $diasAcumulados - $diasGozados,
                ];
            }
        }

        $totalAcumulado = array_sum(array_column($detalle, 'dias_acumulados'));
        $totalGozado    = array_sum(array_column($detalle, 'dias_gozados'));
        $totalPendiente = array_sum(array_column($detalle, 'dias_pendientes'));

        return [
            'empresas'        => $detalle,
            'multi_empresa'   => count($detalle) > 1,
            'dias_acumulados' => $totalAcumulado,
            'dias_gozados'    => $totalGozado,
            'dias_pendientes' => $totalPendiente,
            'saldo'           => $totalAcumulado - $totalGozado,
        ];
    }

    /**
     * Años completos cumplidos entre $fechaIngreso y $fechaFin (o hoy si es
     * null). Misma regla que el frontend: solo cuenta un año al cumplirse el
     * aniversario completo, sin prorrateo del año en curso.
     */
    private function aniosCompletos(?string $fechaIngreso, ?string $fechaFin): int
    {
        if (empty($fechaIngreso)) {
            return 0;
        }

        try {
            $ingreso = new \DateTime($fechaIngreso);
            $fin     = $fechaFin ? new \DateTime($fechaFin) : new \DateTime();
        } catch (\Exception) {
            return 0;
        }

        if ($fin < $ingreso) {
            return 0;
        }

        return $ingreso->diff($fin)->y;
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
                'descuento_afp'     => $fila->DESCUENTO_AFP      ? (float) $fila->DESCUENTO_AFP      : null,
                'periodo_vac'       => $fila->PERIODO_VAC        ? trim($fila->PERIODO_VAC)           : null,
                'cancelable'        => in_array($estado, ['PE', 'AJ']) && trim($fila->TIP_VACACIONES ?? '') === 'VC',
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

    /**
     * Registra la visualización de una boleta en LOG_VISUALIZACION_BOLETA
     * vía SP_INTRANET_REGISTRAR_VIS_BOLETA (Pattern B).
     * Devuelve el ID del log generado, o 0 si el SP no está instalado.
     */
    /**
     * Lista de boletas ya visualizadas por el trabajador (para pre-cargar en el frontend).
     * Devuelve array de { periodo, ano_proceso, mes_proceso, tip_boleta, fec_primera_vis, ind_firma_conforme }.
     */
    public function getBoletasVistas(string $dbName, string $codEmpresaErp, string $codPersonal): array
    {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_GET_BOLETAS_VISTAS @COD_PERSONAL = ?, @COD_EMPRESA = ?",
            [$codPersonal, $codEmpresaErp]
        );

        return array_map(fn($r) => [
            'periodo'            => $r->PERIODO,
            'ano_proceso'        => (int) $r->ANO_PROCESO,
            'mes_proceso'        => (int) $r->MES_PROCESO,
            'tip_boleta'         => $r->TIP_BOLETA ?? 'REMUNERACION',
            'fec_primera_vis'    => $r->FEC_PRIMERA_VIS,
            'ind_firma_conforme' => $r->IND_FIRMA_CONFORME ?? 'N',
        ], $rows);
    }

    /**
     * Registra la primera visualización de una boleta (dedup en el SP).
     * Si el período ya fue visto, devuelve el ID existente con primera_vez = false.
     *
     * @return array{id_log:int, primera_vez:bool}
     */
    public function registrarVisBoleta(
        string  $dbName,
        string  $codEmpresaErp,
        string  $codPersonal,
        string  $codUsuario,
        int     $anoProceso,
        int     $mesProceso,
        string  $tipBoleta      = 'REMUNERACION',
        ?string $nomPersonal    = null,
        ?string $dni            = null,
        ?string $cargo          = null,
        ?float  $impIngresos    = null,
        ?float  $impDescuentos  = null,
        ?float  $impNeto        = null,
        ?string $desIp            = null,
        ?string $desDispositivo   = null,
        string  $desPlataforma    = 'WEB',
        ?string $nomEmpresa       = null,
        ?string $correoTrabajador = null,
    ): array {
        $sql = "
            DECLARE @id INT, @pv BIT;
            EXEC [{$dbName}].dbo.SP_INTRANET_REGISTRAR_VIS_BOLETA
                @COD_EMPRESA       = ?,
                @COD_PERSONAL      = ?,
                @COD_USUARIO       = ?,
                @ANO_PROCESO       = ?,
                @MES_PROCESO       = ?,
                @TIP_BOLETA        = ?,
                @NOM_PERSONAL      = ?,
                @DNI               = ?,
                @CARGO             = ?,
                @IMP_INGRESOS      = ?,
                @IMP_DESCUENTOS    = ?,
                @IMP_NETO          = ?,
                @DES_IP            = ?,
                @DES_DISPOSITIVO   = ?,
                @DES_PLATAFORMA    = ?,
                @NOM_EMPRESA       = ?,
                @CORREO_TRABAJADOR = ?,
                @ID_LOG            = @id OUTPUT,
                @ES_PRIMERA_VEZ    = @pv OUTPUT;
            SELECT @id AS ID_LOG, @pv AS ES_PRIMERA_VEZ;
        ";

        $row = DB::selectOne($sql, [
            $codEmpresaErp, $codPersonal, $codUsuario,
            $anoProceso, $mesProceso, $tipBoleta,
            $nomPersonal, $dni, $cargo,
            $impIngresos, $impDescuentos, $impNeto,
            $desIp,
            $desDispositivo !== null ? substr($desDispositivo, 0, 150) : null,
            $desPlataforma,
            $nomEmpresa,
            $correoTrabajador,
        ]);

        return [
            'id_log'      => (int)  ($row->ID_LOG         ?? 0),
            'primera_vez' => (bool) ($row->ES_PRIMERA_VEZ ?? false),
        ];
    }

    /**
     * Configuración de vacaciones del trabajador desde SP_INTRANET_GET_CONFIG_VAC.
     * Devuelve habilitado (bool), limite_dias_anio (int|null) y dias_usados_anio (int).
     *
     * @return array{habilitado:bool, limite_dias_anio:int|null, dias_usados_anio:int}
     */
    public function getConfigVac(string $dbName, string $codPersonal): array
    {
        $rows = DB::select(
            "EXEC [{$dbName}].dbo.SP_INTRANET_GET_CONFIG_VAC @cod_personal = ?, @ano = ?",
            [$codPersonal, (int) date('Y')]
        );

        if (empty($rows)) {
            return ['habilitado' => true, 'limite_dias_anio' => null, 'dias_usados_anio' => 0];
        }

        $row = $rows[0];
        return [
            'habilitado'       => ($row->IND_HABILITA_VACACIONES ?? 'N') === 'S',
            'limite_dias_anio' => ($row->LIMITE_DIAS_VAC_ANIO ?? null) !== null
                ? (int) $row->LIMITE_DIAS_VAC_ANIO
                : null,
            'dias_usados_anio' => (int) ($row->DIAS_USADOS_ANIO ?? 0),
        ];
    }
}
