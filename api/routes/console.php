<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Son POCAS y son de verificacion, no de mantenimiento.
|
| Una tarea que repara cosas sola es una tarea que hide un problema hasta que
| ya no lo arregla. Estas avisan: si un estado quedo desalineado o hay una
| prueba vencida, lo dicen y dejan que una persona decida.
|
| Para que corran hay que activar el scheduler en el contenedor de la API
| (ver deploy/api/docker/entrypoint.sh: `schedule:work`).
|
| Ver docs/05-CONFIGURACION.md.
*/

// Revisa que el estado de cada pedido cuadre con la suma de sus entregas.
// Si algo quedo desalineado, avisa. NO corrige solo: para eso esta
// `--corregir`, que es una decision de una persona.
Schedule::command('imprenta:revisar-estados')
    ->dailyAt('06:15')
    ->withoutOverlapping()
    ->onOneServer();

// Verificacion del aislamiento entre imprentas.
//
// daily y no solo en el despliegue: una migracion que se agregue despues y
// se olvide de activar la RLS es un silencio peligroso, y esto lo delata.
Schedule::command('imprenta:verificar-aislamiento')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();
