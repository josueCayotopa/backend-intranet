<?php

namespace App\Services;

use App\Services\Concerns\EjecutaSpHub;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Permisos (FORMATO DE AUTORIZACIÓN DE PERMISOS NO SUJETOS A DESCUENTO).
 *
 * Flujo: el trabajador solicita en la intranet → su jefe ("Administrador de
 * Sede" del formato) autoriza o rechaza → RR.HH. aprueba en la web de RR.HH.
 * Todo con los SP multiempresa del hub (SIS_RRHH/database/sp/permisos):
 *   SP_RRHH_PERMISO_MOTIVOS · SP_RRHH_MIS_PERMISOS · SP_RRHH_PERMISO_REGISTRAR
 *   SP_RRHH_PERMISO_DETALLE · SP_RRHH_PERMISO_DOC_GUARDAR / _OBTENER / _QUITAR
 *   SP_RRHH_PERMISO_RESOLVER (cancelar) · SP_RRHH_JEFE_PERMISOS_LISTAR
 *   SP_RRHH_JEFE_PERMISO_RESOLVER
 */
class PermisoService
{
    use EjecutaSpHub;

    public const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png'];
    public const TAMANO_MAX_KB = 10240; // el SP también limita a 10 MB

    // ── Trabajador ──────────────────────────────────────────────────────────

    /** Catálogo del formulario: motivos, documentos por motivo y tipos de documento. */
    public function motivos(string $empresa): array
    {
        $rs = $this->ejecutar('SP_RRHH_PERMISO_MOTIVOS @empresa = ?', [$empresa]);

        return [
            'motivos'          => $this->filas($rs[0] ?? []),
            'documentos'       => $this->filas($rs[1] ?? []),
            'tipos_documento'  => $this->filas($rs[2] ?? []),
        ];
    }

    public function misPermisos(string $empresa, string $codPersonal): array
    {
        return $this->filas($this->ejecutar('SP_RRHH_MIS_PERMISOS @empresa = ?, @cod_personal = ?', [$empresa, $codPersonal])[0] ?? []);
    }

    /**
     * Registra la solicitud y sus documentos. Si un documento falla, la
     * solicitud recién creada se cancela para no dejarla a medias.
     *
     * @param array<int, array{archivo: UploadedFile, id_tipo: int, des_otro: ?string}> $documentos
     */
    public function registrar(string $empresa, string $codPersonal, string $usuario, ?string $firmaUrl, array $datos, array $documentos): array
    {
        $fila = $this->filas($this->ejecutar(
            'SP_RRHH_PERMISO_REGISTRAR @empresa = ?, @cod_personal = ?, @id_motivo = ?, @fecha_inicio = ?, @fecha_fin = ?,
                @hora_inicio = ?, @hora_fin = ?, @des_otro = ?, @provincia = ?, @justificacion = ?, @usuario = ?, @origen = ?, @firma_url = ?',
            [$empresa, $codPersonal, (int) $datos['id_motivo'], $datos['fecha_inicio'], $datos['fecha_fin'],
             $datos['hora_inicio'] ?? null, $datos['hora_fin'] ?? null, $datos['des_otro'] ?? null,
             !empty($datos['provincia']) ? 1 : 0, $datos['justificacion'] ?? null, $usuario, 'INTRANET', $firmaUrl]
        )[0] ?? [])[0] ?? [];

        $id = (int) ($fila['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('No se pudo registrar la solicitud.', 500);
        }

        try {
            foreach ($documentos as $doc) {
                $this->guardarDocumento($empresa, $id, $doc['archivo'], (int) $doc['id_tipo'], $doc['des_otro'] ?? null, $usuario, $codPersonal);
            }
        } catch (\Throwable $e) {
            try {
                $this->cancelar($empresa, $id, $usuario, $codPersonal);
            } catch (\Throwable) {
                // si no se puede cancelar, RR.HH. la verá sin el documento
            }
            throw $e;
        }

        return $fila;
    }

    public function guardarDocumento(string $empresa, int $idPermiso, UploadedFile $archivo, int $idTipo, ?string $desOtro, string $usuario, ?string $codPersonal): array
    {
        return ($this->filas($this->ejecutar(
            'SP_RRHH_PERMISO_DOC_GUARDAR @empresa = ?, @id_permiso = ?, @id_tipo = ?, @nombre = ?, @mime = ?, @base64 = ?, @des_otro = ?, @usuario = ?, @cod_personal = ?',
            [$empresa, $idPermiso, $idTipo, mb_substr($archivo->getClientOriginalName(), 0, 255),
             $archivo->getMimeType() ?: 'application/octet-stream', base64_encode((string) file_get_contents($archivo->getRealPath())),
             $desOtro, $usuario, $codPersonal]
        )[0] ?? []))[0] ?? [];
    }

    /** Detalle para el trabajador ($codPersonal) o para su jefe ($jefe). */
    public function detalle(string $empresa, int $id, ?string $codPersonal = null, ?string $jefe = null): array
    {
        $rs = $this->ejecutar('SP_RRHH_PERMISO_DETALLE @empresa = ?, @id = ?, @cod_personal = ?, @jefe = ?', [$empresa, $id, $codPersonal, $jefe]);

        return [
            'solicitud'  => $this->filas($rs[0] ?? [])[0] ?? null,
            'documentos' => $this->filas($rs[1] ?? []),
            'requeridos' => $this->filas($rs[2] ?? []),
        ];
    }

    /** @return array{nombre: string, mime: string, contenido: string} */
    public function documento(string $empresa, int $idDoc, ?string $codPersonal): array
    {
        $fila = ($this->filas($this->ejecutar('SP_RRHH_PERMISO_DOC_OBTENER @empresa = ?, @id_doc = ?, @cod_personal = ?', [$empresa, $idDoc, $codPersonal])[0] ?? []))[0] ?? null;
        if (!$fila || empty($fila['archivo_base64'])) {
            throw new RuntimeException('El archivo no está disponible en la intranet.', 404);
        }

        return [
            'id_permiso' => (int) $fila['id_permiso_personal'],
            'nombre'     => $fila['nombre_documento'] ?: 'documento',
            'mime'       => $fila['tip_mime'] ?: 'application/octet-stream',
            'contenido'  => base64_decode($fila['archivo_base64']),
        ];
    }

    public function quitarDocumento(string $empresa, int $idDoc, string $codPersonal): void
    {
        $this->ejecutar('SP_RRHH_PERMISO_DOC_QUITAR @empresa = ?, @id_doc = ?, @cod_personal = ?', [$empresa, $idDoc, $codPersonal]);
    }

    public function cancelar(string $empresa, int $id, string $usuario, string $codPersonal): array
    {
        return ($this->filas($this->ejecutar(
            'SP_RRHH_PERMISO_RESOLVER @empresa = ?, @id = ?, @accion = ?, @obs = ?, @usuario = ?, @nombre = ?, @cod_personal = ?',
            [$empresa, $id, 'CANCELAR', null, $usuario, null, $codPersonal]
        )[0] ?? []))[0] ?? [];
    }

    // ── Jefe ("Administrador de Sede") ──────────────────────────────────────

    public function delEquipo(string $usuario, bool $soloPendientes = false): array
    {
        return $this->filas($this->ejecutar('SP_RRHH_JEFE_PERMISOS_LISTAR @usuario = ?, @solo_pendientes = ?', [$usuario, $soloPendientes ? 1 : 0])[0] ?? []);
    }

    /** $accion: AUTORIZAR | RECHAZAR */
    public function resolverJefe(string $usuario, string $empresa, int $id, string $accion, ?string $obs, ?string $firmaUrl): array
    {
        return ($this->filas($this->ejecutar(
            'SP_RRHH_JEFE_PERMISO_RESOLVER @usuario = ?, @empresa = ?, @id = ?, @accion = ?, @obs = ?, @firma_url = ?',
            [$usuario, $empresa, $id, $accion, $obs, $firmaUrl]
        )[0] ?? []))[0] ?? [];
    }
}
