<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (!Schema::hasColumn('USUARIOS_INTRANET', 'firma_url')) {
                $table->string('firma_url', 500)->nullable()->after('foto_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('USUARIOS_INTRANET', function (Blueprint $table) {
            if (Schema::hasColumn('USUARIOS_INTRANET', 'firma_url')) {
                $table->dropColumn('firma_url');
            }
        });
    }
};
