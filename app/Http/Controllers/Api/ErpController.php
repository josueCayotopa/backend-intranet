<?php

namespace App\Http\Controllers\Api;

use App\Services\ErpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpController extends BaseController
{
    public function __construct(private readonly ErpService $erpService) {}

    /**
     * Resumen para el dashboard: trabajador + última boleta + vacaciones.
     * Una sola llamada en lugar de 3 para reducir latencia.
     */
    public function dashboard(Request $request): JsonResponse
    {
        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);

        // Trabajador (siempre requerido)
        try {
            $trabajador = $this->erpService->getTrabajador($dbName, $codPersonal);
        } catch (\Exception) {
            $trabajador = null;
        }

        // Última boleta: obtener el período más reciente y su neto
        $ultimaBoleta = null;
        try {
            $periodos = $this->erpService->getPeriodos($dbName, $codErp, $codPersonal, 1);
            if (!empty($periodos)) {
                $periodo  = $periodos[0]['periodo_clave']
                    ?? ($periodos[0]['ANO_PROCESO'] . $periodos[0]['MES_PROCESO']);
                $boleta = $this->erpService->getBoleta($dbName, $codErp, $codPersonal, $periodo);
                if ($boleta) {
                    $ultimaBoleta = [
                        'periodo'     => $periodo,
                        'periodo_label' => ($periodos[0]['ANO_PROCESO'] ?? '') . '/' . ($periodos[0]['MES_PROCESO'] ?? ''),
                        'neto'        => $boleta['totales']['neto'],
                        'ingresos'    => $boleta['totales']['ingresos'],
                        'descuentos'  => $boleta['totales']['descuentos'],
                    ];
                }
            }
        } catch (\Exception) {
            $ultimaBoleta = null;
        }

        // Vacaciones (SP puede no estar creado aún — no bloquea)
        $vacaciones = null;
        try {
            $vac = $this->erpService->getVacaciones($dbName, $codPersonal);
            if ($vac) {
                $vacaciones = $vac;
            }
        } catch (\Exception) {
            $vacaciones = null;
        }

        return $this->success([
            'trabajador'   => $trabajador,
            'ultima_boleta' => $ultimaBoleta,
            'vacaciones'   => $vacaciones,
        ], 'Dashboard cargado.');
    }

    /**
     * Datos del trabajador autenticado desde el ERP.
     */
    public function trabajador(Request $request): JsonResponse
    {
        [$dbName, $codPersonal] = $this->resolverContexto($request);

        try {
            $data = $this->erpService->getTrabajador($dbName, $codPersonal);

            if ($data === null) {
                return $this->error(
                    "Trabajador no encontrado en el ERP (db={$dbName}, cod={$codPersonal}).",
                    404
                );
            }

            return $this->success($data, 'Datos del trabajador obtenidos.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar el ERP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Crea una solicitud de vacaciones en el ERP vía SP_INTRANET_CREAR_SOL_VAC.
     */
    public function crearSolicitudVac(Request $request): JsonResponse
    {
        try {
            $v = $request->validate([
                'tipo'               => 'required|in:VG,VC',
                'ano_proceso'        => 'required|integer|min:2000|max:2100',
                'mes_proceso'        => 'required|integer|min:1|max:12',
                'fec_inicio'         => 'required|date',
                'fec_final'          => 'required|date|after_or_equal:fec_inicio',
                'imp_adelanto_vacac' => 'nullable|numeric|min:0',
                'descuento_afp'      => 'nullable|numeric|min:0',
                'periodo_vac'        => 'nullable|string|max:100',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);
        $user          = $request->user();
        $codUserActual = $user->usuario ?? $codPersonal;

        // El jefe se lee del perfil del usuario autenticado.
        // cod_personal_jefe almacena el 'usuario' (login intranet) del jefe,
        // no el cod_personal del ERP, para que sea consistente entre bases de datos.
        $codPersonalJefe = $user->cod_personal_jefe ?? null;

        $numDias = (int) ((strtotime($v['fec_final']) - strtotime($v['fec_inicio'])) / 86400) + 1;

        // Verificar habilitación y límite anual antes de insertar
        try {
            $config = $this->erpService->getConfigVac($dbName, $codPersonal);
            if (!$config['habilitado']) {
                return $this->error(
                    'No tienes habilitadas las solicitudes de vacaciones. Contacta a RRHH.',
                    403
                );
            }
            if ($config['limite_dias_anio'] !== null) {
                $disponibles = max(0, $config['limite_dias_anio'] - $config['dias_usados_anio']);
                if ($numDias > $disponibles) {
                    return $this->error(
                        "Excedes el límite anual de {$config['limite_dias_anio']} días. "
                        . "Días disponibles este año: {$disponibles}.",
                        422
                    );
                }
            }
        } catch (QueryException) {
            // SP no instalado aún — omitir la validación
        }

        try {
            $codCorrSol = $this->erpService->crearSolicitudVac(
                $dbName,
                $codErp,
                $codPersonal,
                $v['tipo'],
                (int) $v['ano_proceso'],
                (int) $v['mes_proceso'],
                $v['fec_inicio'],
                $v['fec_final'],
                $codUserActual,
                $codPersonalJefe,
                isset($v['imp_adelanto_vacac']) ? (float) $v['imp_adelanto_vacac'] : null,
                isset($v['descuento_afp'])      ? (float) $v['descuento_afp']      : null,
                $v['periodo_vac'] ?? null
            );

            return $this->success(
                ['cod_corr_sol' => $codCorrSol, 'num_dias' => $numDias],
                'Solicitud registrada correctamente.',
                201
            );
        } catch (QueryException $e) {
            return $this->error('Error al registrar: ' . $e->getMessage(), 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Aprueba o rechaza una solicitud. $accion viene en el body.
     * Acciones: APROBAR_JEFE | RECHAZAR_JEFE | APROBAR_RRHH | RECHAZAR_RRHH
     */
    public function aprobarVac(Request $request, int $codCorrVac): JsonResponse
    {
        try {
            $v = $request->validate([
                'accion'       => 'required|in:APROBAR_JEFE,RECHAZAR_JEFE,APROBAR_RRHH,RECHAZAR_RRHH',
                'cod_personal' => 'required|string|max:20',
                'obs'          => 'nullable|string|max:500',
                'periodo_vac'  => 'nullable|string|max:100',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        [$dbName, , $codErp] = $this->resolverContexto($request);

        // Verificar permiso según rol
        $rol = $request->user()->rol ?? 'EMPLEADO';
        if (str_contains($v['accion'], 'JEFE') && !in_array($rol, ['JEFE', 'ADMIN'])) {
            return $this->error('No tienes permiso para aprobar como Jefe.', 403);
        }
        if (str_contains($v['accion'], 'RRHH') && !in_array($rol, ['RRHH', 'ADMIN'])) {
            return $this->error('No tienes permiso para aprobar como RRHH.', 403);
        }

        try {
            $nuevoEstado = $this->erpService->aprobarVac(
                $dbName, $codErp,
                $v['cod_personal'],
                $codCorrVac,
                $v['accion'],
                $v['obs'] ?? null,
                $v['periodo_vac'] ?? null
            );

            $labels = [
                'AJ' => 'Aprobado por Jefe',
                'RJ' => 'Rechazado por Jefe',
                'AR' => 'Aprobado por RRHH',
                'RR' => 'Rechazado por RRHH',
            ];

            return $this->success(
                ['estado' => $nuevoEstado, 'estado_label' => $labels[$nuevoEstado] ?? $nuevoEstado],
                $labels[$nuevoEstado] ?? 'Estado actualizado.'
            );
        } catch (QueryException $e) {
            return $this->error('Error al procesar: ' . $e->getMessage(), 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * El empleado cancela su propia solicitud (PE o AJ → CA).
     */
    public function cancelarVac(Request $request, int $codCorrVac): JsonResponse
    {
        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);

        try {
            $ok = $this->erpService->cancelarVac($dbName, $codErp, $codPersonal, $codCorrVac);

            if (!$ok) {
                return $this->error('No se puede cancelar en el estado actual.', 422);
            }

            return $this->success(null, 'Solicitud cancelada correctamente.');
        } catch (QueryException $e) {
            return $this->error('Error al cancelar: ' . $e->getMessage(), 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Solicitudes de vacaciones intranet del trabajador (PLA_SOL_VACACIONES).
     */
    public function solicitudesVac(Request $request): JsonResponse
    {
        [$dbName, $codPersonal] = $this->resolverContexto($request);

        try {
            $data = $this->erpService->getSolicitudesVac($dbName, $codPersonal);
            return $this->success($data, 'Solicitudes obtenidas.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar solicitudes: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancela una solicitud intranet (PE o AJ → CA) en PLA_SOL_VACACIONES.
     */
    public function cancelarSolVac(Request $request, string $codCorrSol): JsonResponse
    {
        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);

        try {
            $ok = $this->erpService->cancelarSolVac($dbName, $codErp, $codPersonal, $codCorrSol);

            if (!$ok) {
                return $this->error('No se puede cancelar en el estado actual.', 422);
            }

            return $this->success(null, 'Solicitud cancelada correctamente.');
        } catch (QueryException $e) {
            return $this->error('Error al cancelar: ' . $e->getMessage(), 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Períodos disponibles con boleta. Parámetro opcional: ?limite=18
     */
    public function periodos(Request $request): JsonResponse
    {
        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);

        try {
            $limite = (int) $request->query('limite', 18);
            $data   = $this->erpService->getPeriodos($dbName, $codErp, $codPersonal, $limite);

            return $this->success($data, 'Períodos obtenidos.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar períodos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Boleta de pago procesada. Parámetro: ?periodo=202501
     */
    public function boleta(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'periodo' => 'required|string|regex:/^\d{6}$/',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Periodo inválido. Formato esperado: AAAAMM (ej: 202501).', 422, $e->errors());
        }

        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);

        try {
            $data = $this->erpService->getBoleta($dbName, $codErp, $codPersonal, $validated['periodo']);

            if ($data === null) {
                return $this->error('No se encontró boleta para el período indicado.', 404);
            }

            return $this->success($data, 'Boleta obtenida correctamente.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar el ERP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Vacaciones acumuladas y programadas del trabajador autenticado.
     */
    public function vacaciones(Request $request): JsonResponse
    {
        [$dbName, $codPersonal] = $this->resolverContexto($request);

        try {
            $data = $this->erpService->getVacaciones($dbName, $codPersonal);

            if ($data === null) {
                return $this->error('No se encontraron registros de vacaciones.', 404);
            }

            return $this->success($data, 'Vacaciones obtenidas correctamente.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar el ERP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Vacaciones acumuladas del trabajador considerando TODAS las empresas
     * del corporativo (soporte para personal que pasó de una razón social a
     * otra dentro del grupo). Suma el bloque de días ganados por empresa.
     */
    public function vacacionesConsolidado(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->dni)) {
            return $this->error('El usuario no tiene DNI registrado.', 422);
        }

        try {
            $data = $this->erpService->getVacacionesConsolidado($user->dni);
            return $this->success($data, 'Vacaciones consolidadas obtenidas.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar el ERP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Lista de períodos de boleta ya visualizados por el trabajador.
     * El frontend usa este dato para saber si mostrar el modal de "primera vez".
     */
    public function boletasVistas(Request $request): JsonResponse
    {
        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);

        try {
            $data = $this->erpService->getBoletasVistas($dbName, $codErp, $codPersonal);
            return $this->success($data, 'Boletas vistas obtenidas.');
        } catch (QueryException) {
            // SP no instalado — responder vacío, no bloquear
            return $this->success([], 'Sin registros de visualización.');
        }
    }

    /**
     * Registra (o confirma) la primera visualización de una boleta.
     * Deduplicado en el SP: si el período ya fue visto, devuelve el registro
     * existente sin insertar uno nuevo.
     * Body: { periodo: "202507", (opcional) imp_ingresos, imp_descuentos, imp_neto,
     *          nom_personal, dni, cargo }
     */
    public function registrarVisBoleta(Request $request): JsonResponse
    {
        try {
            $v = $request->validate([
                'periodo'       => 'required|string|regex:/^\d{6}$/',
                'nom_personal'  => 'nullable|string|max:200',
                'dni'           => 'nullable|string|max:15',
                'cargo'         => 'nullable|string|max:150',
                'imp_ingresos'  => 'nullable|numeric|min:0',
                'imp_descuentos'=> 'nullable|numeric|min:0',
                'imp_neto'      => 'nullable|numeric',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        [$dbName, $codPersonal, $codErp] = $this->resolverContexto($request);
        $user    = $request->user();
        $empresa = $user->empresa()->firstOrFail();

        try {
            $result = $this->erpService->registrarVisBoleta(
                dbName:           $dbName,
                codEmpresaErp:    $codErp,
                codPersonal:      $codPersonal,
                codUsuario:       $user->usuario ?? $codPersonal,
                anoProceso:       (int) substr($v['periodo'], 0, 4),
                mesProceso:       (int) substr($v['periodo'], 4, 2),
                tipBoleta:        'REMUNERACION',
                nomPersonal:      $v['nom_personal']   ?? null,
                dni:              $v['dni']            ?? null,
                cargo:            $v['cargo']          ?? null,
                impIngresos:      isset($v['imp_ingresos'])   ? (float) $v['imp_ingresos']   : null,
                impDescuentos:    isset($v['imp_descuentos']) ? (float) $v['imp_descuentos'] : null,
                impNeto:          isset($v['imp_neto'])       ? (float) $v['imp_neto']       : null,
                desIp:            $request->ip(),
                desDispositivo:   $request->userAgent(),
                desPlataforma:    'WEB',
                nomEmpresa:       $empresa->nombre ?? null,
                correoTrabajador: $user->email ?? null,
            );
            return $this->success($result, $result['primera_vez'] ? 'Visualización registrada.' : 'Ya registrada anteriormente.');
        } catch (QueryException $e) {
            return $this->error('Error al registrar: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Configuración de vacaciones del trabajador autenticado.
     * Devuelve habilitado (bool), limite_dias_anio (int|null), dias_usados_anio (int).
     * Si el SP no está instalado aún, responde con valores permisivos por defecto.
     */
    public function configVac(Request $request): JsonResponse
    {
        [$dbName, $codPersonal] = $this->resolverContexto($request);

        try {
            $config = $this->erpService->getConfigVac($dbName, $codPersonal);
        } catch (QueryException) {
            $config = ['habilitado' => true, 'limite_dias_anio' => null, 'dias_usados_anio' => 0];
        }

        return $this->success($config, 'Configuración de vacaciones obtenida.');
    }

    /**
     * Horarios del trabajador por mes. Parámetro: ?mes=202501
     */
    public function horarios(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'mes' => 'required|string|regex:/^\d{6}$/',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Mes inválido. Formato esperado: AAAAMM (ej: 202501).', 422, $e->errors());
        }

        [$dbName, $codPersonal] = $this->resolverContexto($request);

        try {
            $data = $this->erpService->getHorarios($dbName, $codPersonal, $validated['mes']);

            if ($data === null) {
                return $this->error('No se encontraron horarios para el mes indicado.', 404);
            }

            return $this->success($data, 'Horarios obtenidos correctamente.');
        } catch (QueryException $e) {
            return $this->error('Error al consultar el ERP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Extrae db_name, cod_personal y cod_erp del usuario autenticado.
     * Siempre consulta empresa desde BD para evitar datos cacheados por Eloquent.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function resolverContexto(Request $request): array
    {
        $user    = $request->user();
        $empresa = $user->empresa()->firstOrFail();

        return [
            $empresa->db_name,
            $user->cod_personal,
            $empresa->cod_erp ?? '0001',
        ];
    }
}
