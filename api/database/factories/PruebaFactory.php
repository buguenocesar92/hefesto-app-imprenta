<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoPrueba;
use App\Models\Arte;
use App\Models\PedidoItem;
use App\Models\Prueba;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prueba>
 */
class PruebaFactory extends Factory
{
    protected $model = Prueba::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'pedido_item_id' => PedidoItem::factory(),
            'arte_id' => null,
            'version' => 1,
            'estado' => EstadoPrueba::PENDIENTE->value,
            'enviada_en' => null,
            'resuelta_en' => null,
            'aprobada_por' => null,
            'comentario' => null,
        ];
    }

    public function para(PedidoItem $item): static
    {
        return $this->state(function () use ($item): array {
            $arte = Arte::query()->create([
                'tenant_id' => $item->tenant_id,
                'pedido_item_id' => $item->id,
                'version' => 1,
                'nombre_archivo' => 'diseno.pdf',
                'url' => 'https://archivos.ejemplo.cl/diseno.pdf',
                'sha256' => str_repeat('a', 64),
                'subido_por' => \App\Models\Usuario::factory(),
            ]);

            return [
                'tenant_id' => $item->tenant_id,
                'arte_id' => $arte->id,
            ];
        });
    }

    /** Aprobada por un supervisor, como queda en la vida real. */
    public function aprobada(): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoPrueba::APROBADA->value,
            'enviada_en' => now()->subDay(),
            'resuelta_en' => now(),
            'aprobada_por' => \App\Models\Usuario::factory(),
        ]);
    }

    public function enviadaHace(int $dias): static
    {
        return $this->state(fn (): array => [
            'estado' => EstadoPrueba::ENVIADA->value,
            'enviada_en' => now()->subDays($dias),
        ]);
    }
}
