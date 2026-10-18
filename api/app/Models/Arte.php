<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Arte: el archivo de diseno, en una version.
 *
 * EL ARCHIVO VIVE FUERA DE ESTE SISTEMA. Aca solo queda el registro: nombre,
 * donde esta, que version es, quien la subio y su huella SHA-256.
 *
 * Guardar la huella es lo que permite demostrar, meses despues, que el
 * archivo que se imprimio es el mismo que el cliente aprobo. Sin eso, una
 * discusion sobre el diseno no se puede ganar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pedido_item_id
 * @property int $version
 * @property string $nombre_archivo
 * @property string $url
 * @property string|null $sha256
 * @property string|null $notas
 * @property string $subido_por
 */
class Arte extends Model
{
    use HasUuids;

    protected $table = 'artes';

    protected $fillable = [
        'tenant_id',
        'pedido_item_id',
        'version',
        'nombre_archivo',
        'url',
        'sha256',
        'notas',
        'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'subido_por');
    }

    public function pruebas(): HasMany
    {
        return $this->hasMany(Prueba::class, 'arte_id');
    }

    /** Proxima version disponible para este item. */
    public static function siguienteVersion(string $pedidoItemId): int
    {
        $ultima = static::query()
            ->where('pedido_item_id', $pedidoItemId)
            ->max('version');

        return (int) $ultima + 1;
    }
}
