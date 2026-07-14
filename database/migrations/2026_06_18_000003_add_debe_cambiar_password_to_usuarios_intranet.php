<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'debe_cambiar_password')) {
                // true  → usuario debe cambiar contraseña al siguiente login
                // Se activa automáticamente cuando la clave era SHA-256 o texto plano
                $table->boolean('debe_cambiar_password')->default(false)->after('activo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (Schema::hasColumn('USUARIOS_INTRANET', 'debe_cambiar_password')) {
                $table->dropColumn('debe_cambiar_password');
            }
        });
    }
};
