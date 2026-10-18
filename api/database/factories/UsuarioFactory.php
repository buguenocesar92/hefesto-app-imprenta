<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolImprenta;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => Str::lower($this->faker->unique()->safeEmail()),
            'nombre' => $this->faker->name(),
            'rol' => RolImprenta::PREPARADOR->value,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function supervisor(): static
    {
        return $this->state(fn (): array => ['rol' => RolImprenta::SUPERVISOR->value]);
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => RolImprenta::ADMINISTRADOR->value]);
    }

    public function auditor(): static
    {
        return $this->state(fn (): array => ['rol' => RolImprenta::AUDITOR->value]);
    }
}
