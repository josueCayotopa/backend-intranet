<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('EMPRESAS', 'cod_erp')) {
            return;
        }

        Schema::table('EMPRESAS', function (Blueprint $table) {
            // Código interno del ERP (COD_EMPRESA en las tablas PLA_*) — siempre '0001'
            $table->string('cod_erp', 10)->default('0001')->after('db_name');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('EMPRESAS', 'cod_erp')) {
            Schema::table('EMPRESAS', fn(Blueprint $t) => $t->dropColumn('cod_erp'));
        }
    }
};
