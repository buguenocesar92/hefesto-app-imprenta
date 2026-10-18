<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de libreria: una entrada o una salida de un articulo.
 *
 * Es el unico origen del stock. No se edita: una correccion se hace con un
 * movimiento contrario y quedan los dos en la historia.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $articulo_id
 * @property string $sentido
 * @property float $cantidad
 * @property string $motivo
 * @property string|null $referencia
 * @property string $usuario_id
 */
class MovimientoLibreria extends Model
{
    use HasUuids;

    protected $table = 'movimientos_libreria';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'sentido',
        'cantidad',
        'motivo',
        'referencia',
        'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'float',
        ];
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(ArticuloLibreria::class, 'articulo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function esEntrada(): bool
    {
        return $this->sentido === 'entrada';
    }
}
