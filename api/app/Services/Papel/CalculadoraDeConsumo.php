<?php

declare(strict_types=1);

namespace App\Services\Papel;

use App\Exceptions\ErrorDeImprenta;
use App\Models\Papel;
use App\Models\PedidoItem;
use App\Models\Plancha;
use App\Support\Cantidad;

/**
 * Calcula cuanto papel necesita un item de pedido.
 *
 * POR QUE NO HAY UN FACTOR DE CONVERSION FIJO
 * ============================================
 *
 * El papel se compra en PLIEGOS (a veces en KILOS) y el trabajo sale en
 * UNIDADES. Entre uno y otro hay dos conversiones, y ninguna es una
 * constante:
 *
 * PLIEGO -> UNIDAD
 *   Depende de la medida de la plancha (la hoja entera) y de la medida de la
 *   pieza que se imprime. En una plancha de 700 x 1000 mm salen 77 x 200 =
 *   15.400 tarjetas de 9 x 5 mm. En la misma plancha, un afiche de 600 x
 *   900 mm no cabe ni uno. El mismo papel rinde 15.400 piezas o cero segun
 *   el trabajo, y por eso el factor NO vive en el catalogo de papeles ni en
 *   un parametro de configuracion: se calcula por cada item.
 *
 *   Ademas se prueban las dos orientaciones. Una pieza de 900 x 600 mm
 *   ordinarily no cabe en una plancha de 700 x 1000 mm, pero ROTADA si, y
 *   se elige la que rinde mas.
 *
 * KILO -> PLIEGO
 *   Depende del AREA de la plancha y del GRAMAJE. Una plancha de 0,7 m2 a
 *   80 g/m2 pesa 560 g: en un kilo entran 1,78 pliegos. La misma plancha a
 *   300 g/m2 pesa 2.100 g: en un kilo no entra ni uno. El gramaje solo cambia
 *   el factor.
 *
 * POR QUE SE DEVUELVE UN DESGLOSE Y NO UN NUMERO
 *
 * Porque el cliente va a preguntar. "Por que 42 pliegos" tiene que poder
 * contestarse paso a paso, o el taller se queda sin respuesta y la
 * cotizacion no se firma. Ver `DesgloseDeConsumo::pasos`.
 */
final class CalculadoraDeConsumo
{
    public function __construct(private readonly CalculadoraDeMerma $calculadoraDeMerma)
    {
    }

