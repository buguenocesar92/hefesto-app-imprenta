<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa usuaria del sistema: una imprenta.
 *
 * Es la unica entidad sin `tenant_id` ni RLS, a proposito: se consulta en el
 * acceso, antes de que exista sesion. Lo que sale al selector de entrada es
 * la vista `vw_impresas_publicas` (tres columnas), no esta tabla.
 *
 * @property string $id
 * @property string $slug
 * @property string $nombre
 * @property string|null $razon_social
 * @property bool $activo
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'tenant_id');
    }

    public function papeles(): HasMany
    {
        return $this->hasMany(Papel::class, 'tenant_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'tenant_id');
    }

    /**
     * Empresas que se ofrecen en el selector de acceso.
     *
     * Lee la VISTA, no la tabla. La vista expone solo `slug`, `nombre` y
     * `activo`, que es lo unico que sirve para elegir a donde entrar. Se
     * consulta de verdad: es lo que llama `AuthController::empresas()`.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function paraElSelectorDeAcceso(): \Illuminate\Support\Collection
    {
        return static::query()
            ->getConnection()
            ->select('SELECT slug, nombre, activo FROM public.vw_impresas_publicas ORDER BY nombre');
    }
}
