<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\ErrorDeImprenta;

/**
 * Como se compra el papel.
 *
 * ESTA ES LA RAZON POR LA QUE LA CONVERSION NO ES UN NUMERO FIJO.
 *
 * El papel se compra en pliegos (la hoja entera de la plancha) o en kilos
 * (por peso, cuando la plancha no es estandar). El trabajo sale en
 * UNIDADES: tarjetas, hojas, afiches. Entre kilo y unidad hay que pasar por
 * dos conversionces distintas y ninguna es una constante:
 *
 *   pliego -> unidad   depende de la medida de la plancha y de la medida del
 *                     trabajo, y de cuantas piezas caben por ventana
 *                     (agrupacion). Cambia trabajo por trabajo.
 *
 *   kilo  -> pliego    depende del AREA de la plancha y del GRAMAJE:
 *                     una plancha de 70 x 100 cm a 80 g/m2 pesa 560 g, y en
 *                     un kilo entran menos de dos pliegos; la misma plancha a
 *                     300 g/m2 pesa 2.100 g y en un kilo no entra ni uno.
 *
 * Por eso no hay ningun factor global en la configuracion. El factor se
 * calcula por cada papel y por cada trabajo, y el desglose se muestra.
 * Ver `App\Services\Papel\CalculadoraDeConsumo`.
 */
enum UnidadCompraPapel: string
{
    /** A granel por hoja: se cuenta en pliegos. */
    case PLIEGO = 'pliego';

    /** A granel por peso: se cuenta en kilos. */
    case KILO = 'kilo';

    public function etiqueta(): string
    {
        return $this === self::PLIEGO ? 'Pliego' : 'Kilo';
    }

    /** @throws ErrorDeImprenta si la unidad no existe. */
    public static function desdeTexto(string $valor): self
    {
        return self::tryFrom($valor) ?? throw new ErrorDeImprenta(
            sprintf('La unidad de compra "%s" no existe. Use "pliego" o "kilo".', $valor),
            'unidad_compra_invalida'
        );
    }
}
