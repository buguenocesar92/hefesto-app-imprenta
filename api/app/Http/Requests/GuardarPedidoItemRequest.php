<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta o edicion de un item de pedido.
 *
 * Lo que NO se valida aca y por que:
 *
 *  - Que la pieza quepa en la plancha: lo comprueba
 *    `CalculadoraDeConsumo`, que ademas necesita la plancha del papel y da
 *    un mensaje util.
 *  - Que el papel tenga merma configurada: tambien lo comprueba el
 *    calculador, al calcular.
 *  - Que exista una prueba aprobada: NO se valida al crear un item, porque un
 *    item se crea ANTES de tener el arte. Se valida al registrar produccion,
 *    que es cuando importa.
 *
 * Dejar estas reglas en el calculador y no en el formulario es lo que hace
 * que la misma regla se cumpla desde la interfaz, desde un comando de
 * consola y desde un futuro script de migracion de datos.
 */
class GuardarPedidoItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeRegistrarProduccion() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'descripcion' => ['required', 'string', 'min:3', 'max:200'],
            'papel_id' => ['required', 'uuid'],

            // Piezas a entregar. El sistema no pone un minimo de negocio:
            // alguien puede imprimir 200 tarjetas.
            'cantidad_unidades' => ['required', 'integer', 'min:1'],

            'ancho_mm' => ['required', 'integer', 'min:1', 'max:2000'],
            'alto_mm' => ['required', 'integer', 'min:1', 'max:2000'],

            // Agrupacion: piezas por cara de plancha.
            'n_por_ventana' => ['required', 'integer', 'min:1', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'descripcion.required' => 'Hay que escribir que se va a imprimir.',
            'descripcion.min' => 'La descripcion es demasiado corta para saber que es.',
            'papel_id.required' => 'Hay que elegir el papel.',
            'cantidad_unidades.required' => 'Hay que indicar cuantas piezas se quieren.',
            'cantidad_unidades.min' => 'La cantidad tiene que ser al menos una pieza.',
            'ancho_mm.required' => 'Hay que indicar el ancho de la pieza.',
            'alto_mm.required' => 'Hay que indicar el alto de la pieza.',
            'n_por_ventana.min' => 'La agrupacion no puede ser cero: una pieza por cara es el minimo.',
        ];
    }
}
