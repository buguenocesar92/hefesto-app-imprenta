<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden de produccion: la evidencia de que se imprimio, y de cuanto papel se
 * perdio.
 *
 * La diferencia entre el papel usado y el producto entregado ESTE REGISTRO.
 * `pliegos_totales` esta partido en netos, prueba y merma, y un CHECK en
 * PostgreSQL obliga a que la suma cuadre.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_item_id
 * @property string $papel_id
 * @property int $pliegos_netos
 * @property int $pliegos_prueba
 * @property int $pliegos_merma
 * @property int $pliegos_totales
 * @property int $producto_bueno
 * @property string $usuario_id
 * @property string|null $notas
 */
class OrdenProduccion extends Model
{
    use HasUuids;

    protected $table = 'ordenes_produccion';

    protected $fillable = [
        'tenant_id',
        'pedido_item_id',
        'papel_id',
        'pliegos_netos',
        'pliegos_prueba',
        'pliegos_merma',
        'pliegos_totales',
        'producto_bueno',
        'usuario_id',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'pliegos_netos' => 'integer',
            'pliegos_prueba' => 'integer',
            'pliegos_merma' => 'integer',
            'pliegos_totales' => 'integer',
            'producto_bueno' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function papel(): BelongsTo
    {
        return $this->belongsTo(Papel::class, 'papel_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /**
     * Porcentaje de merma REAL de esta corrida.
     *
     * Se mide sobre los pliegos netos. Es el numero con el que se corrigen
     * los porcentajes del catalogo de papeles: comparar lo previsto contra
     * lo medido es la unica forma de que el supuesto no se quede viejo.
     */
    public function mermaRealPorcentaje(): ?float
    {
        $perdidos = $this->pliegos_prueba + $this->pliegos_merma;

        if ($this->pliegos_netos <= 0) {
            return null;
        }

        return round(($perdidos / $this->pliegos_netos) * 100.0, 2);
    }
}
