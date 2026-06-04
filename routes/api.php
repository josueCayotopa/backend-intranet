<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmpresaController;
use App\Http\Controllers\Api\ErpController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Intranet CLL
|--------------------------------------------------------------------------
| Prefijo global: /api/v1 (configurado en bootstrap/app.php)
|--------------------------------------------------------------------------
*/

// ── Rutas públicas ──────────────────────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/empresas',    [EmpresaController::class, 'index']);

// ── Rutas protegidas con Sanctum ────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',     [AuthController::class, 'me']);
    });

    // Empresas (administración)
    Route::prefix('empresas')->group(function () {
        Route::post('/',                         [EmpresaController::class, 'store']);
        Route::put('/{id}',                      [EmpresaController::class, 'update']);
        Route::patch('/{id}/toggle-activo',      [EmpresaController::class, 'toggleActivo']);
        Route::get('/{id}/probar-conexion',      [EmpresaController::class, 'probarConexion']);
    });

    // Usuarios de la intranet
    Route::prefix('usuarios')->group(function () {
        Route::get('/',                              [UserController::class, 'index']);
        Route::post('/',                             [UserController::class, 'store']);
        Route::put('/{id}',                          [UserController::class, 'update']);
        Route::patch('/{id}/toggle-activo',          [UserController::class, 'toggleActivo']);
        Route::patch('/{id}/cambiar-password',       [UserController::class, 'cambiarPassword']);
    });

    // ERP — datos del trabajador autenticado
    Route::prefix('erp')->group(function () {
        Route::get('/dashboard',   [ErpController::class, 'dashboard']);
        Route::get('/trabajador',  [ErpController::class, 'trabajador']);
        Route::get('/periodos',    [ErpController::class, 'periodos']);
        Route::get('/boleta',      [ErpController::class, 'boleta']);      // ?periodo=202501
        Route::get('/horarios',    [ErpController::class, 'horarios']);    // ?mes=202501

        // Vacaciones — lectura y escritura en PLA_VACACIONES_MES del ERP
        Route::get('/vacaciones',                           [ErpController::class, 'vacaciones']);
        Route::post('/vacaciones',                          [ErpController::class, 'crearSolicitudVac']);
        Route::patch('/vacaciones/{codCorrVac}/aprobar',    [ErpController::class, 'aprobarVac']);
        Route::patch('/vacaciones/{codCorrVac}/cancelar',   [ErpController::class, 'cancelarVac']);
    });
});
