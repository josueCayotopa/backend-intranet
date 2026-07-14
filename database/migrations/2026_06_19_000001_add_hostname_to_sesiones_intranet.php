<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('SESIONES_INTRANET', function (Blueprint $table) {
            $table->string('hostname', 255)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('SESIONES_INTRANET', function (Blueprint $table) {
            $table->dropColumn('hostname');
        });
    }
};
