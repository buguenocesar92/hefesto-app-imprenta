<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plancha;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plancha>
 */
class PlanchaFactory extends Factory
{
    protected $model = Plancha::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'codigo' => Str::upper(Str::random(5)),
            'nombre' => 'Plancha ' . $this->faker->numberBetween(50, 100),
            'ancho_mm' => $this->faker->numberBetween(600, 760),
            'alto_mm' => $this->faker->numberBetween(880, 1020),
            'pliegos_por_paquete' => null,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    /**
     * Plancha de 70 x 100 cm, la medida de referencia de una imprenta de
     * PyME. Se fija a proposito en las pruebas de conversion: el calculo tiene
     * que dar el mismo numero siempre, no uno que varia con los aleatorios.
     */
    public function deReferencia(): static
    {
        return $this->state(fn (): array => [
            'codigo' => 'P70100',
            'nombre' => 'Plancha 70 x 100 cm',
            'ancho_mm' => 700,
            'alto_mm' => 1000,
        ]);
    }
}
