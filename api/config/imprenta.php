<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Entregas parciales
    |--------------------------------------------------------------------------
    |
    | Un pedido de 20.000 se entrega en dos. El sistema no cierra un pedido
    | porque se registro una entrega: lo cierra cuando lo entregado cubre
    | lo pedido. Ese criterio esta en `ServicioDeEntregas`, no en un parametro.
    |
    | Lo que si es configurable es el margen de tolerancia cuando el papel
    | da un sobrante de la ultima plancha: es el unico lugar donde "casi
    | completo" tiene que decidirse, y la decision es de la imprenta.
    |
    */

    'entregas' => [
        'margen_tolerancia_piezas' => (int) env('IMPRENTA_TOLERANCIA_PIEZAS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Merma
    |--------------------------------------------------------------------------
    |
    | NO HAY VALOR POR OMISION DE MERMA, Y ESTO ES DELIBERADO.
    |
    | Una merma inventada es plata inventada: si el sistema supone que se
    | pierde un 5% de cada plancha y en realidad se pierde un 2%, el papel
    | que "falta" no existe y el margen del trabajo desaparece. Por eso
    | `papeles.merma_prueba_porcentaje` y `papeles.merma_plancha_porcentaje`
    | admiten `NULL` a proposito, y `CalculadoraDeMerma` FALLA CERRADA cuando
    | no hay valor: es preferible que el calculo se niegue a responder antes
    | que responder con una suposicion.
    |
    | El valor lo pone la imprenta con su propio historial, por papel.
    |
    */

    'merma' => [
        /*
        | Aviso cuando la merma realmente registrada supera la prevista por
        | esta diferencia en puntos porcentuales. No bloquea nada: el taller
        | tiene que poder seguir imprimiendo aunque una tirada salga mala.
        |
        */
        'aviso_puntos_porcentuales' => (int) env('IMPRENTA_MERMA_AVISO_PUNTOS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruebas y aprobacion
    |--------------------------------------------------------------------------
    |
    | Dias que se espera antes de considerar vencida una prueba que el taller
    | envie y el cliente no responda. NO se aprueba sola: vencido el plazo,
    | el pedido sigue SIN aprobar y el taller tiene que llamar al cliente.
    | Un sistema que aprueba por silencio es un sistema que reimprime.
    |
    */

    'pruebas' => [
        'dias_para_vencer' => (int) env('IMPRENTA_DIAS_PARA_VENCER_PRUEBA', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Artes y archivos
    |--------------------------------------------------------------------------
    |
    | El diseno vive FUERA de este sistema (en el servidor de archivos o en
    | la nube). Aca se guarda el nombre, la ruta, la version y quien la
    | aprobo: el registro, no el contenido.
    |
    | `bytes_maximos_por_arte` es solo de apoyo para la interfaz: el
    | uploading real no lo hace esta aplicacion.
    |
    */

    'artes' => [
        'bytes_maximos_por_arte' => (int) env('IMPRENTA_BYTES_MAXIMOS_ARTE', 52428800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Produccion
    |--------------------------------------------------------------------------
    |
    | Cuando se avisa de que un pedido se atrasa. Es un aviso al taller, no
    | un bloqueo: el cliente decide.
    |
    */

    'produccion' => [
        'dias_de_aviso' => (int) env('IMPRENTA_DIAS_AVISO_ATRASO', 1),
    ],

];
