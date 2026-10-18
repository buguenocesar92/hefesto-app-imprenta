<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPedido;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pedido: una produccion comprometida con una fecha.
 *
 * NO HAY COLUMNA DE CANTIDAD ENTREGADA, Y NO ES UN OLVIDO.
 *
 * El saldo se deriva sumando las filas de `entregas` contra las cantidades
 * pedidas. Si el saldo fuera una columna, un segundo registro de entrega la
 * desactualizaria y el pedido volveria a "completo" sin haber entregado todo.
 * Ver `App\Services\Entregas\ServicioDeEntregas`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $numero
 * @property string $cliente_id
 * @property EstadoPedido $estado
 * @property Carbon $fecha_prometida
 * @property string|null $descripcion
 * @property string|null $aprobado_por
 * @property Carbon|null $aprobado_en
 */
class Pedido extends Model
{
    use HasUuids;

    protected $table = 'pedidos';

    protected $fillable = [
        'tenant_id',
        'numero',
        'cliente_id',
        'estado',
        'fecha_prometida',
        'descripcion',
        'aprobado_por',
        'aprobado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoPedido::class,
            'fecha_prometida' => 'date',
            'aprobado_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'pedido_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(Entrega::class, 'pedido_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprobado_por');
    }

    /** Piezas pedidas en total, sumando todos los items. */
    public function cantidadPedida(): int
    {
        return (int) $this->items()->sum('cantidad_unidades');
    }

    /** Piezas ya entregadas, sumando todas las entregas. */
    public function cantidadEntregada(): int
    {
        return (int) $this->entregas()->sum('cantidad_unidades');
    }

    /** Piezas que faltan por entregar. Nunca negativo. */
    public function saldo(): int
    {
        return max(0, $this->cantidadPedida() - $this->cantidadEntregada());
    }

    /**
     * El pedido tiene TODOS sus items con prueba aprobada.
     *
     * Esta es la condicion que habilita la produccion. La comprueba tambien
     * el gatillo de PostgreSQL; las dos capas estan porque cada una falla de
     * forma distinta: el codigo da un mensaje legible y la base impide el
     * bypass.
     */
    public function todoItemAprobado(): bool
    {
        if ($this->items()->count() === 0) {
            return false;
        }

        return $this->items()->get()->every(
            static fn (PedidoItem $item): bool => $item->tienePruebaAprobada()
        );
    }
}
