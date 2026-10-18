<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ArticuloLibreria;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ArticuloLibreria>
 *
 * Sin `stock`: el stock se deriva de los movimientos. Ver el modelo.
 */
class ArticuloLibreriaFactory extends Factory
{
    protected $model = ArticuloLibreria::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sku' => 'SKU-' . Str::upper(Str::random(6)),
            'nombre' => ucfirst($this->faker->word()) . ' ' . ucfirst($this->faker->word()),
            'categoria' => $this->faker->randomElement(['escritorio', 'cuadernos', 'papeleria']),
            'unidad' => 'unidad',

            // Precios en NULL: esta aplicacion no inventa precios. La
            // pantalla los muestra como pendientes.
            'precio_compra' => null,
            'precio_venta' => null,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function conPrecios(float $compra = 1000.0, float $venta = 1600.0): static
    {
        return $this->state(fn (): array => [
            'precio_compra' => $compra,
            'precio_venta' => $venta,
        ]);
    }
}
