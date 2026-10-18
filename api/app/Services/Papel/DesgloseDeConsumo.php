<?php

declare(strict_types=1);

namespace App\Services\Papel;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Desglose de consumo de papel: la cuenta completa, paso a paso.
 *
 * POR QUE NO SE DEVUELVE SOLO UN NUMERO
 *
 * Un numero de "pliegos necesarios" es inverificable. El cliente va a
 * preguntar "por que 42 pliegos", y sin la cuenta esa pregunta no tiene
 * respuesta. Con el desglose, cada paso es discutible por separado: si el
 * rendimiento por plancha no calza, se ve; si la agrupacion esta mal puesta,
 * se ve.
 *
 * `pasos` guarda la cuenta en texto, en el orden en que se hizo. Es lo que
 * se muestra en pantalla y lo que se puede copiar a un informe.
 *
 * @implements Arrayable<string, mixed>
 */
final class DesgloseDeConsumo implements Arrayable
{
    /**
     * @param  int  $unidadesPedidas  Piezas que el cliente recibe.
     * @param  int  $unidadesPorPliego  Piezas que salen de UNA plancha.
     * @param  string  $orientacion  'normal' o 'rotada': como rindio mejor.
     * @param  int  $columnas  Columnas de piezas en la plancha.
     * @param  int  $filas  Filas de piezas en la plancha.
     * @param  int  $porVentana  Piezas por cara (agrupacion).
     * @param  int  $pliegosNetos  Pliegos sin merma.
     * @param  list<DesgloseDeMerma>  $mermas  Merma por origen.
     * @param  int  $pliegosTotales  Netos mas merma.
     * @param  list<string>  $pasos  La cuenta, en texto.
     */
    private function __construct(
        public readonly int $unidadesPedidas,
        public readonly int $unidadesPorPliego,
        public readonly string $orientacion,
        public readonly int $columnas,
        public readonly int $filas,
        public readonly int $porVentana,
        public readonly int $pliegosNetos,
        public readonly array $mermas,
        public readonly int $pliegosTotales,
        public readonly array $pasos,
        public readonly float $gramosPorPliego,
        public readonly float $kilosRequeridos,
        public readonly float $kilosPorPliego,
    ) {
    }

    /** Pliegos perdidos en total, sumando todos los origenes. */
    public function pliegosDeMerma(): int
    {
        $total = 0;

        foreach ($this->mermas as $merma) {
            $total += $merma->pliegos;
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unidades_pedidas' => $this->unidadesPedidas,
            'unidades_por_pliego' => $this->unidadesPorPliego,
            'orientacion' => $this->orientacion,
            'columnas' => $this->columnas,
            'filas' => $this->filas,
            'por_ventana' => $this->porVentana,
            'pliegos_netos' => $this->pliegosNetos,
            'pliegos_de_merma' => $this->pliegosDeMerma(),
            'pliegos_totales' => $this->pliegosTotales,
            'gramos_por_pliego' => $this->gramosPorPliego,
            'kilos_requeridos' => $this->kilosRequeridos,
            'kilos_por_pliego' => $this->kilosPorPliego,
            'mermas' => array_map(
                static fn (DesgloseDeMerma $merma): array => $merma->toArray(),
                $this->mermas
            ),
            'pasos' => $this->pasos,
        ];
    }
}
