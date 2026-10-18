<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPrueba;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Prueba (mockup): lo que el cliente vio antes de que se imprimiera.
 *
 * La fila pasa a `aprobada` solo por `ServicioDeAprobacion`, que valida que
 * el item tenga una prueba APROBADA y registra quien dejo constancia de la
 * respuesta del cliente.
 *
 * `aprobada_por` NO es quien aprobo: es quien REGISTRO la aprobacion. La
 * aprueba el cliente; el supervisor deja constancia. Guardar el nombre es lo
 * que hace auditable el pedido.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_item_id
 * @property string $arte_id
 * @property int $version
 * @property EstadoPrueba $estado
 * @property Carbon|null $enviada_en
 * @property Carbon|null $resuelta_en
 * @property string|null $aprobada_por
 * @property string|null $comentario
 */
class Prueba extends Model
{
    use HasUuids;

    protected $table = 'pruebas';

    protected $fillable = [
        'tenant_id',
        'pedido_item_id',
        'arte_id',
        'version',
        'estado',
        'enviada_en',
        'resuelta_en',
        'aprobada_por',
        'comentario',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoPrueba::class,
            'version' => 'integer',
            'enviada_en' => 'datetime',
            'resuelta_en' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function arte(): BelongsTo
    {
        return $this->belongsTo(Arte::class, 'arte_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprobada_por');
    }

    /** Esta sola prueba habilita la produccion de su item. */
    public function habilitaProduccion(): bool
    {
        return $this->estado === EstadoPrueba::APROBADA;
    }
}
