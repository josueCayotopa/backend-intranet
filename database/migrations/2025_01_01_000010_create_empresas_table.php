<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('EMPRESAS')) {
            return;
        }

        Schema::create('EMPRESAS', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 200);
            $table->string('ruc', 11)->unique();
            $table->string('logo_url', 500)->nullable();
            $table->string('db_host', 100);
            $table->integer('db_port')->default(1433);
            $table->string('db_name', 100);
            $table->string('db_user', 100);
            $table->string('db_password', 500); // encriptado con Crypt::encryptString()
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('EMPRESAS');
    }
};
