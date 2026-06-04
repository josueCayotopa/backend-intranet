<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('SOLICITUDES_VACACIONES')) {
            return;
        }

        Schema::create('SOLICITUDES_VACACIONES', function (Blueprint $table) {
            $table->id();

            // ── Solicitante ───────────────────────────────────────────────
            $table->foreignId('empresa_id')
                  ->constrained('EMPRESAS')
                  ->cascadeOnDelete();

            $table->unsignedInteger('usuario_id');
            $table->foreign('usuario_id')
                  ->references('id')
                  ->on('USUARIOS_INTRANET')
                  ->cascadeOnDelete();

            $table->string('cod_personal', 20);

            // ── Datos de la solicitud ─────────────────────────────────────
            // VG = Vacaciones Gozadas | VC = Compra de Vacaciones
            $table->char('tipo', 2)->default('VG');
            $table->date('fec_inicio');
            $table->date('fec_final');
            $table->unsignedSmallInteger('num_dias');
            $table->char('ano_proceso', 4);
            $table->string('obs_solicitante', 500)->nullable();

            // ── Estado del flujo ──────────────────────────────────────────
            // PE=Pendiente | AJ=Aprobado Jefe | RJ=Rechazado Jefe
            // AR=Aprobado RRHH | RR=Rechazado RRHH | CA=Cancelado
            $table->char('estado', 2)->default('PE');

            // ── Nivel 1: Jefe de área ─────────────────────────────────────
            $table->unsignedInteger('jefe_id')->nullable();
            $table->foreign('jefe_id')
                  ->references('id')
                  ->on('USUARIOS_INTRANET')
                  ->nullOnDelete();
            $table->dateTime('fec_aprob_jefe')->nullable();
            $table->string('obs_jefe', 500)->nullable();

            // ── Nivel 2: Recursos Humanos ─────────────────────────────────
            $table->unsignedInteger('rrhh_id')->nullable();
            $table->foreign('rrhh_id')
                  ->references('id')
                  ->on('USUARIOS_INTRANET')
                  ->nullOnDelete();
            $table->dateTime('fec_aprob_rrhh')->nullable();
            $table->string('obs_rrhh', 500)->nullable();
            // Período vacacional (llenado por RRHH — ej: "2025/2026")
            $table->string('periodo_vacacional', 100)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SOLICITUDES_VACACIONES');
    }
};
