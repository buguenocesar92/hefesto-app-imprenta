<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edicion de un articulo de libreria.
 *
 * `precio_venta` ADMITE NULL A PROPOSITO: el precio lo carga la imprenta,
 * no esta aplicacion. Un articulo sin precio de venta aparece marcado como
 * pendiente en la pantalla, que es la verdad, en vez de mostrar un cero que
 * alguien podria leer como "es gratis".
 */
class GuardarArticuloLibreriaRequest extends FormRequest
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
            'sku' => ['required', 'string', 'min:2', 'max:40'],
            'nombre' => ['required', 'string', 'min:3', 'max:160'],
            'categoria' => ['required', 'string', 'min:3', 'max:60'],
            'unidad' => ['required', 'string', 'min:2', 'max:24'],

            'precio_compra' => ['nullable', 'numeric', 'min:0'],
            'precio_venta' => ['nullable', 'numeric', 'min:0', 'gt:precio_compra'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.required' => 'Hay que escribir el codigo del articulo.',
            'sku.unique' => 'Ya hay un articulo con ese codigo.',
            'nombre.required' => 'Hay que escribir el nombre del articulo.',
            'categoria.required' => 'Hay que escribir la categoria.',
            'precio_venta.gt' => 'El precio de venta tiene que ser mayor que el de compra.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'codigo',
            'precio_compra' => 'precio de compra',
            'precio_venta' => 'precio de venta',
        ];
    }
}
