<?php

namespace App\Http\Controllers\Api;

use App\Services\EvaluacionEquipoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Módulo "Evaluar a mi personal" (jefes).
 *
 * GET  /equipo/resumen                                   ¿es jefe?
 * GET  /equipo/evaluaciones                              personal a cargo + estado de su evaluación
 * GET  /equipo/evaluaciones/{empresa}/{codPersonal}      ficha (?periodo=AAAAMM)
 * PUT  /equipo/evaluaciones/{empresa}/{codPersonal}/{periodo}   guardar sección del jefe
 * GET  /equipo/aprobaciones                               evaluaciones donde soy aprobador
 * GET  /equipo/aprobaciones/{empresa}/{codPersonal}/{periodo}   ficha para el aprobador
 * POST /equipo/aprobaciones/{empresa}/{aprobacionId}      aprobar / aprobar con otro período / rechazar
 * GET  /equipo/vacaciones                                 solicitudes de vacaciones del personal a cargo
 * POST /equipo/vacaciones/{empresa}/{codPersonal}/{codCorrSol}/aprobar|rechazar
 */
class EquipoController extends BaseController
{
    public function __construct(private readonly EvaluacionEquipoService $servicio) {}

    public function resumen(Request $request): JsonResponse
    {
        $user   = $request->user();
        $esJefe = $this->servicio->esJefe($user->usuario, $user->cod_personal, (int) $user->empresa_id);

        $pendientes = 0;
        $permisosPendientes = 0;
        if ($esJefe) {
            try {
                $pendientes = count($this->servicio->solicitudesVacaciones($user->usuario, true));
            } catch (\Throwable) {
                $pendientes = 0; // el contador no debe impedir mostrar el menú
            }
            try {
                $permisosPendientes = count(app(\App\Services\PermisoService::class)->delEquipo($user->usuario, true));
            } catch (\Throwable) {
                $permisosPendientes = 0;
            }
        }

        // Aprobador de evaluaciones: tiene alguna aprobación asignada en alguna empresa.
        $aprobaciones = [];
        try {
            $aprobaciones = $this->servicio->aprobaciones($user->usuario);
        } catch (\Throwable) {
            $aprobaciones = [];
        }

        return $this->success([
            'es_jefe'                 => $esJefe,
            'vacaciones_pendientes'   => $pendientes,
            'permisos_pendientes'     => $permisosPendientes,
            'es_aprobador'            => count($aprobaciones) > 0,
            'aprobaciones_pendientes' => count(array_filter($aprobaciones, fn ($a) => $a['mi_estado'] === 'P')),
        ]);
    }

