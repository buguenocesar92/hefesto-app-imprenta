<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPrueba;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Item del pedido: una cosa concreta que se imprime.
 *
 * "5000 tarjetas de 9 x 5 cm en opal de 300 g/m2" es un item. Con eso el
 * sistema puede calcular el papel y puede decidir si se puede imprimir.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_id
 * @property string $papel_id
 * @property string $descripcion
 * @property int $cantidad_unidades
 * @property int $ancho_mm
 * @property int $alto_mm
 * @property int $n_por_ventana
 */
class PedidoItem extends Model
{
    use HasUuids;

    protected $table = 'pedido_items';

    protected $fillable = [
        'tenant_id',
        'pedido_id',
        'papel_id',
        'descripcion',
        'cantidad_unidades',
        'ancho_mm',
        'alto_mm',
        'n_por_ventana',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_unidades' => 'integer',
            'ancho_mm' => 'integer',
            'alto_mm' => 'integer',
            'n_por_ventana' => 'integer',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function papel(): BelongsTo
    {
        return $this->belongsTo(Papel::class, 'papel_id');
    }

    public function artes(): HasMany
    {
        return $this->hasMany(Arte::class, 'pedido_item_id')->orderBy('version');
    }

    public function pruebas(): HasMany
    {
        return $this->hasMany(Prueba::class, 'pedido_item_id');
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(OrdenProduccion::class, 'pedido_item_id');
    }

    /**
     * Este item tiene una prueba APROBADA.
     *
     * Lo unico que habilita imprimir. Una prueba rechazada o una prueba
     * vencida no cuentan.
     */
    public function tienePruebaAprobada(): bool
    {
        return $this->pruebas()
            ->where('estado', EstadoPrueba::APROBADA->value)
            ->exists();
    }

    /** La ultima prueba registrada, sea del estado que sea. */
    public function ultimaPrueba(): ?Prueba
    {
        return $this->pruebas()
            ->orderByDesc('version')
            ->orderByDesc('created_at')
            ->first();
    }

    /** Medida de la pieza acabada, en el formato que se muestra en pantalla. */
    public function medidaLegible(): string
    {
        return sprintf('%d x %d mm', $this->ancho_mm, $this->alto_mm);
    }

    /**
     * Si la prueba enviada quedo sin respuesta.
     *
     * VENCIDO NO ES APROBADO. Un pedido con la prueba vencida sigue sin
     * poder imprimirse: el taller tiene que llamar al cliente. Ver
     * `config/imprenta.php`, `pruebas.dias_para_vencer`.
     */
    public function pruebaVencida(): bool
    {
        $dias = (int) config('imprenta.pruebas.dias_para_vencer', 5);

        return $this->pruebas()
            ->where('estado', EstadoPrueba::ENVIADA->value)
            ->whereNotNull('enviada_en')
            ->where('enviada_en', '<=', Carbon::now()->subDays($dias))
            ->exists();
    }
}
