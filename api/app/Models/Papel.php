<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoPapel;
use App\Enums\UnidadCompraPapel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Papel del catalogo.
 *
 * NO tiene `unidades_por_pliego` ni ningun factor de consumo, y no es un
 * olvido. Ese factor no es propiedad del papel: depende tambien de la medida
 * de la pieza a imprimir. El mismo papel de 300 g/m2 rinde decenas de
 * tarjetas por plancha y ni una sola hoja de un afiche grande. Ver
 * `App\Services\Papel\CalculadoraDeConsumo`.
 *
 * Lo que SI guarda son las dos cosas que hacen variar el costo sin que nadie
 * las escriba a mano: el gramaje y la medida de la plancha.
 *
 * `merma_prueba_porcentaje` y `merma_plancha_porcentaje` admiten NULL a
 * proposito, y en NULL el calculo falla cerrado en vez de suponer. Ver
 * `App\Services\Papel\CalculadoraDeMerma`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property TipoPapel $tipo
 * @property int $gramaje_gm2
 * @property string $plancha_id
 * @property UnidadCompraPapel $unidad_compra
 * @property string|null $merma_prueba_porcentaje
 * @property string|null $merma_plancha_porcentaje
 * @property string|null $precio_referencia_por_kg
 * @property bool $activo
 */
class Papel extends Model
{
    use HasUuids;

    protected $table = 'papeles';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'tipo',
        'gramaje_gm2',
        'plancha_id',
        'unidad_compra',
        'merma_prueba_porcentaje',
        'merma_plancha_porcentaje',
        'precio_referencia_por_kg',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPapel::class,
            'gramaje_gm2' => 'integer',
            'unidad_compra' => UnidadCompraPapel::class,
            'merma_prueba_porcentaje' => 'float',
            'merma_plancha_porcentaje' => 'float',
            'precio_referencia_por_kg' => 'float',
            'activo' => 'boolean',
        ];
    }

    public function plancha(): BelongsTo
    {
        return $this->belongsTo(Plancha::class, 'plancha_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'papel_id');
    }

    /** Descripcion para pantalla: "Offset 120 g/m2, plancha 70 x 100 cm". */
    public function descripcionCorta(): string
    {
        $plancha = $this->plancha?->medidaLegible() ?? 'sin plancha';

        return sprintf(
            '%s %d g/m2, plancha %s',
            $this->tipo->etiqueta(),
            $this->gramaje_gm2,
            $plancha
        );
    }

    /** El papel tiene toda la merma cargada y se puede cotizar consumo. */
    public function mermaConfigurada(): bool
    {
        return $this->merma_prueba_porcentaje !== null
            && $this->merma_plancha_porcentaje !== null;
    }
}
