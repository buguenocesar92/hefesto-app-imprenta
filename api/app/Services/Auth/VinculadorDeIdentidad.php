<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Une la identidad que trae Authentik con el usuario de esta base.
 *
 * POR QUE EXISTE UNA TABLA DE USUARIOS SI EL IDENTIDAD ESTA AFUERA
 *
 * El correo identifica a la persona, pero NO dice que pueda hacer aca. Un
 * mismo correo puede ser administrador en una imprenta y solo preparador en
 * otra. Por eso la persona tiene una fila por empresa, con su rol.
 *
 * Y POR QUE EL ACCESO PREGUNTA POR LA EMPRESA PRIMERO
 *
 * Como el correo es unico por empresa, un correo puede tener N filas. Si el
 * acceso no preguntara cual, habria que adivinar, y adivinar a que
 * imprenta entra alguien es exactamente el error que el aislamiento evita en
 * todos los otros casos. Por eso `AuthController::empresas()` lee la vista
 * `vw_impresas_publicas` y el selector manda el `slug` de la empresa elegida.
 *
 * Esta aplicacion NO crea personas por su cuenta: si el correo no existe
 * para esa empresa, el acceso se rechaza. El alta es una decision
 * administrativa, no un efecto de haber hecho clic en un boton.
 */
class VinculadorDeIdentidad
{
    /**
     * @param  array<string, mixed>  $reclamos  Claims verificados del token.
     */
    public function vincular(array $reclamos, ?string $empresaSlug = null): Usuario
    {
        $correo = strtolower(trim((string) ($reclamos['email'] ?? '')));

        if ($correo === '') {
            throw new \RuntimeException('El token de identidad no trae correo.');
        }

        // Que este en el grupo de operadores es una condicion de entrada al
        // proveedor, pero se vuelve a comprobar aca: el grupo puede haber
        // cambiado entre que se genero el token y que se usa.
        $grupos = $this->grupos($reclamos);
        $grupoOperadores = (string) config('authentik.grupo_operadores');

        if (! in_array($grupoOperadores, $grupos, true)) {
            throw new \RuntimeException(
                sprintf('La cuenta no pertenece al grupo "%s" de Hefesto Studio.', $grupoOperadores)
            );
        }

        $consulta = Usuario::query()->where('email', $correo);

        if ($empresaSlug !== null && $empresaSlug !== '') {
            $empresa = Tenant::query()->where('slug', $empresaSlug)->where('activo', true)->first();

            if ($empresa === null) {
                throw new \RuntimeException('La empresa elegida no existe o esta inactiva.');
            }

            $consulta->where('tenant_id', $empresa->id);
        }

        $usuario = $consulta->first();

        if ($usuario === null) {
            throw new \RuntimeException(
                sprintf(
                    'La cuenta %s no esta dada de alta en esta aplicacion. '
                    . 'Pide al administrador que la registre.',
                    $correo
                )
            );
        }

        if (! $usuario->activo) {
            throw new \RuntimeException('Esta cuenta esta desactivada.');
        }

        // Se deja constancia del ultimo acceso. No se guarda el token: es una
        // credencial y en la tabla no va.
        $usuario->forceFill(['updated_at' => now()])->saveQuietly();

        Log::info('Acceso concedido.', [
            'usuario' => $usuario->id,
            'empresa' => $usuario->tenant_id,
            'rol' => $usuario->rol->value,
        ]);

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $reclamos
     * @return list<string>
     */
    private function grupos(array $reclamos): array
    {
        $grupos = $reclamos['groups'] ?? [];

        if (is_string($grupos)) {
            $grupos = array_filter(array_map('trim', explode(',', $grupos)));
        }

        if (! is_array($grupos)) {
            return [];
        }

        return array_values(array_map(static fn ($g): string => trim((string) $g), $grupos));
    }

    /**
     * Cuantas empresas tiene la persona con ese correo.
     *
     * Lo usa el acceso para decidir si tiene que preguntar o puede entrar
     * directo.
     */
    public function empresasDe(string $correo): int
    {
        return (int) DB::table('usuarios')
            ->where('email', strtolower(trim($correo)))
            ->where('activo', true)
            ->count();
    }
}
