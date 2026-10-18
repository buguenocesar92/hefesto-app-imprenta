<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoPapel;
use App\Enums\UnidadCompraPapel;
use App\Models\Papel;
use App\Models\Plancha;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Papel>
 */
class PapelFactory extends Factory
{
    protected $model = Papel::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'codigo' => Str::upper(Str::random(6)),
            'nombre' => 'Papel ' . $this->faker->word(),
            'tipo' => TipoPapel::OFFSET->value,
            'gramaje_gm2' => $this->faker->numberBetween(70, 350),
            'plancha_id' => Plancha::factory(),
            'unidad_compra' => UnidadCompraPapel::PLIEGO->value,

            // En NULL a proposito: un papel sin merma cargada NO se puede
            // cotizar, y eso hay que probarlo. Las pruebas que si calculan
            // usan `conMerma()`.
            'merma_prueba_porcentaje' => null,
            'merma_plancha_porcentaje' => null,

            'precio_referencia_por_kg' => null,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function dePlancha(Plancha $plancha): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $plancha->tenant_id,
            'plancha_id' => $plancha->id,
        ]);
    }

    /** Papel con la merma cargada, para poder calcular consumo. */
    public function conMerma(float $prueba = 4.0, float $plancha = 2.0): static
    {
        return $this->state(fn (): array => [
            'merma_prueba_porcentaje' => $prueba,
            'merma_plancha_porcentaje' => $plancha,
        ]);
    }

    /** Offset de 120 g/m2, el papel de uso corriente en el dia a dia. */
    public function offset120(): static
    {
        return $this->state(fn (): array => [
            'codigo' => 'OFF120',
            'nombre' => 'Offset 120 g/m2',
            'tipo' => TipoPapel::OFFSET->value,
            'gramaje_gm2' => 120,
        ]);
    }

    public function opal300(): static
    {
        return $this->state(fn (): array => [
            'codigo' => 'OPL300',
            'nombre' => 'Opal 300 g/m2',
            'tipo' => TipoPapel::OPAL->value,
            'gramaje_gm2' => 300,
        ]);
    }

    public function compradoPorKilos(): static
    {
        return $this->state(fn (): array => [
            'unidad_compra' => UnidadCompraPapel::KILO->value,
        ]);
    }
}
