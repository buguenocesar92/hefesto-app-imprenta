<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\ErrorDeImprenta;

/**
 * Estado de una prueba (mockup).
 *
 * La maqueta es lo que el cliente ve antes de que se imprima. Este enum es
 * el corazon del producto: `APROBADA` es lo unico que habilita la
 * produccion, y no se puede llegar a `APROBADA` desde otro camino.
 *
 * `VENCIDA` NO es un estado: es la ausencia de respuesta con la fecha vencida.
 * Se calcula con `dias_para_vencer` de `config/imprenta.php` y se informa,
 * pero un pedido con la prueba vencida sigue SIN aprobar. Un sistema que
 * aprueba por silencio es un sistema que reimprime.
 */
enum EstadoPrueba: string
{
    /** Se pidio la prueba, todavia no se genero. */
    case PENDIENTE = 'pendiente';

    /** La prueba se genero y se mostro al cliente. */
    case ENVIADA = 'enviada';

    /** El cliente la aprobo. Este es el unico estado que habilita imprimir. */
    case APROBADA = 'aprobada';

    /** El cliente la rechazo: hay que corregir el arte y mandar otra. */
    case RECHAZADA = 'rechazada';

    public function habilitaProduccion(): bool
    {
        return $this === self::APROBADA;
    }

    /** @throws ErrorDeImprenta si el estado no existe. */
    public static function desdeTexto(string $valor): self
    {
        return self::tryFrom($valor) ?? throw new ErrorDeImprenta(
            sprintf('El estado de prueba "%s" no existe.', $valor),
            'estado_prueba_invalido'
        );
    }
}
