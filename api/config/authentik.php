<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de identidad (Authentik)
    |--------------------------------------------------------------------------
    |
    | La imprenta no maneja contrasenas: Authentik resuelve la identidad y la
    | API solo verifica el token y decide el rol.
    |
    | El issuer debe terminar en barra: Authentik lo publica asi y una
    | diferencia de una barra hace que todos los tokens parezcan de otro
    | emisor.
    |
    */

    'issuer' => rtrim((string) env('AUTHENTIK_ISSUER', ''), '/') . '/',

    'client_id' => (string) env('AUTHENTIK_CLIENT_ID', ''),

    'client_secret' => (string) env('AUTHENTIK_CLIENT_SECRET', ''),

    'redirect_uri' => (string) env('AUTHENTIK_REDIRECT_URI', ''),

    'scopes' => (string) env('AUTHENTIK_SCOPES', 'openid profile email'),

    // Algoritmo de firma. RS256 es el que usa Authentik por omision.
    'algoritmo' => (string) env('AUTHENTIK_ALGORITMO', 'RS256'),

    /*
    | Grupo de Authentik que corresponde a la administracion de la imprenta.
    |
    | Este grupo NO da permisos por si solo. El rol con el que se autoriza
    | cada accion vive en `usuarios.rol`, que es de la imprenta. El grupo
    | sirve para que el alta de personas sea auditable en el proveedor.
    */
    'grupo_administradores' => (string) env('AUTHENTIK_GRUPO_ADMIN', 'imprenta-administradores'),

    /*
    | Grupo que agrupa a todo el personal autorizado a entrar a la imprenta.
    | Si una persona no esta en este grupo, el acceso se rechaza aunque
    | exista en la base.
    */
    'grupo_operadores' => (string) env('AUTHENTIK_GRUPO_OPERADORES', 'imprenta-operadores'),

    /*
    | Sesion: en que almacenamiento queda la cookie. `database` es el
    | clasico de Laravel y permite revocar una sesion desde la base si
    | alguien pierde el equipo.
    */
    'sesion' => [
        'driver' => (string) env('SESSION_DRIVER', 'database'),
        'nombre_cookie' => (string) env('SESSION_COOKIE', 'imprenta_sesion'),
    ],

];
