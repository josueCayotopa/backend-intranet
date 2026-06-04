<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class Empresa extends Model
{
    use HasFactory;

    protected $table = 'EMPRESAS';

    protected $fillable = [
        'codigo',
        'nombre',
        'ruc',
        'logo_url',
        'db_host',
        'db_port',
        'db_name',
        'cod_erp',
        'db_user',
        'db_password',
        'activo',
    ];

    protected $hidden = [
        'db_password',
        'db_user',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Accessor: desencripta db_password al leer.
     */
    public function getDbPasswordAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Mutator: encripta db_password al guardar.
     */
    public function setDbPasswordAttribute(string $value): void
    {
        $this->attributes['db_password'] = Crypt::encryptString($value);
    }

    /**
     * Scope: solo empresas activas.
     */
    public function scopeActivas(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('activo', 1);
    }

    public function usuarios()
    {
        return $this->hasMany(UsuarioIntranet::class, 'empresa_id');
    }
}
