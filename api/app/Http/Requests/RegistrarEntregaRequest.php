<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro de una entrega, parcial o total.
 *
 * `cantidad_unidades` tiene que ser mayor que cero y NO puede superar el
 * saldo. El saldo lo comprueba `ServicioDeEntregas`, que ademas conoce la
 * tolerancia por sobreentrega; aqui solo se fija la regla evidentes: no se
 * acepta una cantidad negativa ni cero.
 */
class RegistrarEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeRegistrarEntrega() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cantidad_unidades' => ['required', 'integer', 'min:1'],

            'medio' => ['required', 'string', Rule::in(['retiro', 'envio', 'mostrador'])],

            'entregado_por' => ['nullable', 'string', 'max:160'],
            'entregado_en' => ['nullable', 'date'],

            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cantidad_unidades.required' => 'Hay que indicar cuantas piezas se entregan.',
            'cantidad_unidades.min' => 'Una entrega de cero piezas deja al pedido igual y oculta el problema.',
            'medio.required' => 'Hay que indicar si se retira, se envia o se entrega en el mostrador.',
            'medio.in' => 'El medio de entrega tiene que ser retiro, envio o mostrador.',
            'entregado_en.date' => 'La fecha de entrega no tiene un formato valido.',
        ];
    }
}
