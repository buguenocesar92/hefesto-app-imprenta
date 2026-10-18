<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de regla de imprenta.
 *
 * Son las situaciones en que la operacion es valida en forma pero invalida
 * en fondo: el pedido no tiene ninguna prueba aprobada, el papel no tiene
 * merma configurada, la entrega supera el saldo pendiente, la medida del
 * trabajo no cabe en la plancha.
 *
 * Se distinguen de los errores de validacion (422 de Laravel en el
 * FormRequest) y de los de autorizacion (404) porque la interfaz necesita
 * reaccionar a cada uno de forma distinta. Todas llevan un `codigo` estable,
 * documentado en docs/09-API.md.
 */
class ErrorDeImprenta extends RuntimeException
{
    /**
     * @param  string  $codigo  Identificador estable para la interfaz.
     *                          Ver la lista en docs/09-API.md.
     */
    public function __construct(
        string $mensaje,
        private readonly string $codigo = 'regla_de_imprenta',
    ) {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return $this->codigo;
    }
}
