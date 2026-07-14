<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('SESIONES_INTRANET')) {
            return;
        }

        Schema::create('SESIONES_INTRANET', function (Blueprint $table) {
            $table->id();
            // INT para coincidir con USUARIOS_INTRANET.id (no BIGINT)
            $table->unsignedInteger('usuario_id');
            $table->foreign('usuario_id')->references('id')->on('USUARIOS_INTRANET');
            $table->unsignedBigInteger('token_sanctum_id')->nullable(); // personal_access_tokens.id
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('dispositivo', 50)->default('PC');         // PC | Móvil | Tablet
            $table->string('navegador', 100)->nullable();
            $table->string('sistema_operativo', 100)->nullable();
            $table->timestamp('fec_login')->useCurrent();
            $table->timestamp('fec_ultimo_acceso')->nullable();
            $table->timestamp('fec_logout')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['usuario_id', 'activo']);
            $table->index('token_sanctum_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SESIONES_INTRANET');
    }
};
