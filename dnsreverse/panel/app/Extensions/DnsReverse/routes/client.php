<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\DnsReverse\Http\Controllers\Client;

/*
|--------------------------------------------------------------------------
| Rutas del area de cliente
|--------------------------------------------------------------------------
|
| Cuelgan de /api/dnsreverse porque routes/base.php del panel tiene un comodin
| que se queda con TODO lo que no empiece por api, auth, admin o daemon y se
| lo entrega a React.
|
| Van con el grupo "web" (sesion + CSRF), que es como esta autenticado el
| navegador mientras el cliente usa el panel.
|
*/

Route::get('/server/{server}', [Client\DnsController::class, 'index'])->name('index');
Route::post('/server/{server}', [Client\DnsController::class, 'store'])->name('store');
Route::delete('/server/{server}/{record}', [Client\DnsController::class, 'destroy'])->name('destroy');
