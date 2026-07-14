<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmpresaController;
use App\Http\Controllers\Api\ErpController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\CheckSessionActivity;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Intranet CLL
|--------------------------------------------------------------------------
| Prefijo global: /api/v1 (configurado en bootstrap/app.php)
|--------------------------------------------------------------------------
*/

// ── Rutas públicas ──────────────────────────────────────────────────────
Route::post('/auth/login',              [AuthController::class, 'login']);
Route::post('/auth/recuperar-password', [AuthController::class, 'recuperarPassword']);
Route::get('/empresas',                 [EmpresaController::class, 'index']);

// ── Rutas protegidas con Sanctum + control de inactividad ───────────────
Route::middleware(['auth:sanctum', CheckSessionActivity::class])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout',              [AuthController::class, 'logout']);
        Route::post('/logout-todos',        [AuthController::class, 'logoutTodos']);
        Route::get('/me',                   [AuthController::class, 'me']);
        Route::post('/ping',                [AuthController::class, 'ping']);
        Route::post('/cambiar-password',    [AuthController::class, 'cambiarPassword']);
        Route::post('/foto',                [AuthController::class, 'subirFoto']);
        Route::get('/sesiones',             [AuthController::class, 'sesiones']);
        Route::delete('/sesiones/{id}',     [AuthController::class, 'cerrarSesion']);
    });

    // Empresas (administración)
    Route::prefix('empresas')->group(function () {
        Route::post('/',                         [EmpresaController::class, 'store']);
        Route::put('/{id}',                      [EmpresaController::class, 'update']);
        Route::patch('/{id}/toggle-activo',      [EmpresaController::class, 'toggleActivo']);
        Route::get('/{id}/probar-conexion',      [EmpresaController::class, 'probarConexion']);
    });

    // Usuarios de la intranet — solo ADMIN
    Route::middleware(EnsureAdmin::class)->prefix('usuarios')->group(function () {
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

        // Vacaciones procesadas — lectura de PLA_VACACIONES_MES_CAB (registradas por RRHH)
        Route::get('/vacaciones',                               [ErpController::class, 'vacaciones']);
        Route::patch('/vacaciones/{codCorrVac}/aprobar',        [ErpController::class, 'aprobarVac']);
        Route::patch('/vacaciones/{codCorrVac}/cancelar',       [ErpController::class, 'cancelarVac']);

        // Solicitudes intranet — lectura/escritura en PLA_SOL_VACACIONES
        Route::get('/solicitudes-vac',                          [ErpController::class, 'solicitudesVac']);
        Route::post('/solicitudes-vac',                         [ErpController::class, 'crearSolicitudVac']);
        Route::patch('/solicitudes-vac/{codCorrSol}/cancelar',  [ErpController::class, 'cancelarSolVac']);

        // Configuración de vacaciones del trabajador (habilitado + límite anual)
        Route::get('/config-vac',                               [ErpController::class, 'configVac']);

        // Registro de visualización de boletas (1 registro por boleta/mes)
        Route::get('/boletas-vistas',                           [ErpController::class, 'boletasVistas']);
        Route::post('/boletas-vistas',                          [ErpController::class, 'registrarVisBoleta']);
    });
});
