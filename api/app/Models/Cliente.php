<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente de la imprenta.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $contacto
 * @property string|null $correo
 * @property string|null $telefono
 * @property bool $activo
 */
class Cliente extends Model
{
    use HasUuids;

    protected $table = 'clientes';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'contacto',
        'correo',
        'telefono',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'cliente_id');
    }
}
