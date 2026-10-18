<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plancha: la hoja entera de papel con su medida.
 *
 * De la medida depende el rendimiento (cuantas piezas salen) y el peso
 * (cuantos kilos hay que comprar). Por eso es una tabla y no una constante.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 * @property int $ancho_mm
 * @property int $alto_mm
 * @property int|null $pliegos_por_paquete
 * @property bool $activo
 */
class Plancha extends Model
{
    use HasUuids;

    protected $table = 'planchas';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'ancho_mm',
        'alto_mm',
        'pliegos_por_paquete',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'ancho_mm' => 'integer',
            'alto_mm' => 'integer',
            'pliegos_por_paquete' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function papeles(): HasMany
    {
        return $this->hasMany(Papel::class, 'plancha_id');
    }

    /** Medida en el formato que se muestra en pantalla: "70 x 100 cm". */
    public function medidaLegible(): string
    {
        return sprintf(
            '%s x %s cm',
            number_format($this->ancho_mm / 10, 1, ',', '.'),
            number_format($this->alto_mm / 10, 1, ',', '.')
        );
    }

    /** Area en metros cuadrados. Base de todo el calculo de peso. */
    public function areaEnMetros(): float
    {
        return ($this->ancho_mm / 1000.0) * ($this->alto_mm / 1000.0);
    }
}