    /** GET /equipo/aprobaciones?pendientes=1 — evaluaciones donde soy aprobador */
    public function aprobaciones(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->aprobaciones($request->user()->usuario, $request->boolean('pendientes')));
    }

    /** GET /equipo/aprobaciones/{empresa}/{codPersonal}/{periodo} */
    public function aprobacion(Request $request, string $empresa, string $codPersonal, string $periodo): JsonResponse
    {
        if (!preg_match('/^\d{6}$/', $periodo)) {
            return $this->error('El período debe tener el formato AAAAMM.', 422);
        }

        return $this->ejecutar(fn () => $this->servicio->detalleAprobacion($request->user()->usuario, $empresa, $codPersonal, (int) $periodo));
    }

    /**
     * POST /equipo/aprobaciones/{empresa}/{aprobacionId}
     * { accion: APROBAR | OBSER_PERIODO | RECHAZAR, comentario?, recomendacion_final?, justificacion_cambio? }
     */
    public function resolverAprobacion(Request $request, string $empresa, int $aprobacionId): JsonResponse
    {
        try {
            $v = $request->validate([
                'accion'               => ['required', Rule::in(['APROBAR', 'OBSER_PERIODO', 'RECHAZAR'])],
                'comentario'           => 'nullable|required_if:accion,RECHAZAR|string|max:500',
                'recomendacion_final'  => ['nullable', 'required_if:accion,OBSER_PERIODO', Rule::in(['12M', '6M', '3M', '2M', '1M', 'IN', 'NO'])],
                'justificacion_cambio' => 'nullable|required_if:accion,OBSER_PERIODO|string|max:2000',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        return $this->ejecutar(fn () => $this->servicio->resolverAprobacion(
            $request->user()->usuario, $empresa, $aprobacionId, $v['accion'],
            $v['comentario'] ?? null, $v['recomendacion_final'] ?? null, $v['justificacion_cambio'] ?? null
        ), 'Su decisión quedó registrada.');
    }

    /** GET /equipo/vacaciones?pendientes=1 — solicitudes de vacaciones del personal a cargo */
    public function vacaciones(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->solicitudesVacaciones(
            $request->user()->usuario, $request->boolean('pendientes')
        ));
    }

    /** POST /equipo/vacaciones/{empresa}/{codPersonal}/{codCorrSol}/{accion}  accion: aprobar | rechazar  { obs } */
    public function resolverVacaciones(Request $request, string $empresa, string $codPersonal, string $codCorrSol, string $accion): JsonResponse
    {
        $obs = trim((string) $request->input('obs', '')) ?: null;
        if ($accion === 'rechazar' && $obs === null) {
            return $this->error('Indique el motivo del rechazo.', 422);
        }
        if ($obs !== null && mb_strlen($obs) > 500) {
            return $this->error('El motivo no puede superar 500 caracteres.', 422);
        }

        return $this->ejecutar(fn () => $this->servicio->resolverVacaciones(
            $request->user()->usuario, $empresa, $codPersonal, $codCorrSol,
            $accion === 'aprobar' ? 'APROBAR_JEFE' : 'RECHAZAR_JEFE', $obs
        ), $accion === 'aprobar' ? 'Solicitud aprobada. Pasa a Recursos Humanos.' : 'Solicitud rechazada.');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->equipo($request->user()->usuario));
    }

    public function show(Request $request, string $empresa, string $codPersonal): JsonResponse
    {
        $periodo = $request->query('periodo');
        if ($periodo !== null && !preg_match('/^\d{6}$/', (string) $periodo)) {
            return $this->error('El período debe tener el formato AAAAMM.', 422);
        }

        return $this->ejecutar(fn () => $this->servicio->detalle(
            $request->user()->usuario, $empresa, $codPersonal, $periodo !== null ? (int) $periodo : null
        ));
    }

    public function guardar(Request $request, string $empresa, string $codPersonal, string $periodo): JsonResponse
    {
        if (!preg_match('/^\d{6}$/', $periodo)) {
            return $this->error('El período debe tener el formato AAAAMM.', 422);
        }

        try {
            $v = $request->validate([
                'puntajes'               => 'required|array|min:1',
                'puntajes.*.criterio_id' => 'required|integer|distinct',
                'puntajes.*.puntaje'     => 'required|integer|min:0|max:100',
                'puntajes.*.observacion' => 'nullable|string|max:2000',
                'recomendaciones'        => 'nullable|string|max:1000',
                'recomendacion_final'    => ['nullable', Rule::in(['12M', '6M', '3M', '2M', '1M', 'IN', 'NO'])],
                'justificacion'          => 'nullable|string|max:2000',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        return $this->ejecutar(fn () => $this->servicio->guardar(
            $request->user()->usuario, $empresa, $codPersonal, (int) $periodo,
            $v['puntajes'], $v['recomendaciones'] ?? null, $v['recomendacion_final'] ?? null, $v['justificacion'] ?? null
        ), 'Evaluación guardada.');
    }

    /** Errores de negocio del SP (THROW 50xxx) → su código HTTP y mensaje. */
    private function ejecutar(callable $accion, string $mensaje = 'OK'): JsonResponse
    {
        try {
            return $this->success($accion(), $mensaje);
        } catch (RuntimeException $e) {
            $status = in_array($e->getCode(), [400, 403, 404, 409, 422], true) ? $e->getCode() : 422;
            return $this->error($e->getMessage(), $status);
        } catch (\Throwable $e) {
            Log::error('[EQUIPO] Error', ['error' => $e->getMessage()]);
            return $this->error('No se pudo consultar la evaluación.', 500);
        }
    }
}
