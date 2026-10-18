<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de una corrida de produccion.
 *
 * EL FORMULARIO NO RECIBE LOS PLIEGOS, Y ESO ES LO IMPORTANTE.
 *
 * Se pide solo `producto_bueno`, que es un conteo real de la maquina. Los
 * pliegos los calcula `ServicioDeProduccion` con el desglose, porque si se
 * recibieran a mano el informe de merma seria tan inventado como la merma
 * que dice medir.
 *
 * Un formulario que pide "cuantos pliegos gastaste" y acepta cualquier
 * numero produce un informe de merma que no sirve para cotizar.
 */
class RegistrarProduccionRequest extends FormRequest
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
            // Conteo de piezas buenas de la corrida.
            'producto_bueno' => ['nullable', 'integer', 'min:0'],

            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'producto_bueno.integer' => 'El conteo de piezas buenas tiene que ser un numero entero.',
            'producto_bueno.min' => 'El conteo no puede ser negativo.',
            'notas.max' => 'La nota es demasiado larga.',
        ];
    }
}
