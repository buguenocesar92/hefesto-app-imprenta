<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Descubre la configuracion OIDC de Authentik.
 *
 * Authentik publica su configuracion en `.well-known/openid-configuration`.
 * Se lee ese documento en vez de dejar fijas las URLs de autorizacion y de
 * token: si el proveedor cambia de direccion, la aplicacion lo sigue sin
 * tocar codigo, y no queda una URL de hace dos años que ya no existe.
 *
 * Se cachea 1 hora. Una falla al leer NO se cachea: si Authentik esta caido
 * y se guardara el error, el acceso quedaria caido por una hora aunque el
 * proveedor se levantara en un minuto.
 */
class DescubridorOidc
{
    /** Segundos de cache del documento de descubrimiento. */
    private const TTL_SEGUNDOS = 3600;

    public function issuer(): string
    {
        return rtrim((string) config('authentik.issuer'), '/') . '/';
    }

    public function clientId(): string
    {
        return (string) config('authentik.client_id');
    }

    public function redirectUri(): string
    {
        return (string) config('authentik.redirect_uri');
    }

    /**
     * URL a la que se manda al navegador para entrar.
     *
     * `state` y `nonce` se generan aqui y se guardan en la sesion: son los
     * dos parametros que impiden que alguien reenvie un codigo de acceso que
     * haya capturado en transito.
     */
    public function urlDeAcceso(string $state, string $nonce): string
    {
        $documento = $this->documento();

        if (! isset($documento['authorization_endpoint'])) {
            throw new \RuntimeException(
                'El documento de Authentik no trae authorization_endpoint. '
                . 'Revise AUTHENTIK_ISSUER en el archivo de entorno.'
            );
        }

        $consulta = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => (string) config('authentik.scopes'),
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return $documento['authorization_endpoint'] . '?' . $consulta;
    }

    /** Canjea un codigo de acceso por un token de identidad. */
    public function canjear(string $codigo): array
    {
        $documento = $this->documento();

        if (! isset($documento['token_endpoint'])) {
            throw new \RuntimeException('El documento de Authentik no trae token_endpoint.');
        }

        $respuesta = Http::asForm()
            ->withBasicAuth($this->clientId(), (string) config('authentik.client_secret'))
            ->timeout(15)
            ->retry(2, 250)
            ->post($documento['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $codigo,
                'redirect_uri' => $this->redirectUri(),
            ]);

        if (! $respuesta->successful()) {
            Log::warning('Authentik no acepto el canje del codigo de acceso.', [
                'estado' => $respuesta->status(),
            ]);

            throw new \RuntimeException('El proveedor de identidad rechazo el canje del codigo.');
        }

        $cuerpo = $respuesta->json();

        if (! is_array($cuerpo) || ! isset($cuerpo['id_token'])) {
            throw new \RuntimeException('El proveedor de identidad no devolvio un id_token.');
        }

        return $cuerpo;
    }

    /**
     * Llaves publicas de Authentik, para verificar la firma.
     *
     * @return array<string, mixed>
     */
    public function llavesPublicas(): array
    {
        $documento = $this->documento();
        $jwksUri = (string) ($documento['jwks_uri'] ?? '');

        if ($jwksUri === '') {
            throw new \RuntimeException('El documento de Authentik no trae jwks_uri.');
        }

        $respuesta = Http::timeout(15)->get($jwksUri);

        if (! $respuesta->successful()) {
            throw new \RuntimeException('No se pudieron obtener las llaves publicas de Authentik.');
        }

        return (array) $respuesta->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function documento(): array
    {
        return Cache::remember(
            'authentik:openid-configuration',
            self::TTL_SEGUNDOS,
            function (): array {
                $url = rtrim($this->issuer(), '/') . '/.well-known/openid-configuration';

                $respuesta = Http::timeout(15)->retry(2, 250)->get($url);

                if (! $respuesta->successful()) {
                    throw new \RuntimeException(
                        sprintf('Authentik no respondio a %s (estado %d).', $url, $respuesta->status())
                    );
                }

                $documento = $respuesta->json();

                if (! is_array($documento)) {
                    throw new \RuntimeException('El documento de Authentik tiene un formato inesperado.');
                }

                return $documento;
            }
        );
    }
}
