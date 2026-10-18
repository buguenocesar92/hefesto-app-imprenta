<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolImprenta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Persona que opera la imprenta.
 *
 * La autenticacion la resuelve Authentik; esta clase guarda lo propio de la
 * imprenta: el rol con el que se autoriza cada accion.
 *
 * El correo es unico POR EMPRESA: la misma persona puede tener roles
 * distintos en dos imprentas. Por eso el acceso pregunta primero por la
 * empresa.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $nombre
 * @property RolImprenta $rol
 * @property bool $activo
 */
class Usuario extends Authenticatable
{
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'email',
        'nombre',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolImprenta::class,
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function esSupervisorOAdmin(): bool
    {
        return in_array($this->rol, [RolImprenta::SUPERVISOR, RolImprenta::ADMINISTRADOR], true);
    }
}
