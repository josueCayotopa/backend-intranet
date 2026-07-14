<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amplía USUARIOS_INTRANET.cod_personal_jefe de VARCHAR(20) a VARCHAR(100).
     * Ahora guarda el campo 'usuario' (login de intranet) del jefe,
     * no el cod_personal del ERP (que puede variar por base de datos).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('USUARIOS_INTRANET', 'cod_personal_jefe')) {
            return;
        }

        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            $table->string('cod_personal_jefe', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('USUARIOS_INTRANET', 'cod_personal_jefe')) {
            return;
        }

        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            $table->string('cod_personal_jefe', 20)->nullable()->change();
        });
    }
};
