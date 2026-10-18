<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja constancia de las operaciones que mueven plata o cambian lo que la
 * imprenta le respondio al cliente.
 *
 * Que se registra y que no:
 *
 * Si: aprobacion de pruebas, anulacion de pedidos, movimientos de libreria.
 * Son las acciones despues de las cuales alguien va a preguntar "por que".
 *
 * No: consultar una lista o abrir un pedido. Registrarlas todas llena el
 * registro de ruido y lo vuelve ilegible, que es la forma mas comun de que
 * un registro de auditoria no sirva para nada.
 *
 * NO SE REGISTRA EL CONTENIDO DEL ARTE NI EL DEL PEDIDO: solo la ruta, el
 * usuario y el recurso. En una imprenta el archivo del cliente es material
 * con datos personales.
 */
class AuditarOperacion
{
    /** Rutas cuyo POST o PATCH cambia el estado de una operacion. */
    private const RUTAS_AUDITADAS = [
        'api/v1/pruebas',
        'api/v1/produccion',
        'api/v1/entregas',
        'api/v1/pedidos',
        'api/v1/libreria',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if (! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return $respuesta;
        }

        $ruta = '/' . ltrim($request->path(), '/');

        $esAuditada = false;

        foreach (self::RUTAS_AUDITADAS as $prefijo) {
            if (str_starts_with($ruta, $prefijo)) {
                $esAuditada = true;
                break;
            }
        }

        if (! $esAuditada) {
            return $respuesta;
        }

        /** @var Usuario|null $usuario */
        $usuario = $request->user();

        Log::info('operacion', [
            'usuario_id' => $usuario?->id,
            'empresa_id' => $usuario?->tenant_id,
            'rol' => $usuario?->rol->value,
            'metodo' => $request->method(),
            'ruta' => $ruta,
            'estado' => $respuesta->getStatusCode(),
        ]);

        return $respuesta;
    }
}
