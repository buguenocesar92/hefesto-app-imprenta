<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;

/**
 * Verifica el token de identidad que devuelve Authentik.
 *
 * LO QUE SE VERIFICA, Y POR QUE CADA COSA
 *
 *  - La FIRMA con la llave publica del proveedor. Sin esto, cualquiera
 *    podria fabricar un token diciendo que es el administrador.
 *  - El EMISOR: que venga de donde dice venir. Un token de otra aplicacion
 *    firmada por la misma llave no sirve aca.
 *  - La AUDIENCIA: que este emitido para esta aplicacion. Sin esta
 *    comprobacion, un token legitimo de otra aplicacion de Hefesto Studio
 *    serviria para entrar a la imprenta.
 *  - El TIEMPO: un token expirado o usado despues de su `nbf` no sirve.
 *
 * Verificar la firma SIN verificar la audiencia es un error clasico: el
 * token es authenticito, pero no es para esta aplicacion.
 */
class VerificadorIdToken
{
    public function __construct(private readonly DescubridorOidc $descubridor) {}

    /**
     * Devuelve los reclamos del token ya verificado.
     *
     * @return array<string, mixed>
     */
    public function verificar(string $idToken): array
    {
        $llaves = $this->llaves();

        try {
            $reclamos = (array) JWT::decode(
                $idToken,
                JWK::parseKeySet($llaves),
                (string) config('authentik.algoritmo', 'RS256')
            );
        } catch (\Throwable $e) {
            // No se loguea el token: es una credencial.
            throw new \RuntimeException(
                'El token de identidad no es valido: ' . $e->getMessage(),
                previous: $e
            );
        }

        $this->comprobarEmisor($reclamos);
        $this->comprobarAudiencia($reclamos);

        return $reclamos;
    }

    /** @param array<string, mixed> $reclamos */
    private function comprobarEmisor(array $reclamos): void
    {
        $esperado = rtrim((string) config('authentik.issuer'), '/') . '/';
        $recibido = (string) ($reclamos['iss'] ?? '');

        if (rtrim($recibido, '/') . '/' !== $esperado) {
            throw new \RuntimeException('El token viene de un emisor que no es el configurado.');
        }
    }

    /** @param array<string, mixed> $reclamos */
    private function comprobarAudiencia(array $reclamos): void
    {
        $esperada = (string) config('authentik.client_id');
        $audiencia = $reclamos['aud'] ?? null;

        $coincide = is_array($audiencia)
            ? in_array($esperada, $audiencia, true)
            : (string) $audiencia === $esperada;

        if (! $coincide) {
            throw new \RuntimeException('El token no fue emitido para esta aplicacion.');
        }
    }

    /** @return array<string, mixed> */
    private function llaves(): array
    {
        return Cache::remember(
            'authentik:jwks',
            3600,
            fn (): array => $this->descubridor->llavesPublicas()
        );
    }
}
