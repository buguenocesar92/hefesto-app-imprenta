<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrega: una salida de piezas, total o parcial.
 *
 * Un pedido de 20.000 se entrega en dos. Por eso esta tabla no guarda saldo:
 * el saldo se deriva sumando estas filas, y el estado del pedido lo
 * recalcula `ServicioDeEntregas`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_id
 * @property int $cantidad_unidades
 * @property string $medio
 * @property string|null $entregado_por
 * @property Carbon $entregado_en
 * @property string $usuario_id
 * @property string|null $notas
 */
class Entrega extends Model
{
    use HasUuids;

    protected $table = 'entregas';

    protected $fillable = [
        'tenant_id',
        'pedido_id',
        'cantidad_unidades',
        'medio',
        'entregado_por',
        'entregado_en',
        'usuario_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_unidades' => 'integer',
            'entregado_en' => 'date',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
