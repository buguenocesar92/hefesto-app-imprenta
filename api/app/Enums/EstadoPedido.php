<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\ErrorDeImprenta;

/**
 * Estado de un pedido de produccion.
 *
 * LA SEPARACION QUE IMPORTA
 * -------------------------
 * `ESPERANDO_APROBACION` y `APROBADO` son estados DISTINTOS y no se pueden
 * saltar. Un pedido sin prueba aprobada no puede pasar a `APROBADO`, y nada
 * se imprime sin estar `APROBADO`.
 *
 * Por que son dos estados y no uno: en una imprenta lo que se vende es una
 * produccion con una prueba aprobada y una fecha prometida. Si el sistema
 * no distingue "el cliente todavia no vio el diseno" de "el cliente lo
 * aprobo", el taller reimprime lo que el cliente no quiso, y ese es el
 * error mas caro del rubro.
 *
 * `PARCIALMENTE_ENTREGADO` es otro estado propio, no una bandera: un pedido
 * de 20.000 que se entrego en dos no esta entregado. Ver
 * `App\Services\Entregas\ServicioDeEntregas`.
 */
enum EstadoPedido: string
{
    /** Se esta cotizando. No se imprime nada en este estado. */
    case BORRADOR = 'borrador';

    /** El cliente recibio la prueba y se espera su respuesta. */
    case ESPERANDO_APROBACION = 'esperando_aprobacion';

    /** Todas las pruebas estan aprobadas. Se puede imprimir. */
    case APROBADO = 'aprobado';

    /** Se esta imprimiendo. */
    case EN_PRODUCCION = 'en_produccion';

    /** Se entrego parte. El saldo sigue vivo. */
    case PARCIALMENTE_ENTREGADO = 'parcialmente_entregado';

    /** Lo entregado cubre lo pedido. */
    case ENTREGADO = 'entregado';

    /** Se cancelo. Un pedido anulado no se imprime ni se entrega. */
    case ANULADO = 'anulado';

    /** No se puede imprimir mientras este en este estado. */
    public function admiteProduccion(): bool
    {
        return $this === self::APROBADO || $this === self::EN_PRODUCCION;
    }

    /** No se puede registrar una entrega en este estado. */
    public function admiteEntrega(): bool
    {
        return in_array(
            $this,
            [self::APROBADO, self::EN_PRODUCCION, self::PARCIALMENTE_ENTREGADO],
            true
        );
    }

    /** El pedido todavia esta vivo: no entregado ni cancelado. */
    public function estaAbierto(): bool
    {
        return $this !== self::ENTREGADO && $this !== self::ANULADO;
    }

    /** @throws ErrorDeImprenta si el estado no es valido para el pedido. */
    public static function desdeTexto(string $valor): self
    {
        return self::tryFrom($valor) ?? throw new ErrorDeImprenta(
            sprintf('El estado de pedido "%s" no existe.', $valor),
            'estado_pedido_invalido'
        );
    }
}
