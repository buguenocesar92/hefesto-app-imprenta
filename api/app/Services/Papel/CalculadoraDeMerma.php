<?php

declare(strict_types=1);

namespace App\Services\Papel;

use App\Exceptions\ErrorDeImprenta;
use App\Support\Cantidad;

/**
 * Calcula la merma: el papel que se gasta sin llegar a ser producto.
 *
 * HAY DOS ORIGENES Y NO SE MEZCLAN
 *
 *   - Merma de PRUEBA: el papel que se gasta en sacar la maqueta antes de
 *     imprimir. En trabajos con muchas pruebas y tiradas cortas es la mayor
 *     parte de la perdida, y es la que el cliente no ve.
 *
 *   - Merma de PLANCHA: el papel que se dania al pasar por la maquina. Es
 *     impredecible de verdad y por eso se mide por tirada, no se supone.
 *
 * POR QUE NO HAY VALOR POR OMISION
 *
 * Esta clase NO tiene un porcentaje de respaldo. Si el papel no trae
 * `merma_prueba_porcentaje` o `merma_plancha_porcentaje`, `Cantidad::porcentaje()`
 * lanza `ErrorDeImprenta` y el calculo se niega a responder.
 *
 * Es deliberado. Si el sistema supusiera "5 % de merma", cada trabajo ganaria
 * un 5 % de papel fantasma, y para una PyME que reimprime mucho eso no es un
 * detalle de redondeo: es margen que desaparece sin que nadie lo vea. El
 * supuesto debe salir del historial de la imprenta y de ahi para cada papel.
 *
 * El `porcentaje_real` del desglose es lo que el taller MIDIO en la corrida,
 * y no se supone de antemano: comparar lo previsto contra lo medido es
 * justamente como se corrigen los porcentajes del catalogo.
 */
final class CalculadoraDeMerma
{
    /**
     * Calcula la merma de un consumo.
     *
     * @param  int  $pliegosNetos  Pliegos que salen como producto, sin merma.
     * @param  float|null  $mermaPruebaPorcentaje  Del papel. `NULL` = falla
     *                                   cerrado: no se inventa.
     * @param  float|null  $mermaPlanchaPorcentaje  Del papel. `NULL` = falla
     *                                   cerrado: no se inventa.
     * @param  int|null  $pliegosPruebaReales  Pruebas contadas de verdad en la
     *                                   corrida, si ya se cerraron.
     * @param  int|null  $pliegosMermaReales  Pliegos danados contados de
     *                                   verdad en la corrida.
     * @return list<DesgloseDeMerma>
     *
     * @throws ErrorDeImprenta si falta un porcentaje en el papel.
     */
    public function calcular(
        int $pliegosNetos,
        ?float $mermaPruebaPorcentaje,
        ?float $mermaPlanchaPorcentaje,
        ?int $pliegosPruebaReales = null,
        ?int $pliegosMermaReales = null,
    ): array {
        // Falla cerrado: sin porcentaje no hay merma calculable.
        $porcentajePrueba = Cantidad::porcentaje(
            $mermaPruebaPorcentaje,
            'merma de prueba'
        );
        $porcentajePlancha = Cantidad::porcentaje(
            $mermaPlanchaPorcentaje,
            'merma de plancha'
        );

        $mermas = [];

        $mermas[] = DesgloseDeMerma::dePrueba(
            Cantidad::aplicarPorcentaje($pliegosNetos, $porcentajePrueba),
            $porcentajePrueba
        )->conPorcentajeReal($this->porcentajeReal($pliegosPruebaReales, $pliegosNetos));

        $mermas[] = DesgloseDeMerma::dePlancha(
            Cantidad::aplicarPorcentaje($pliegosNetos, $porcentajePlancha),
            $porcentajePlancha
        )->conPorcentajeReal($this->porcentajeReal($pliegosMermaReales, $pliegosNetos));

        return $mermas;
    }

    /**
     * Porcentaje real de la corrida, o `NULL` si todavia no se midio.
     *
     * Con cero pliegos netos no hay division posible y se devuelve `NULL` en
     * vez de cero: cero seria afirmar que no se perdio nada.
     */
    private function porcentajeReal(?int $reales, int $netos): ?float
    {
        if ($reales === null || $netos <= 0) {
            return null;
        }

        return round(($reales / $netos) * 100.0, 2);
    }
}
