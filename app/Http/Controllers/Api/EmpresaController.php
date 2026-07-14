<?php

namespace App\Http\Controllers\Api;

use App\Services\EmpresaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmpresaController extends BaseController
{
    public function __construct(private readonly EmpresaService $empresaService) {}

    /**
     * Lista todas las empresas (sin credenciales de BD).
     * Ruta pública.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $soloActivas = $request->boolean('activas', false);
            $empresas = $this->empresaService->listar($soloActivas);

            return $this->success($empresas, 'Empresas obtenidas.');
        } catch (\Exception $e) {
            return $this->error('Error al obtener empresas.', 500);
        }
    }

    /**
     * Crea una nueva empresa.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'codigo'      => 'required|string|max:20|unique:EMPRESAS,codigo',
                'nombre'      => 'required|string|max:200',
                'ruc'         => 'required|string|size:11|unique:EMPRESAS,ruc',
                'cod_erp'     => 'nullable|string|max:20',
                'logo_url'    => 'nullable|url|max:500',
                'db_host'     => 'required|string|max:100',
                'db_port'     => 'required|integer|between:1,65535',
                'db_name'     => 'required|string|max:100',
                'db_user'     => 'required|string|max:100',
                'db_password' => 'required|string|max:255',
                'activo'      => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $empresa = $this->empresaService->crear($validated);

            return $this->success(
                ['id' => $empresa->id, 'codigo' => $empresa->codigo, 'nombre' => $empresa->nombre],
                'Empresa creada correctamente.',
                201
            );
        } catch (QueryException $e) {
            return $this->error('Error de base de datos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Actualiza una empresa existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'codigo'      => "string|max:20|unique:EMPRESAS,codigo,{$id}",
                'nombre'      => 'string|max:200',
                'ruc'         => "string|size:11|unique:EMPRESAS,ruc,{$id}",
                'cod_erp'     => 'nullable|string|max:20',
                'logo_url'    => 'nullable|url|max:500',
                'db_host'     => 'string|max:100',
                'db_port'     => 'integer|between:1,65535',
                'db_name'     => 'string|max:100',
                'db_user'     => 'string|max:100',
                'db_password' => 'string|max:255',
                'activo'      => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Datos inválidos.', 422, $e->errors());
        }

        try {
            $empresa = $this->empresaService->actualizar($id, $validated);

            return $this->success(
                ['id' => $empresa->id, 'codigo' => $empresa->codigo, 'nombre' => $empresa->nombre],
                'Empresa actualizada correctamente.'
            );
        } catch (ModelNotFoundException) {
            return $this->error('Empresa no encontrada.', 404);
        } catch (QueryException $e) {
            return $this->error('Error de base de datos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Alterna el estado activo/inactivo de una empresa.
     */
    public function toggleActivo(int $id): JsonResponse
    {
        try {
            $empresa = $this->empresaService->toggleActivo($id);

            $estado = $empresa->activo ? 'activada' : 'desactivada';

            return $this->success(
                ['id' => $empresa->id, 'activo' => $empresa->activo],
                "Empresa {$estado} correctamente."
            );
        } catch (ModelNotFoundException) {
            return $this->error('Empresa no encontrada.', 404);
        } catch (QueryException $e) {
            return $this->error('Error de base de datos.', 500);
        }
    }

    /**
     * Prueba la conexión a la BD del ERP de la empresa.
     */
    public function probarConexion(int $id): JsonResponse
    {
        try {
            $this->empresaService->probarConexion($id);

            return $this->success(null, 'Conexión exitosa a la base de datos del ERP.');
        } catch (ModelNotFoundException) {
            return $this->error('Empresa no encontrada.', 404);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return $this->error('No se pudieron descifrar las credenciales almacenadas.', 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Sube o reemplaza el logo de una empresa.
     * Guarda en storage/app/public/logos-empresa/ y actualiza logo_url.
     */
    public function subirLogo(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Archivo inválido.', 422, $e->errors());
        }

        try {
            $empresa = \App\Models\Empresa::findOrFail($id);

            if ($empresa->logo_url) {
                $pathAnterior = preg_replace('#^.*/storage/#', 'public/', $empresa->logo_url);
                \Illuminate\Support\Facades\Storage::delete($pathAnterior);
            }

            $path    = $request->file('logo')->store('logos-empresa', 'public');
            $logoUrl = url('storage/' . $path);

            $empresa->update(['logo_url' => $logoUrl]);

            return $this->success(['logo_url' => $logoUrl], 'Logo actualizado.');
        } catch (ModelNotFoundException) {
            return $this->error('Empresa no encontrada.', 404);
        } catch (\Exception $e) {
            return $this->error('Error al subir el logo: ' . $e->getMessage(), 500);
        }
    }
}
