<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edicion de un papel del catalogo.
 *
 * LOS PORCENTAJES DE MERMA SON OBLIGATORIOS Y NO TIENEN VALOR POR OMISION.
 *
 * La migracion los acepta en NULL a proposito (para que exista el papel
 * antes de que la imprenta sepa cuanto pierde), pero al GUARDARLOS hay que
 * cargarlos. Un papel con merma en NULL es un papel que no se puede cotizar,
 * y la pantalla lo muestra como pendiente en vez de dejarlo pasar.
 *
 * El precio de compra SI admite NULL: es informacion comercial y el sistema
 * calcula papel, no precios.
 */
class GuardarPapelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->rol->puedeAdministrarCatalogos() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'min:2', 'max:40'],
            'nombre' => ['required', 'string', 'min:2', 'max:120'],

            'tipo' => ['required', 'string', Rule::in(['offset', 'coated', 'opal'])],

            'gramaje_gm2' => ['required', 'integer', 'min:1', 'max:1000'],

            'plancha_id' => ['required', 'uuid'],

            'unidad_compra' => ['required', 'string', Rule::in(['pliego', 'kilo'])],

            // Sin valor por omision: o se cargan, o el papel queda pendiente.
            'merma_prueba_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'merma_plancha_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'precio_referencia_por_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.in' => 'El tipo de papel tiene que ser offset, coated u opal.',
            'gramaje_gm2.min' => 'El gramaje tiene que ser mayor que cero.',
            'unidad_compra.in' => 'El papel se compra por pliego o por kilo.',
            'merma_prueba_porcentaje.max' => 'La merma no puede pasar de 100 %.',
            'merma_plancha_porcentaje.max' => 'La merma no puede pasar de 100 %.',
            'plancha_id.required' => 'Hay que indicar de que plancha viene el papel: sin ella no se puede calcular nada.',
        ];
    }
}
