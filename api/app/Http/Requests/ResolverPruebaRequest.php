<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoPrueba;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Respuesta del cliente a una prueba.
 *
 * El formulario NO pide "aprobar" en binario. Pide el ESTADO, y el estado
 * aprobado exige un comentario opcional, pero el registro de QUIEN lo
 * registro lo pone el servidor.
 *
 * Por que no un campo booleano: un formulario con "aprobado: si/no" invita a
 * que alguien marque "si" sin haber preguntado. Con un estado explicito y
 * `resuelta_en`, la traza dice que paso.
 */
class ResolverPruebaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeAprobarPruebas() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::in([
                EstadoPrueba::APROBADA->value,
                EstadoPrueba::RECHAZADA->value,
            ])],

            'comentario' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Hay que registrar la respuesta del cliente.',
            'estado.in' => 'La respuesta tiene que ser que la aprueba o que la rechazo.',
            'comentario.max' => 'El comentario es demasiado largo.',
        ];
    }
}
