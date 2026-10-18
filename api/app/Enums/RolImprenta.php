<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Niveles de autorizacion de la imprenta.
 *
 * La separacion importa mas de lo que parece: `PREPARADOR` puede preparar un
 * pedido y registrar una tirada, pero NO puede aprobar una prueba ni anular
 * un pedido. Registrar la aprobacion de una prueba y anular un pedido son las
 * dos acciones que cambian lo que la imprenta le respondio al cliente, asi
 * que quedan en el supervisor.
 *
 * Ojo con lo que significa "aprobar": la aprueba el CLIENTE. El supervisor
 * es quien REGISTRA que el cliente aprobo. Guardar ese nombre es lo que hace
 * auditable el pedido meses despues.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
enum RolImprenta: string
{
    /** Ve todo y no cambia nada. Sirve para auditar. */
    case AUDITOR = 'auditor';

    /** Prepara pedidos, carga artes y registra produccion. */
    case PREPARADOR = 'preparador';

    /** Ademas: registra aprobaciones de pruebas y anula pedidos. */
    case SUPERVISOR = 'supervisor';

    /** Configura papeles, planchas, clientes y usuarios. */
    case ADMINISTRADOR = 'administrador';

    public function puedeRegistrarProduccion(): bool
    {
        return in_array($this, [self::PREPARADOR, self::SUPERVISOR, self::ADMINISTRADOR], true);
    }

    public function puedeRegistrarEntrega(): bool
    {
        return in_array($this, [self::PREPARADOR, self::SUPERVISOR, self::ADMINISTRADOR], true);
    }

    /** Registrar la aprobacion del cliente: accion de supervisor. */
    public function puedeAprobarPruebas(): bool
    {
        return in_array($this, [self::SUPERVISOR, self::ADMINISTRADOR], true);
    }

    public function puedeAnularPedidos(): bool
    {
        return in_array($this, [self::SUPERVISOR, self::ADMINISTRADOR], true);
    }

    public function puedeAdministrarCatalogos(): bool
    {
        return $this === self::ADMINISTRADOR;
    }
}
