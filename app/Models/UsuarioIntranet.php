<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class UsuarioIntranet extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'USUARIOS_INTRANET';

    protected $fillable = [
        'empresa_id',
        'cod_personal',
        'ape_paterno',
        'ape_materno',
        'nom_trabajador',
        'cod_personal_jefe',
        'dni',
        'usuario',
        'password_hash',
        'foto_url',
        'firma_url',
        'rol',
        'activo',
        'debe_cambiar_password',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'activo'                 => 'boolean',
        'debe_cambiar_password'  => 'boolean',
    ];

    /**
     * Retorna el campo usado por Sanctum/Hash para verificar contraseñas.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
