<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

/*
|--------------------------------------------------------------------------
| Rutas del area de administracion de LogsPterodactyl
|--------------------------------------------------------------------------
|
| Se cargan desde el proveedor de la extension con el prefijo /admin/logspterodactyl.
| No se tocan routes/admin.php ni routes/base.php del panel a proposito: el
| tema Arix reemplaza esos archivos cada vez que se instala o actualiza.
|
*/

Route::get('/', [Admin\DashboardController::class, 'index'])->name('index');

// --- Registro de errores del panel ---
Route::get('/logs', [Admin\LogsController::class, 'index'])->name('logs');
Route::get('/logs/data', [Admin\LogsController::class, 'data'])->name('logs.data');
Route::get('/logs/download', [Admin\LogsController::class, 'download'])->name('logs.download');
Route::post('/logs/clear', [Admin\LogsController::class, 'clear'])->name('logs.clear');
Route::post('/logs/delete', [Admin\LogsController::class, 'destroy'])->name('logs.delete');

// --- Registro propio de la extension ---
Route::get('/events', [Admin\EventsController::class, 'index'])->name('events');

// --- Instalaciones ---
Route::get('/installs', [Admin\InstallsController::class, 'index'])->name('installs');
Route::get('/installs/live', [Admin\InstallsController::class, 'live'])->name('installs.live');
Route::post('/installs/{server}/stop', [Admin\InstallsController::class, 'stop'])->name('installs.stop');

// --- Correos ---
Route::get('/mails', [Admin\MailsController::class, 'index'])->name('mails');
Route::get('/mails/{mail}', [Admin\MailsController::class, 'show'])->name('mails.show');
Route::get('/mails/{mail}/preview', [Admin\MailsController::class, 'preview'])->name('mails.preview');
Route::post('/mails/{mail}/resend', [Admin\MailsController::class, 'resend'])->name('mails.resend');
Route::post('/mails/test', [Admin\MailsController::class, 'test'])->name('mails.test');

// --- Redactar y enviar correos a los clientes ---
Route::get('/compose', [Admin\ComposeController::class, 'index'])->name('compose');
Route::get('/compose/search', [Admin\ComposeController::class, 'search'])->name('compose.search');
Route::post('/compose/preview', [Admin\ComposeController::class, 'preview'])->name('compose.preview');
Route::post('/compose/logo', [Admin\ComposeController::class, 'logo'])->name('compose.logo');
Route::post('/compose/send', [Admin\ComposeController::class, 'send'])->name('compose.send');
Route::post('/compose/template', [Admin\ComposeController::class, 'template'])->name('compose.template');

// --- Consumo de recursos ---
Route::get('/resources', [Admin\ResourcesController::class, 'index'])->name('resources');
Route::get('/resources/live', [Admin\ResourcesController::class, 'live'])->name('resources.live');
Route::get('/resources/top', [Admin\ResourcesController::class, 'top'])->name('resources.top');

// --- Actualizacion del panel ---
// Acceso SSH a los nodos, para soltar el bloqueo de instalacion de wings.
Route::get('/nodes', [Admin\NodesController::class, 'index'])->name('nodes');
Route::post('/nodes/{node}', [Admin\NodesController::class, 'save'])->name('nodes.save');
Route::post('/nodes/{node}/test', [Admin\NodesController::class, 'test'])->name('nodes.test');
Route::get('/nodes/{node}/check', [Admin\NodesController::class, 'check'])->name('nodes.check');
Route::post('/nodes/{node}/forget', [Admin\NodesController::class, 'forget'])->name('nodes.forget');
Route::post('/nodes/{node}/delete', [Admin\NodesController::class, 'destroy'])->name('nodes.delete');
Route::post('/nodes/{node}/restart-wings', [Admin\NodesController::class, 'restartWings'])->name('nodes.restart');
Route::post('/nodes/release/{server}', [Admin\NodesController::class, 'releaseServer'])->name('nodes.release');


Route::get('/updater', [Admin\UpdaterController::class, 'index'])->name('updater');
Route::post('/updater/check', [Admin\UpdaterController::class, 'check'])->name('updater.check');
Route::post('/updater/start', [Admin\UpdaterController::class, 'start'])->name('updater.start');
Route::get('/updater/{run}/status', [Admin\UpdaterController::class, 'status'])->name('updater.status');
Route::post('/updater/{run}/rollback', [Admin\UpdaterController::class, 'rollback'])->name('updater.rollback');

// --- Configuracion ---
Route::get('/settings', [Admin\SettingsController::class, 'index'])->name('settings');
Route::post('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
