<?php

namespace App\Http\Controllers\Api;

use App\Services\PermisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permisos (formato de autorización de permisos no sujetos a descuento).
 *
 * Trabajador (su empresa y su código salen de la sesión):
 *   GET    /permisos/motivos                      catálogo del formulario
 *   GET    /permisos                              mis solicitudes
 *   POST   /permisos                              nueva solicitud (multipart, con documentos)
 *   GET    /permisos/{id}                         detalle (para ver e imprimir)
 *   POST   /permisos/{id}/documentos              adjuntar otro documento (pendiente)
 *   DELETE /permisos/documentos/{doc}             quitar un documento (pendiente)
 *   GET    /permisos/documentos/{doc}             ver un documento
 *   POST   /permisos/{id}/cancelar                cancelar (antes de que el jefe decida)
 * Jefe ("Administrador de Sede"):
 *   GET    /equipo/permisos                       solicitudes del personal a cargo (?pendientes=1)
 *   GET    /equipo/permisos/{empresa}/{id}        detalle
 *   GET    /equipo/permisos/{empresa}/{id}/documentos/{doc}
 *   POST   /equipo/permisos/{empresa}/{id}/{autorizar|rechazar}   { obs }
 */
class PermisoController extends BaseController
{
    public function __construct(private readonly PermisoService $servicio) {}

    // ── Trabajador ──────────────────────────────────────────────────────────

