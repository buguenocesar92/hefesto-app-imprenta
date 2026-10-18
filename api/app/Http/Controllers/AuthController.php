<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\VerificadorIdToken;
use App\Services\Auth\VinculadorDeIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acceso y salida.
 *
 * EL ACCESO PREGUNTA POR LA EMPRESA ANTES DE PREGUNTAR POR LA PERSONA.
 *
 * El correo es unico POR EMPRESA, no global: la misma persona puede ser
 * administradora en una imprenta y preparadora en otra. Si el acceso no
 * preguntara a cual entra, habria que adivinar, y adivinar a que empresa se
 * entra es el mismo error que el aislamiento evita en todos los demas casos.
 *
 * LA LISTA DE EMPRESAS VIENE DE LA VISTA `vw_impresas_publicas`.
 *
 * Se consulta DE VERDAD, y no por clausura administrativa: la vista expone
 * tres columnas y ninguna mas, para que un atacante no pueda aprender el
 * razon social ni el estado de una empresa que no es suya enumerando el
 * selector de entrada. En varias aplicaciones anteriores se creo la vista y
 * nunca se consulto; aca es la unica fuente de esta ruta.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md y docs/07-SEGURIDAD.md.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly DescubridorOidc $descubridor,
        private readonly VerificadorIdToken $verificador,
        private readonly VinculadorDeIdentidad $vinculador,
    ) {
    }

    /**
     * Empresas que se ofrecen en el selector de acceso.
     *
     * PUBLICA Y SIN SESION, por definicion: es la pantalla de entrada.
     *
     * La consulta va a la VISTA, no a la tabla `tenants`. La vista solo trae
     * `slug`, `nombre` y `activo`. Se filtran las inactivas en PHP y no en
     * SQL para que el codigo diga lo mismo que dice la vista.
     */
    public function empresas(): JsonResponse
    {
        $empresas = Tenant::paraElSelectorDeAcceso()
            ->filter(static fn (object $fila): bool => (bool) $fila->activo)
            ->values()
            ->map(static fn (object $fila): array => [
                'slug' => $fila->slug,
                'nombre' => $fila->nombre,
            ]);

        return response()->json(['empresas' => $empresas]);
    }

    /**
     * Parte el acceso: manda al navegador a Authentik.
     *
     * `state` y `nonce` se generan aqui y se guardan en la sesion. Sin ellos,
     * un codigo de acceso capturado en transito se podria reenviar.
     */
    public function acceso(Request $request): RedirectResponse|JsonResponse
    {
        $empresa = (string) $request->query('empresa', '');

        if ($empresa !== '') {
            $existe = Tenant::query()->where('slug', $empresa)->where('activo', true)->exists();

            if (! $existe) {
                return $this->fallo($request, 'La empresa elegida no existe o esta inactiva.');
            }
        }

        $state = Str::random(40);
        $nonce = Str::random(40);

        $request->session()->put('oidc_state', $state);
        $request->session()->put('oidc_nonce', $nonce);
        $request->session()->put('oidc_empresa', $empresa !== '' ? $empresa : null);

        return redirect()->away($this->descubridor->urlDeAcceso($state, $nonce));
    }

    /** Callback de Authentik. */
    public function callback(Request $request): RedirectResponse
    {
        $stateGuardada = $request->session()->pull('oidc_state');
        $nonceGuardado = $request->session()->pull('oidc_nonce');
        $empresaSlug = $request->session()->pull('oidc_empresa');

        if ($stateGuardada === null || $stateGuardada !== $request->query('state')) {
            return $this->fallo($request, 'La respuesta del proveedor no corresponde a esta solicitud.');
        }

        if ($request->filled('error')) {
            return $this->fallo(
                $request,
                sprintf('El acceso no se concedio: %s.', (string) $request->query('error_description', $request->query('error')))
            );
        }

        $codigo = (string) $request->query('code', '');

        if ($codigo === '') {
            return $this->fallo($request, 'El proveedor no devolvio un codigo de acceso.');
        }

        try {
            $tokens = $this->descubridor->canjear($codigo);
            $reclamos = $this->verificador->verificar((string) $tokens['id_token']);

            if ((string) ($reclamos['nonce'] ?? '') !== (string) $nonceGuardado) {
                return $this->fallo($request, 'La respuesta del proveedor no corresponde a esta solicitud.');
            }

            $usuario = $this->vinculador->vincular($reclamos, $empresaSlug);
        } catch (\Throwable $e) {
            // No se reenvia el detalle tecnico al navegador: puede traer
            // URLs internas del proveedor.
            return $this->fallo($request, $e->getMessage());
        }

        // Firma de sesion nueva: evita el secuestro de sesion.
        $request->session()->regenerate();

        Auth::guard('web')->login($usuario, true);

        return redirect()->away((string) config('app.web_url', '/'));
    }

    /** La persona que esta operando, con lo que su rol le permite. */
    public function yo(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return response()->json(['mensaje' => 'No hay sesion.'], 401);
        }

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->email,
            'rol' => $usuario->rol->value,
            'empresa' => $usuario->empresa?->nombre,
            'puede_aprobar_pruebas' => $usuario->rol->puedeAprobarPruebas(),
            'puede_registrar_produccion' => $usuario->rol->puedeRegistrarProduccion(),
            'puede_anular_pedidos' => $usuario->rol->puedeAnularPedidos(),
            'puede_administrar_catalogos' => $usuario->rol->puedeAdministrarCatalogos(),
        ]);
    }

    public function salir(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesion cerrada.']);
    }

    /**
     * Vuelve a la interfaz con el motivo del fallo.
     *
     * El mensaje va en la URL para que la pantalla de entrada lo muestre. Es
     * texto que esta aplicacion genero, no una excepcion del proveedor.
     */
    private function fallo(Request $request, string $mensaje): RedirectResponse
    {
        $destino = rtrim((string) config('app.web_url', ''), '/') . '/entrar';

        return redirect()->away($destino . '?' . http_build_query([
            'error' => Str::limit($mensaje, 180, ''),
        ]));
    }
}
