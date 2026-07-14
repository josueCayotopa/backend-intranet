<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Eliminar FK y columna jefe_id ───────────────────────────
        if (Schema::hasColumn('USUARIOS_INTRANET', 'jefe_id')) {
            DB::statement("
                DECLARE @sql NVARCHAR(500);
                SELECT TOP 1 @sql = N'ALTER TABLE [USUARIOS_INTRANET] DROP CONSTRAINT [' + fk.name + N']'
                FROM sys.foreign_keys fk
                JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
                JOIN sys.columns c ON fkc.parent_object_id = c.object_id
                                  AND fkc.parent_column_id = c.column_id
                WHERE OBJECT_NAME(fk.parent_object_id) = 'USUARIOS_INTRANET'
                  AND c.name = 'jefe_id';
                IF @sql IS NOT NULL EXEC sp_executesql @sql;
            ");

            Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
                $table->dropColumn('jefe_id');
            });
        }

        // ── 2. Agregar nuevos campos ────────────────────────────────────
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'ape_paterno')) {
                $table->string('ape_paterno', 100)->nullable()->after('cod_personal');
            }
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'ape_materno')) {
                $table->string('ape_materno', 100)->nullable()->after('ape_paterno');
            }
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'nom_trabajador')) {
                $table->string('nom_trabajador', 200)->nullable()->after('ape_materno');
            }
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'cod_personal_jefe')) {
                $table->string('cod_personal_jefe', 20)->nullable()->after('nom_trabajador');
            }
        });
    }

    public function down(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            $columns = array_filter(
                ['ape_paterno', 'ape_materno', 'nom_trabajador', 'cod_personal_jefe'],
                fn($col) => Schema::hasColumn('USUARIOS_INTRANET', $col)
            );

            if (!empty($columns)) {
                $table->dropColumn(array_values($columns));
            }

            if (!Schema::hasColumn('USUARIOS_INTRANET', 'jefe_id')) {
                $table->unsignedInteger('jefe_id')->nullable();
                $table->foreign('jefe_id')
                      ->references('id')
                      ->on('USUARIOS_INTRANET')
                      ->onDelete('NO ACTION');
            }
        });
    }
};
