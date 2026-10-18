<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ErrorDeImprenta;

/**
 * Cantidades del taller: pliegos, kilos, piezas, porcentajes.
 *
 * Los numeros de una imprenta son fracciones. Un pliego pesa 560 gramos; tres
 * cuartos de pliego, 420. Un porcentaje de merma de 3,75 % son 3,75, no 3. El
 * error de redondeo se acumula por el camino y termina inventando papel que
 * no existe, asi que aca se concentran las reglas:
 *
 *  - `redondearPliego()`: el pliego es entero y NO se puede pagar medio
 *    pliego. Siempre hacia arriba: al piso le falta papel para un trabajo.
 *  - `pliegosParaKilos()` y `kilosParaPliegos()`: la compra en kilos se
 *    redondea hacia arriba y la conversion a kilos se conserva con decimales
 *    (un kilo es una unidad continua, no un conteo).
 *  - `porcentaje()`: rechaza valores fuera de 0..100 en lugar de recortarlos.
 *    Un -5 % de merma es un dato malo, no un -5 %.
 */
final class Cantidad
{
    /** Decimales con que se guardan los kilos. */
    public const DECIMALES_KILOS = 3;

    private function __construct()
    {
    }

    /**
     * Redondea hacia arriba a un pliego entero.
     *
     * Si el resultado es 0, devuelve 1: pedir 0 pliegos de papel es pedir
     * papel y que no te manden nada.
     */
    public static function redondearPliego(float $valor): int
    {
        if ($valor <= 0.0) {
            return 1;
        }

        return (int) ceil($valor - 0.0000001);
    }

    /** Redondea a kilos con los decimales de la compra. */
    public static function redondearKilos(float $valor): float
    {
        return round(max(0.0, $valor), self::DECIMALES_KILOS);
    }

    /**
     * Kilos que pesan `$pliegos` pliegos, dado el peso de uno.
     *
     * @throws ErrorDeImprenta si el peso por pliego no es positivo, porque
     *                          significaria que la plancha y el gramaje estan
     *                          mal configurados en el catalogo.
     */
    public static function kilosParaPliegos(int $pliegos, float $gramosPorPliego): float
    {
        if ($gramosPorPliego <= 0.0) {
            throw new ErrorDeImprenta(
                'El peso por pliego quedo en cero o menos. Revise el gramaje y '
                . 'la medida de la plancha de este papel.',
                'papel_sin_peso_unitario'
            );
        }

        return self::redondearKilos(($pliegos * $gramosPorPliego) / 1000.0);
    }

    /**
     * Kilos redondeados hacia arriba que hay que comprar para cubrir una
     * cantidad de pliegos. Alguien tiene que pagar eso, y paga de a kilo.
     */
    public static function kilosParaCubrir(float $gramosPorPliego, int $pliegos): float
    {
        if ($gramosPorPliego <= 0.0) {
            throw new ErrorDeImprenta(
                'El peso por pliego quedo en cero o menos. Revise el gramaje y '
                . 'la medida de la plancha de este papel.',
                'papel_sin_peso_unitario'
            );
        }

        return self::redondearKilos($pliegos * $gramosPorPliego / 1000.0);
    }

    /**
     * Valida un porcentaje de merma.
     *
     * @throws ErrorDeImprenta si esta fuera de rango o no es un numero.
     */
    public static function porcentaje(?float $valor, string $campo): float
    {
        if ($valor === null) {
            throw new ErrorDeImprenta(
                sprintf(
                    'El campo "%s" no esta configurado en este papel. Sin el, '
                    . 'no se puede calcular cuanto papel se va a perder. '
                    . 'Carguelo con el historial real de la imprenta.',
                    $campo
                ),
                'merma_no_configurada'
            );
        }

        if ($valor < 0.0 || $valor > 100.0) {
            throw new ErrorDeImprenta(
                sprintf('El campo "%s" esta en %.2f %% y deberia estar entre 0 y 100.', $campo, $valor),
                'merma_fuera_de_rango'
            );
        }

        return $valor;
    }

    /**
     * Un porcentaje sobre una base, redondeado a entero SIEMPRE hacia arriba.
     *
     * La merma son pliegos que se pierden: no se redondea hacia abajo porque
     * al piso "sobra" papel que en realidad no se compro.
     */
    public static function aplicarPorcentaje(float $base, float $porcentaje): int
    {
        return self::redondearPliego($base * ($porcentaje / 100.0));
    }

    /** Formatea kilos para mostrar, en el formato chileno (coma decimal). */
    public static function kilosParaMostrar(float $kilos): string
    {
        return number_format($kilos, self::DECIMALES_KILOS, ',', '.') . ' kg';
    }
}
