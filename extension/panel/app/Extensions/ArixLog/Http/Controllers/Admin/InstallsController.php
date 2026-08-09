<?php

namespace Pterodactyl\Extensions\ArixLog\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\ArixLog\Models\InstallEvent;
use Pterodactyl\Extensions\ArixLog\Services\InstallGuard;
use Pterodactyl\Extensions\ArixLog\Services\PortRotator;
use Pterodactyl\Extensions\ArixLog\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;

/**
 * Historial de instalaciones y control manual de las que estan en curso.
 */
class InstallsController extends Controller
{
    public function __construct(
        private InstallGuard $guard,
        private PortRotator $ports,
        private Settings $settings,
    ) {
    }

    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));

        $history = InstallEvent::query()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('server_name', 'like', $like)
                        ->orWhere('user_email', 'like', $like)
                        ->orWhere('user_name', 'like', $like)
                        ->orWhere('node_name', 'like', $like);
                });
            })
            ->orderByDesc('started_at')
            ->paginate(30)
            ->withQueryString();

        return view('arixlog::admin.installs', [
            'history' => $history,
            'status' => $status,
            'search' => $search,
            'settings' => $this->settings,
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Instalaciones en curso ahora mismo. La pantalla lo refresca sola.
     */
    public function live(): JsonResponse
    {
        $rows = [];

        foreach ($this->guard->installing() as $server) {
            $minutes = $this->guard->minutesInstalling($server);

            $rows[] = [
                'id' => $server->id,
                'name' => $server->name,
                'uuid_short' => $server->uuidShort,
                'owner' => trim(($server->user->name_first ?? '') . ' ' . ($server->user->name_last ?? ''))
                    ?: ($server->user->username ?? '-'),
                'owner_email' => $server->user->email ?? '-',
                'node' => $server->node->name ?? '-',
                'egg' => $server->egg->name ?? '-',
                'allocation' => $this->ports->label($server->allocation),
                'minutes' => $minutes,
                'started_at' => $this->guard->startedAt($server)->toDateTimeString(),
                'is_reinstall' => $server->installed_at !== null,
                'over_limit' => $minutes >= $this->settings->int('watchdog_minutes', 1, 1440),
                'free_ports' => $this->ports->freeCount($server->node_id),
                'admin_url' => url('/admin/servers/view/' . $server->id),
            ];
        }

        usort($rows, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        return new JsonResponse([
            'servers' => $rows,
            'limit_minutes' => $this->settings->int('watchdog_minutes', 1, 1440),
            'watchdog_enabled' => $this->settings->bool('watchdog_enabled'),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Para la instalacion de un servidor desde el panel de administracion.
     */
    public function stop(Request $request, int $server)
    {
        $model = Server::query()->with(['user', 'node', 'allocation', 'egg'])->find($server);

        if (!$model) {
            return back()->with('arixlog_error', 'Ese servidor ya no existe.');
        }

        if ($model->status !== Server::STATUS_INSTALLING) {
            return back()->with('arixlog_error', 'Ese servidor no esta instalando ahora mismo.');
        }

        $mode = (string) $request->input('mode', InstallGuard::MODE_FAIL_ROTATE);

        if (!in_array($mode, [InstallGuard::MODE_FAIL, InstallGuard::MODE_FAIL_ROTATE, InstallGuard::MODE_FORCE_ROTATE], true)) {
            $mode = InstallGuard::MODE_FAIL_ROTATE;
        }

        $by = $request->user()->email ?? 'administrador';

        try {
            $result = $this->guard->stop($model, $mode, $by, $request->boolean('notify', true));
        } catch (\Throwable $e) {
            return back()->with('arixlog_error', 'No se pudo detener la instalacion: ' . $e->getMessage());
        }

        $message = 'Instalacion de "' . $model->name . '" detenida.';

        if ($result['port_changed']) {
            $message .= ' Puerto cambiado de ' . ($result['old_allocation'] ?? '?') . ' a ' . $result['new_allocation'] . '.';
        }

        if ($result['wings_deleted']) {
            $message .= ' El servidor se borro en el nodo: usa "Recrear en el nodo" antes de volver a instalar.';
        }

        foreach ($result['warnings'] as $warning) {
            $message .= ' Aviso: ' . $warning;
        }

        return back()->with('arixlog_success', $message);
    }

    /**
     * Vuelve a crear el servidor en el nodo tras una parada forzada.
     */
    public function recreate(Request $request, int $server)
    {
        $model = Server::query()->with(['node', 'allocation'])->find($server);

        if (!$model) {
            return back()->with('arixlog_error', 'Ese servidor ya no existe.');
        }

        try {
            $this->guard->recreate($model);
        } catch (\Throwable $e) {
            return back()->with('arixlog_error', 'No se pudo recrear el servidor en el nodo: ' . $e->getMessage());
        }

        return back()->with('arixlog_success', 'El servidor "' . $model->name . '" se esta creando de nuevo en el nodo.');
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $since = now()->subDays(30);

        return [
            'total' => (int) InstallEvent::query()->where('started_at', '>=', $since)->count(),
            'success' => (int) InstallEvent::query()->where('started_at', '>=', $since)->where('status', InstallEvent::STATUS_SUCCESS)->count(),
            'failed' => (int) InstallEvent::query()->where('started_at', '>=', $since)->where('status', InstallEvent::STATUS_FAILED)->count(),
            'timeout' => (int) InstallEvent::query()->where('started_at', '>=', $since)->where('status', InstallEvent::STATUS_TIMEOUT)->count(),
            'cancelled' => (int) InstallEvent::query()->where('started_at', '>=', $since)->where('status', InstallEvent::STATUS_CANCELLED)->count(),
        ];
    }
}
