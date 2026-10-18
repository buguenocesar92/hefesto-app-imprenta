<?php

declare(strict_types=1);

namespace App\Services\Papel;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Desglose de merma: por que se pierde papel.
 *
 * Se devuelve el desglose y no el numero porque el numero solo no sirve para
 * nada. Cuando el taller pregunta "por que dice que pierdo 40 pliegos", la
 * respuesta que sirve esta partida: cuantos fueron de prueba, cuantos se
 * dañaron en la plancha y con que porcentaje se estimo cada cosa. Si se
 * devuelve solo el total, el taller no puede discutir el supuesto: solo el
 * resultado.
 *
 * Por eso los porcentajes de origen van tambien en el desglose. Son la parte
 * que la imprenta puede corregir cuando el supuesto no calza con su
 * historial.
 *
 * @implements Arrayable<string, mixed>
 */
final class DesgloseDeMerma implements Arrayable
{
    /**
     * @param  string  $origen  'prueba' o 'plancha'.
     * @param  int  $pliegos  Pliegos perdidos por este origen.
     * @param  float  $porcentaje  Porcentaje que se aplico sobre los pliegos
     *                              netos. Sale del papel, no de un supuesto.
     * @param  float  $porcentajeReal  Porcentaje que se midio de verdad en esta
     *                                 corrida. Es `NULL` cuando no hubo corrida.
     */
    private function __construct(
        public readonly string $origen,
        public readonly int $pliegos,
        public readonly float $porcentaje,
        public readonly ?float $porcentajeReal = null,
    ) {
    }

    public static function dePrueba(int $pliegos, float $porcentaje): self
    {
        return new self('prueba', $pliegos, $porcentaje);
    }

    public static function dePlancha(int $pliegos, float $porcentaje): self
    {
        return new self('plancha', $pliegos, $porcentaje);
    }

    /** Crea una pieza del desglose con la merma REAL de la corrida. */
    public function conPorcentajeReal(float $porcentajeReal): self
    {
        return new self($this->origen, $this->pliegos, $this->porcentaje, $porcentajeReal);
    }

    public function etiqueta(): string
    {
        return match ($this->origen) {
            'prueba' => 'Papel de prueba',
            'plancha' => 'Papel danado en la plancha',
            default => $this->origen,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'origen' => $this->origen,
            'etiqueta' => $this->etiqueta(),
            'pliegos' => $this->pliegos,
            'porcentaje' => $this->porcentaje,
            'porcentaje_real' => $this->porcentajeReal,
        ];
    }
}