    /**
     * Desglose completo del consumo de un item.
     *
     * @param  int|null  $pliegosPruebaReales  Si la corrida ya se cerro, la
     *                                          merma medida de verdad. Se usa
     *                                          para comparar lo previsto con
     *                                          lo ocurido.
     * @param  int|null  $pliegosMermaReales  Idem, para el papel danado.
     *
     * @throws ErrorDeImprenta si la pieza no cabe en la plancha o si al papel
     *                          le falta un porcentaje de merma.
     */
    public function calcular(
        PedidoItem $item,
        ?int $pliegosPruebaReales = null,
        ?int $pliegosMermaReales = null,
    ): DesgloseDeConsumo {
        $papel = $item->papel;

        if ($papel === null) {
            throw new ErrorDeImprenta(
                'El item no tiene un papel asociado: no se puede calcular el consumo.',
                'item_sin_papel'
            );
        }

        $plancha = $papel->plancha;

        if ($plancha === null) {
            throw new ErrorDeImprenta(
                sprintf('El papel "%s" no tiene una plancha con medida.', $papel->codigo),
                'papel_sin_plancha'
            );
        }

        $pasos = [];

        // --- Paso 1: rendimiento de la plancha -------------------------
        [$columnas, $filas, $porPliego, $orientacion, $mejorRinde] =
            $this->mejorDisposicion($plancha, $item);

        if ($mejorRinde === false) {
            throw new ErrorDeImprenta(
                sprintf(
                    'La pieza de %d x %d mm no cabe en la plancha de %d x %d mm, '
                    . 'ni normal ni rotada.',
                    $item->ancho_mm,
                    $item->alto_mm,
                    $plancha->ancho_mm,
                    $plancha->alto_mm
                ),
                'la_pieza_no_cabe_en_la_plancha'
            );
        }

        $pasos[] = sprintf(
            'Plancha %s de %d x %d mm; pieza de %d x %d mm.',
            $plancha->codigo,
            $plancha->ancho_mm,
            $plancha->alto_mm,
            $item->ancho_mm,
            $item->alto_mm
        );

        $pasos[] = sprintf(
            'Mejor ajuste: %s, %d columnas x %d filas = %d piezas por cara.',
            $orientacion,
            $columnas,
            $filas,
            $porPliego
        );

        $pasos[] = sprintf(
            'Agrupacion de %d pieza(s) por cara: %d unidades por pliego.',
            $item->n_por_ventana,
            $porPliego
        );

        // --- Paso 2: pliegos netos ------------------------------------
        $pliegosNetos = Cantidad::redondearPliego($item->cantidad_unidades / $porPliego);

        $pasos[] = sprintf(
            'Pliegos netos: %d piezas pedidas / %d por pliego = %.4f -> %d pliegos.',
            $item->cantidad_unidades,
            $porPliego,
            $item->cantidad_unidades / $porPliego,
            $pliegosNetos
        );

        // --- Paso 3: merma ---------------------------------------------
        $mermas = $this->calculadoraDeMerma->calcular(
            $pliegosNetos,
            $papel->merma_prueba_porcentaje !== null
                ? (float) $papel->merma_prueba_porcentaje
                : null,
            $papel->merma_plancha_porcentaje !== null
                ? (float) $papel->merma_plancha_porcentaje
                : null,
            $pliegosPruebaReales,
            $pliegosMermaReales
        );

        $pliegosDeMerma = 0;

        foreach ($mermas as $merma) {
            $pliegosDeMerma += $merma->pliegos;

            $pasos[] = sprintf(
                'Merma de %s: %.2f %% de %d pliegos = %d pliegos.',
                mb_strtolower($merma->etiqueta()),
                $merma->porcentaje,
                $pliegosNetos,
                $merma->pliegos
            );
        }

        $pliegosTotales = $pliegosNetos + $pliegosDeMerma;

        $pasos[] = sprintf(
            'Total a gastar: %d netos + %d de merma = %d pliegos.',
            $pliegosNetos,
            $pliegosDeMerma,
            $pliegosTotales
        );

        // --- Paso 4: peso y kilos --------------------------------------
        $gramosPorPliego = $this->gramosPorPliego($papel, $plancha);
        $kilosPorPliego = Cantidad::redondearKilos($gramosPorPliego / 1000.0);
        $kilosRequeridos = Cantidad::kilosParaCubrir($gramosPorPliego, $pliegosTotales);

        $pasos[] = sprintf(
            'Peso: %d g/m2 x %.4f m2 = %.1f g por pliego.',
            $papel->gramaje_gm2,
            $this->areaEnMetros($plancha),
            $gramosPorPliego
        );

        $pasos[] = sprintf(
            'A comprar: %d pliegos = %s (%d kg redondeados hacia arriba).',
            $pliegosTotales,
            Cantidad::kilosParaMostrar($kilosRequeridos),
            (int) ceil($kilosRequeridos)
        );

        return new DesgloseDeConsumo(
            unidadesPedidas: $item->cantidad_unidades,
            unidadesPorPliego: $porPliego,
            orientacion: $orientacion,
            columnas: $columnas,
            filas: $filas,
            porVentana: $item->n_por_ventana,
            pliegosNetos: $pliegosNetos,
            mermas: $mermas,
            pliegosTotales: $pliegosTotales,
            pasos: $pasos,
            gramosPorPliego: $gramosPorPliego,
            kilosRequeridos: $kilosRequeridos,
            kilosPorPliego: $kilosPorPliego,
        );
    }

    /**
     * Gramos que pesa un pliego de este papel.
     *
     * Es la unica parte del calculo que es FISICA y no regla del negocio: un
     * metro cuadrado de papel de 80 g/m2 pesa 80 gramos, sin importar el
     * proveedor ni el tipo. Por eso sale del area y el gramaje y no de un
     * factor configurado.
     */
    public function gramosPorPliego(Papel $papel, Plancha $plancha): float
    {
        return $this->areaEnMetros($plancha) * $papel->gramaje_gm2;
    }

    private function areaEnMetros(Plancha $plancha): float
    {
        // Milimetros a metros, y el area.
        return (($plancha->ancho_mm / 1000.0) * ($plancha->alto_mm / 1000.0));
    }

    /**
     * Mejor ajuste de la pieza en la plancha, probando las dos orientaciones.
     *
     * Se prueban las dos porque una pieza rectangular puede no caber en la
     * plancha normal y caber girada. Elegir siempre la normal haria perder
     * plancha.
     *
     * @return array{0: int, 1: int, 2: int, 3: string, 4: bool}
     */
    private function mejorDisposicion(Plancha $plancha, PedidoItem $item): array
    {
        $normal = $this->disposicion(
            $plancha->ancho_mm,
            $plancha->alto_mm,
            $item->ancho_mm,
            $item->alto_mm
        );

        $rotada = $this->disposicion(
            $plancha->ancho_mm,
            $plancha->alto_mm,
            $item->alto_mm,
            $item->ancho_mm
        );

        if ($rotada > $normal) {
            return [$rotada[0], $rotada[1], $rotada[2] * $item->n_por_ventana, 'rotada', true];
        }

        if ($normal > 0) {
            return [$normal[0], $normal[1], $normal[2] * $item->n_por_ventana, 'normal', true];
        }

        return [0, 0, 0, 'ninguna', false];
    }

    /**
     * Columnas, filas y piezas por cara de una disposicion dada.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function disposicion(
        int $planchaAncho,
        int $planchaAlto,
        int $piezaAncho,
        int $piezaAlto
    ): array {        $columnas = intdiv($planchaAncho, $piezaAncho);
        $filas = intdiv($planchaAlto, $piezaAlto);

        if ($columnas < 1 || $filas < 1) {
            return [0, 0, 0];
        }

        return [$columnas, $filas, $columnas * $filas];
    }
}
