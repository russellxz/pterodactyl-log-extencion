<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Configuracion de la extension.
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index()
    {
        return view('logspterodactyl::admin.settings', [
            'settings' => $this->settings,
            'modes' => [
                InstallGuard::MODE_FAIL => 'Solo detener la instalacion, sin tocar el puerto',
                InstallGuard::MODE_FAIL_ROTATE => 'Detener la instalacion y cambiar el puerto (recomendado)',
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'watchdog_enabled' => 'nullable|boolean',
            'watchdog_minutes' => 'required|integer|min:1|max:1440',
            'watchdog_action' => 'required|in:fail,fail_rotate',
            'watchdog_notify_owner' => 'nullable|boolean',
            'watchdog_same_ip' => 'nullable|boolean',
            'unblock_guard_minutes' => 'required|integer|min:0|max:10080',

            'client_cancel_enabled' => 'nullable|boolean',
            'client_cancel_minutes' => 'required|integer|min:1|max:1440',
            'client_cancel_rotate_port' => 'nullable|boolean',

            'mail_log_enabled' => 'nullable|boolean',
            'mail_log_store_body' => 'nullable|boolean',
            'mail_log_retention_days' => 'required|integer|min:1|max:3650',

            'resources_enabled' => 'nullable|boolean',
            'resources_retention_days' => 'required|integer|min:1|max:3650',
            'resources_alert_cpu' => 'required|integer|min:1|max:100000',
            'resources_alert_memory' => 'required|integer|min:1|max:100',

            'events_retention_days' => 'required|integer|min:1|max:3650',
            'installs_retention_days' => 'required|integer|min:1|max:3650',

            'backup_path' => 'required|string|max:255',
            'update_keep_backups' => 'required|integer|min:1|max:20',
        ]);

        // La carpeta de respaldos guarda un volcado de la base de datos y un
        // archivo con la contrasena de MySQL. Si estuviera dentro del panel
        // quedaria colgando de la web, y si estuviera dentro de la propia
        // carpeta que se respalda, tar intentaria meterse a si mismo.
        $error = $this->validateBackupPath((string) $data['backup_path']);

        if ($error !== null) {
            return back()->withInput()->with('logspterodactyl_error', $error);
        }

        // Las casillas que no se marcan no llegan en la peticion.
        foreach ([
            'watchdog_enabled', 'watchdog_notify_owner', 'watchdog_same_ip',
            'client_cancel_enabled', 'client_cancel_rotate_port',
            'mail_log_enabled', 'mail_log_store_body', 'resources_enabled',
        ] as $flag) {
            $data[$flag] = $request->boolean($flag) ? '1' : '0';
        }

        $this->settings->setMany($data);

        ExtensionEvent::log('info', 'settings', 'Se guardo la configuracion de la extension', [
            'vigilante' => $data['watchdog_enabled'] === '1' ? 'activo' : 'inactivo',
            'minutos' => $data['watchdog_minutes'],
            'accion' => $data['watchdog_action'],
        ]);

        return back()->with('logspterodactyl_success', 'Configuracion guardada.');
    }

    /**
     * @return string|null el motivo del rechazo, o null si la ruta vale
     */
    private function validateBackupPath(string $path): ?string
    {
        $path = rtrim(trim($path), '/');

        if ($path === '' || !str_starts_with($path, '/')) {
            return 'La carpeta de respaldos tiene que ser una ruta absoluta, por ejemplo /var/backups/logspterodactyl';
        }

        $panel = rtrim(base_path(), '/');
        $public = rtrim(public_path(), '/');

        if ($path === $public || str_starts_with($path . '/', $public . '/')) {
            return 'La carpeta de respaldos no puede estar dentro de la carpeta publica del panel: '
                . 'ahi cualquiera podria descargarse el volcado de la base de datos.';
        }

        if ($path === $panel || str_starts_with($path . '/', $panel . '/')) {
            return 'La carpeta de respaldos tiene que estar FUERA de la carpeta del panel ('
                . $panel . '), porque el respaldo copia esa carpeta entera.';
        }

        return null;
    }
}
