<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionIntranet extends Model
{
    protected $table = 'SESIONES_INTRANET';

    protected $fillable = [
        'usuario_id',
        'token_sanctum_id',
        'ip_address',
        'hostname',
        'user_agent',
        'dispositivo',
        'navegador',
        'sistema_operativo',
        'fec_login',
        'fec_ultimo_acceso',
        'fec_logout',
        'activo',
    ];

    protected $casts = [
        'activo'            => 'boolean',
        'fec_login'         => 'datetime',
        'fec_ultimo_acceso' => 'datetime',
        'fec_logout'        => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(UsuarioIntranet::class, 'usuario_id');
    }
}
