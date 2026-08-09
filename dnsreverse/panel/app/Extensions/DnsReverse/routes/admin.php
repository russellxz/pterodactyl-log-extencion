<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

/*
|--------------------------------------------------------------------------
| Rutas del area de administracion de DNS Reverse
|--------------------------------------------------------------------------
|
| Se cargan desde el proveedor con el prefijo /admin/dnsreverse. No se toca
| routes/admin.php del panel a proposito: ese archivo lo reemplazan tanto las
| actualizaciones del panel como el tema.
|
*/

Route::get('/', [Admin\DashboardController::class, 'index'])->name('index');

// --- Dominios de la casa (uno por token de Cloudflare) ---
Route::get('/domains', [Admin\DomainsController::class, 'index'])->name('domains');
Route::get('/domains/new', [Admin\DomainsController::class, 'create'])->name('domains.new');
Route::post('/domains', [Admin\DomainsController::class, 'store'])->name('domains.store');
Route::get('/domains/{domain}', [Admin\DomainsController::class, 'edit'])->name('domains.edit');
Route::post('/domains/{domain}', [Admin\DomainsController::class, 'update'])->name('domains.update');
Route::post('/domains/{domain}/test', [Admin\DomainsController::class, 'test'])->name('domains.test');
Route::post('/domains/{domain}/delete', [Admin\DomainsController::class, 'destroy'])->name('domains.delete');

// --- DNS creados por los clientes ---
Route::get('/records', [Admin\RecordsController::class, 'index'])->name('records');
Route::post('/records/{record}/sync', [Admin\RecordsController::class, 'sync'])->name('records.sync');
Route::post('/records/{record}/delete', [Admin\RecordsController::class, 'destroy'])->name('records.delete');
Route::post('/records/purge', [Admin\RecordsController::class, 'purge'])->name('records.purge');
Route::post('/records/sync-all', [Admin\RecordsController::class, 'syncAll'])->name('records.syncall');

// --- Limites por servidor ---
//
// Ojo con el ":id": los modelos del panel (Server, Node, Egg) se resuelven por
// UUID en las rutas, no por su identificador numerico. Sin el ":id" estas
// pantallas darian 404 al guardar.
Route::get('/servers', [Admin\ServersController::class, 'index'])->name('servers');
Route::post('/servers/bulk-limit', [Admin\ServersController::class, 'bulkLimit'])->name('servers.bulk');
Route::post('/servers/{server:id}/limit', [Admin\ServersController::class, 'limit'])->name('servers.limit');

// --- Tipos de servidor (eggs) ---
Route::get('/eggs', [Admin\EggsController::class, 'index'])->name('eggs');
Route::post('/eggs/{egg:id}', [Admin\EggsController::class, 'update'])->name('eggs.update');

// --- Nodos y complemento de wings ---
Route::get('/nodes', [Admin\NodesController::class, 'index'])->name('nodes');
Route::get('/nodes/{node:id}/check', [Admin\NodesController::class, 'check'])->name('nodes.check');
Route::post('/nodes/{node:id}/renew', [Admin\NodesController::class, 'renew'])->name('nodes.renew');

// --- Registro y configuracion ---
Route::get('/events', [Admin\EventsController::class, 'index'])->name('events');
Route::get('/settings', [Admin\SettingsController::class, 'index'])->name('settings');
Route::post('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
