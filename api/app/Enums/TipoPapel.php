<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\ErrorDeImprenta;

/**
 * Tipo de papel.
 *
 * NO ES TEXTO LIBRE. El tipo cambia el costo y lo que el cliente puede
 * pedir: un opal no se usa para una tarjeta que se manosea, y un coated no
 * es lo mismo que un offset en precio ni en resultado.
 *
 * Los gramajes mas pedidos en una imprenta de PyME chilena son 80, 120 y
 * 300 g/m2, pero el sistema no los restringe: son datos del catalogo, no una
 * lista cerrada en el codigo. Lo que si esta acotado es que el gramaje sea
 * mayor que cero (lo valida un CHECK en PostgreSQL).
 */
enum TipoPapel: string
{
    /** Offset: sin recubrimiento. Para el dorso y para tiradas grandes. */
    case OFFSET = 'offset';

    /** Coated (estucado): con recubrimiento. Color vivo, mas caro. */
    case COATED = 'coated';

    /** Opal: translucido. Se usa para cuadernos y separadores. */
    case OPAL = 'opal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OFFSET => 'Offset',
            self::COATED => 'Coated',
            self::OPAL => 'Opal',
        };
    }

    /** @throws ErrorDeImprenta si el tipo no existe. */
    public static function desdeTexto(string $valor): self
    {
        return self::tryFrom($valor) ?? throw new ErrorDeImprenta(
            sprintf('El tipo de papel "%s" no existe. Use: %s.',
                $valor,
                implode(', ', array_column(self::cases(), 'value'))
            ),
            'tipo_papel_invalido'
        );
    }
}
