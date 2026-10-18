<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'codigo' => Str::upper(Str::random(5)),
            'nombre' => $this->faker->company(),
            'contacto' => $this->faker->name(),
            'correo' => $this->faker->safeEmail(),
            'telefono' => '+56 9 0000 0000',
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }
}
