<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Client;

/*
|--------------------------------------------------------------------------
| Rutas del area de cliente
|--------------------------------------------------------------------------
|
| Cuelgan de /api/logspterodactyl en lugar de una ruta propia porque routes/base.php
| tiene una ruta comodin que se queda con TODO lo que no empiece por
| api, auth, admin o daemon y se lo entrega a React. Usando /api evitamos
| tener que tocar ese archivo del panel.
|
| Van con el grupo "web" (sesion + CSRF), que es como esta autenticado el
| navegador cuando el cliente esta usando el panel.
|
*/

Route::get('/server/{server}/install-status', [Client\InstallController::class, 'status'])->name('status');
Route::post('/server/{server}/cancel-install', [Client\InstallController::class, 'cancel'])->name('cancel');
