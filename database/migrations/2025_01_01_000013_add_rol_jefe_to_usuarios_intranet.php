<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'rol')) {
                // EMPLEADO | JEFE | RRHH | ADMIN
                $table->string('rol', 20)->default('EMPLEADO')->after('activo');
            }
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'jefe_id')) {
                // Supervisor directo que aprueba las solicitudes del empleado
                // INT para coincidir con el tipo real del id en la tabla existente
                $table->unsignedInteger('jefe_id')->nullable()->after('rol');
                $table->foreign('jefe_id')
                      ->references('id')
                      ->on('USUARIOS_INTRANET')
                      ->onDelete('NO ACTION');
            }
        });
    }

    public function down(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (Schema::hasColumn('USUARIOS_INTRANET', 'jefe_id')) {
                $table->dropForeign(['jefe_id']);
                $table->dropColumn('jefe_id');
            }
            if (Schema::hasColumn('USUARIOS_INTRANET', 'rol')) {
                $table->dropColumn('rol');
            }
        });
    }
};
