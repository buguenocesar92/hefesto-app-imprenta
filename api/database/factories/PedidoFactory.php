<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoPedido;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    protected $model = Pedido::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'numero' => 'PED-' . Str::upper(Str::random(6)),
            'cliente_id' => Cliente::factory(),
            'estado' => EstadoPedido::BORRADOR->value,
            'fecha_prometida' => now()->addDays(7)->toDateString(),
            'descripcion' => $this->faker->sentence(),
            'aprobado_por' => null,
            'aprobado_en' => null,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function aprobado(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoPedido::APROBADO->value,
            'aprobado_en' => now(),
        ]);
    }

    public function enProduccion(): static
    {
        return $this->state(fn (): array => ['estado' => EstadoPedido::EN_PRODUCCION->value]);
    }
}
