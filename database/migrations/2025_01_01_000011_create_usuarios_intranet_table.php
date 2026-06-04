<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('USUARIOS_INTRANET')) {
            return;
        }

        Schema::create('USUARIOS_INTRANET', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('EMPRESAS')->cascadeOnDelete();
            $table->string('cod_personal', 20);
            $table->string('dni', 15);
            $table->string('usuario', 100)->unique();
            $table->string('password_hash', 255);
            $table->string('foto_url', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'cod_personal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('USUARIOS_INTRANET');
    }
};
