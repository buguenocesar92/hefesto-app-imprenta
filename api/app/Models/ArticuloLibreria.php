<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Articulo de libreria o papeleria.
 *
 * NO TIENE COLUMNA DE STOCK, Y NO ES UN OLVIDO.
 *
 * El stock se deriva sumando `movimientos_libreria`. Si fuera una columna
 * editable, en dos semanas de ventas estaria pasado y nadie sabria en
 * cuanto. Con el movimiento como unico origen, el stock o esta bien o falta
 * un movimiento, y eso se ve.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku
 * @property string $nombre
 * @property string $categoria
 * @property string $unidad
 * @property float|null $precio_compra
 * @property float|null $precio_venta
 * @property bool $activo
 */
class ArticuloLibreria extends Model
{
    use HasUuids;

    protected $table = 'articulos_libreria';

    protected $fillable = [
        'tenant_id',
        'sku',
        'nombre',
        'categoria',
        'unidad',
        'precio_compra',
        'precio_venta',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_compra' => 'float',
            'precio_venta' => 'float',
            'activo' => 'boolean',
        ];
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoLibreria::class, 'articulo_id');
    }

    /**
     * Stock actual, derivado de los movimientos.
     *
     * La consulta es una resta de sumas en SQL: no se suma en PHP para que
     * el dato no dependa de cuantas filas se trayeron.
     */
    public function stock(): float
    {
        $saldo = static::query()
            ->where('id', $this->id)
            ->join('movimientos_libreria', 'movimientos_libreria.articulo_id', '=', 'articulos_libreria.id')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN movimientos_libreria.sentido = 'entrada'
                                   THEN movimientos_libreria.cantidad
                                   ELSE -movimientos_libreria.cantidad END), 0) AS saldo"
            )
            ->value('saldo');

        return round((float) $saldo, 3);
    }
}