    public function motivos(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->motivos($this->empresa($request)));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->misPermisos($this->empresa($request), (string) $request->user()->cod_personal));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $v = $request->validate([
                'id_motivo'             => 'required|integer|min:1',
                'fecha_inicio'          => 'required|date_format:Y-m-d',
                'fecha_fin'             => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
                'hora_inicio'           => 'nullable|date_format:H:i',
                'hora_fin'              => 'nullable|date_format:H:i',
                'des_otro'              => 'nullable|string|max:200',
                'provincia'             => 'nullable|boolean',
                'justificacion'         => 'nullable|string|max:500',
                'documentos'            => 'nullable|array|max:6',
                'documentos.*.archivo'  => 'required|file|mimes:' . implode(',', PermisoService::EXTENSIONES) . '|max:' . PermisoService::TAMANO_MAX_KB,
                'documentos.*.id_tipo'  => 'required|integer|min:1',
                'documentos.*.des_otro' => 'nullable|string|max:200',
            ], [
                'documentos.*.archivo.mimes' => 'Los documentos deben ser PDF o imagen (JPG, PNG).',
                'documentos.*.archivo.max'   => 'Cada documento debe pesar como máximo 10 MB.',
                'fecha_fin.after_or_equal'   => 'La fecha final no puede ser anterior a la inicial.',
            ]);
        } catch (ValidationException $e) {
            return $this->error(collect($e->errors())->flatten()->first() ?? 'Datos inválidos.', 422, $e->errors());
        }

        $user = $request->user();
        if (!$user->cod_personal) {
            return $this->error('Su usuario no tiene código de personal.', 422);
        }

        return $this->ejecutar(fn () => $this->servicio->registrar(
            $this->empresa($request), (string) $user->cod_personal, (string) $user->usuario, $user->firma_url,
            $v, $v['documentos'] ?? []
        ), 'Solicitud enviada a su jefe.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->detalle($this->empresa($request), $id, (string) $request->user()->cod_personal));
    }

    public function agregarDocumento(Request $request, int $id): JsonResponse
    {
        try {
            $v = $request->validate([
                'archivo'  => 'required|file|mimes:' . implode(',', PermisoService::EXTENSIONES) . '|max:' . PermisoService::TAMANO_MAX_KB,
                'id_tipo'  => 'required|integer|min:1',
                'des_otro' => 'nullable|string|max:200',
            ], ['archivo.mimes' => 'El documento debe ser PDF o imagen (JPG, PNG).', 'archivo.max' => 'El documento debe pesar como máximo 10 MB.']);
        } catch (ValidationException $e) {
            return $this->error(collect($e->errors())->flatten()->first() ?? 'Datos inválidos.', 422, $e->errors());
        }

        $user = $request->user();
        return $this->ejecutar(fn () => $this->servicio->guardarDocumento(
            $this->empresa($request), $id, $request->file('archivo'), (int) $v['id_tipo'], $v['des_otro'] ?? null,
            (string) $user->usuario, (string) $user->cod_personal
        ), 'Documento adjuntado.', 201);
    }

    public function quitarDocumento(Request $request, int $doc): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $doc) {
            $this->servicio->quitarDocumento($this->empresa($request), $doc, (string) $request->user()->cod_personal);
            return null;
        }, 'Documento quitado.');
    }

    public function documento(Request $request, int $doc): Response
    {
        return $this->archivo(fn () => $this->servicio->documento($this->empresa($request), $doc, (string) $request->user()->cod_personal));
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        return $this->ejecutar(fn () => $this->servicio->cancelar($this->empresa($request), $id, (string) $user->usuario, (string) $user->cod_personal), 'Solicitud cancelada.');
    }

    // ── Jefe ────────────────────────────────────────────────────────────────

    public function equipo(Request $request): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->delEquipo($request->user()->usuario, $request->boolean('pendientes')));
    }

    public function equipoShow(Request $request, string $empresa, int $id): JsonResponse
    {
        return $this->ejecutar(fn () => $this->servicio->detalle($empresa, $id, null, $request->user()->usuario));
    }

    public function equipoDocumento(Request $request, string $empresa, int $id, int $doc): Response
    {
        return $this->archivo(function () use ($request, $empresa, $id, $doc) {
            // Valida que sea su jefe y obtiene el trabajador; el documento debe ser de esa solicitud.
            $detalle = $this->servicio->detalle($empresa, $id, null, $request->user()->usuario);
            $archivo = $this->servicio->documento($empresa, $doc, $detalle['solicitud']['cod_personal'] ?? '');
            if ($archivo['id_permiso'] !== $id) {
                throw new RuntimeException('El documento no es de esta solicitud.', 404);
            }
            return $archivo;
        });
    }

    public function equipoResolver(Request $request, string $empresa, int $id, string $accion): JsonResponse
    {
        if (!in_array($accion, ['autorizar', 'rechazar'], true)) {
            return $this->error('Acción no válida.', 404);
        }
        $obs = trim((string) $request->input('obs', '')) ?: null;
        if ($accion === 'rechazar' && $obs === null) {
            return $this->error('Indique el motivo del rechazo.', 422);
        }
        if ($obs !== null && mb_strlen($obs) > 500) {
            return $this->error('Las observaciones no pueden superar 500 caracteres.', 422);
        }

        $user = $request->user();
        return $this->ejecutar(fn () => $this->servicio->resolverJefe(
            (string) $user->usuario, $empresa, $id, strtoupper($accion), $obs, $user->firma_url
        ), $accion === 'autorizar' ? 'Permiso autorizado. Pasa a Recursos Humanos.' : 'Permiso rechazado.');
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    /** Código de la empresa del usuario (EMPRESAS.codigo). */
    private function empresa(Request $request): string
    {
        return (string) $request->user()->empresa()->firstOrFail()->codigo;
    }

    /** Errores de negocio del SP (THROW 50xxx) → su código HTTP y mensaje. */
    private function ejecutar(callable $accion, string $mensaje = 'OK', int $status = 200): JsonResponse
    {
        try {
            return $this->success($accion(), $mensaje, $status);
        } catch (RuntimeException $e) {
            $codigo = in_array($e->getCode(), [400, 403, 404, 409, 422], true) ? $e->getCode() : 422;
            return $this->error($e->getMessage(), $codigo);
        } catch (\Throwable $e) {
            Log::error('[PERMISOS] Error', ['error' => $e->getMessage()]);
            return $this->error('No se pudo completar la operación de permisos.', 500);
        }
    }

    /** Devuelve el archivo para verlo en el navegador. */
    private function archivo(callable $obtener): Response
    {
        try {
            $a = $obtener();
            $nombre = str_replace(['"', "\r", "\n"], '', $a['nombre']);
            return response($a['contenido'], 200, [
                'Content-Type'        => $a['mime'],
                'Content-Disposition' => 'inline; filename="' . $nombre . '"',
                'Cache-Control'       => 'private, no-store',
            ]);
        } catch (RuntimeException $e) {
            $codigo = in_array($e->getCode(), [403, 404], true) ? $e->getCode() : 422;
            return $this->error($e->getMessage(), $codigo);
        } catch (\Throwable $e) {
            Log::error('[PERMISOS] Documento', ['error' => $e->getMessage()]);
            return $this->error('No se pudo abrir el documento.', 500);
        }
    }
}
