<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas de la API
|--------------------------------------------------------------------------
|
| Todas bajo `api/v1`, y el prefijo se aplica UNA vez aqui con un grupo en
| vez de repetirlo en cada ruta: es mas dificil olvidarse asi, y una ruta
| que se sale del prefijo queda a la vista en el archivo.
|
| LO QUE ESTA FUERA DEL GRUPO DE SESION Y POR QUE
|
| Solo cuatro cosas: la salud, la lista de empresas del selector de acceso, y
| las dos piezas del acceso. Ninguna toca una tabla protegida: la salud
| consulta `pg_class`, y el selector lee la vista `vw_impresas_publicas`, que
| existe precisamente para poder consultar antes de que exista
| `app.tenant_id`.
|
| Lo demas va con sesion. Y con la sesion viene el contexto de empresa, que
| es lo que hace que la RLS sepa de quien son las filas.
|
| Ver docs/09-API.md.
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    Route::get('/salud', \App\Http\Controllers\SaludController::class)
        ->name('salud');

    // Empresas para el selector de acceso. Lee la vista
    // `vw_impresas_publicas`: tres columnas, sin RLS, a proposito.
    // Ver AuthController::empresas().
    Route::get('/empresas', [\App\Http\Controllers\AuthController::class, 'empresas'])
        ->name('empresas');

    Route::get('/acceso', [\App\Http\Controllers\AuthController::class, 'acceso'])
        ->name('acceso');

    Route::get('/acceso/callback', [\App\Http\Controllers\AuthController::class, 'callback'])
        ->name('acceso.callback');

    /*
    | Rutas que exigen sesion.
    */
    Route::middleware('auth')->group(function (): void {

        Route::get('/yo', [\App\Http\Controllers\AuthController::class, 'yo'])->name('yo');
        Route::post('/salir', [\App\Http\Controllers\AuthController::class, 'salir'])->name('salir');

        // --- Pedidos ------------------------------------------------------
        Route::get('/pedidos', [\App\Http\Controllers\PedidoController::class, 'index'])->name('pedidos.index');
        Route::get('/pedidos/{pedido}', [\App\Http\Controllers\PedidoController::class, 'mostrar'])->name('pedidos.show');
        Route::post('/pedidos/{pedido}/aprobar', [\App\Http\Controllers\PedidoController::class, 'aprobar'])->name('pedidos.aprobar');
        Route::post('/pedidos/{pedido}/esperar-aprobacion', [\App\Http\Controllers\PedidoController::class, 'esperarAprobacion'])->name('pedidos.esperar');
        Route::post('/pedidos/{pedido}/anular', [\App\Http\Controllers\PedidoController::class, 'anular'])->name('pedidos.anular');

        Route::get('/items/{item}/consumo', [\App\Http\Controllers\PedidoController::class, 'consumo'])->name('items.consumo');

        // --- Pruebas (mockups) y artes -----------------------------------
        Route::get('/pruebas', [\App\Http\Controllers\PruebaController::class, 'index'])->name('pruebas.index');
        Route::post('/items/{item}/versiones', [\App\Http\Controllers\PruebaController::class, 'registrarVersion'])->name('items.versiones');
        Route::post('/pruebas/{prueba}/enviar', [\App\Http\Controllers\PruebaController::class, 'enviar'])->name('pruebas.enviar');
        Route::post('/pruebas/{prueba}/resolver', [\App\Http\Controllers\PruebaController::class, 'resolver'])->name('pruebas.resolver');

        // --- Papel y planchas ---------------------------------------------
        Route::get('/papeles', [\App\Http\Controllers\PapelController::class, 'index'])->name('papeles.index');
        Route::get('/planchas', [\App\Http\Controllers\PapelController::class, 'planchas'])->name('planchas.index');
        Route::get('/papeles/{papel}', [\App\Http\Controllers\PapelController::class, 'mostrar'])->name('papeles.show');
        Route::post('/papeles', [\App\Http\Controllers\PapelController::class, 'crear'])->name('papeles.crear');
        Route::put('/papeles/{papel}', [\App\Http\Controllers\PapelController::class, 'guardar'])->name('papeles.guardar');

        // --- Produccion ----------------------------------------------------
        Route::get('/produccion', [\App\Http\Controllers\ProduccionController::class, 'index'])->name('produccion.index');
        Route::get('/produccion/pedido/{pedido}', [\App\Http\Controllers\ProduccionController::class, 'porPedido'])->name('produccion.porPedido');
        Route::get('/produccion/{item}/presupuesto', [\App\Http\Controllers\ProduccionController::class, 'presupuesto'])->name('produccion.presupuesto');
        Route::post('/produccion/{item}', [\App\Http\Controllers\ProduccionController::class, 'registrar'])->name('produccion.registrar');

        // --- Entregas ------------------------------------------------------
        Route::get('/entregas', [\App\Http\Controllers\EntregaController::class, 'index'])->name('entregas.index');
        Route::get('/entregas/pendientes', [\App\Http\Controllers\EntregaController::class, 'pendientes'])->name('entregas.pendientes');
        Route::post('/pedidos/{pedido}/entregas', [\App\Http\Controllers\EntregaController::class, 'registrar'])->name('entregas.registrar');

        // --- Merma ----------------------------------------------------------
        Route::get('/merma', \App\Http\Controllers\MermaController::class)->name('merma');

        // --- Libreria y papeleria -------------------------------------------
        Route::get('/libreria', [\App\Http\Controllers\LibreriaController::class, 'index'])->name('libreria.index');
        Route::post('/libreria', [\App\Http\Controllers\LibreriaController::class, 'crear'])->name('libreria.crear');
        Route::put('/libreria/{articulo}', [\App\Http\Controllers\LibreriaController::class, 'guardar'])->name('libreria.guardar');
        Route::post('/libreria/{articulo}/movimientos', [\App\Http\Controllers\LibreriaController::class, 'registrarMovimiento'])->name('libreria.movimientos');

        // --- Reportes --------------------------------------------------------
        Route::get('/reportes', \App\Http\Controllers\ReporteController::class)->name('reportes');
    });
});
