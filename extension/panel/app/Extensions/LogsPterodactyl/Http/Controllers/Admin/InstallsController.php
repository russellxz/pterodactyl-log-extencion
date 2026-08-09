<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Extensions\LogsPterodactyl\Services\PortRotator;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
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

        return view('logspterodactyl::admin.installs', [
            'history' => $history,
            'status' => $status,
            'search' => $search,
            'settings' => $this->settings,
            'summary' => $this->summary(),
            'followUp' => $this->followUp(),
            'stuckNodes' => $this->guard->stuckNodes(),
        ]);
    }

    /**
     * Seguimiento de las instalaciones que se detuvieron.
     *
     * Responde a la pregunta que de verdad importa: de las que el sistema paro
     * y cambio de puerto, ¿cuantas acabaron instalandose bien despues? Es la
     * forma de comprobar que el automatismo esta haciendo su trabajo y no solo
     * cortando por cortar.
     *
     * @return array{rows: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    private function followUp(): array
    {
        $detenidas = InstallEvent::query()
            ->whereIn('status', [InstallEvent::STATUS_TIMEOUT, InstallEvent::STATUS_CANCELLED])
            ->where('started_at', '>=', now()->subDays(60))
            ->orderByDesc('finished_at')
            ->limit(40)
            ->get();

        if ($detenidas->isEmpty()) {
            return ['rows' => [], 'resumen' => ['total' => 0, 'resueltas' => 0, 'pendientes' => 0, 'fallidas' => 0]];
        }

        // Todos los intentos posteriores, de una sola consulta.
        $siguientes = InstallEvent::query()
            ->whereIn('previous_id', $detenidas->pluck('id')->all())
            ->get()
            ->keyBy('previous_id');

        $rows = [];
        $resumen = ['total' => 0, 'resueltas' => 0, 'pendientes' => 0, 'fallidas' => 0];

        foreach ($detenidas as $evento) {
            $siguiente = $siguientes->get($evento->id);
            $resumen['total']++;

            if (!$siguiente) {
                $desenlace = 'sin_reintento';
                $resumen['pendientes']++;
            } elseif ($siguiente->status === InstallEvent::STATUS_SUCCESS) {
                $desenlace = 'resuelta';
                $resumen['resueltas']++;
            } elseif ($siguiente->status === InstallEvent::STATUS_INSTALLING) {
                $desenlace = 'reintentando';
                $resumen['pendientes']++;
            } else {
                $desenlace = 'volvio_a_fallar';
                $resumen['fallidas']++;
            }

            $rows[] = [
                'id' => $evento->id,
                'server_name' => $evento->server_name,
                'user_name' => $evento->user_name,
                'user_email' => $evento->user_email,
                'node_name' => $evento->node_name,
                'attempt' => (int) $evento->attempt,
                'minutos' => (int) round(((int) $evento->duration_seconds) / 60),
                'detenida_por' => $evento->cancelled_by,
                'forzada' => (bool) $evento->forced,
                'puerto_antes' => $evento->old_allocation,
                'puerto_despues' => $evento->new_allocation,
                'detenida_el' => $evento->finished_at,
                'desenlace' => $desenlace,
                'siguiente_estado' => $siguiente->status ?? null,
                'siguiente_duracion' => $siguiente && $siguiente->duration_seconds
                    ? (int) round(((int) $siguiente->duration_seconds) / 60)
                    : null,
                'siguiente_el' => $siguiente->started_at ?? null,
            ];
        }

        return ['rows' => $rows, 'resumen' => $resumen];
    }

    /**
     * Instalaciones en curso ahora mismo. La pantalla lo refresca sola.
     */
    public function live(): JsonResponse
    {
        $rows = [];
        $error = null;

        try {
            foreach ($this->guard->installing() as $server) {
                $minutes = $this->guard->minutesInstalling($server);

                // El numero de intento sale del historial, no de installed_at.
                // Un servidor cuya primera instalacion nunca llego a terminar
                // sigue con installed_at vacio, asi que por ahi el segundo
                // intento no parecia una reinstalacion.
                $evento = $this->guard->openEventFor($server);
                $anterior = $evento?->previous();

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
                    'attempt' => (int) ($evento->attempt ?? 1),
                    'is_reinstall' => (int) ($evento->attempt ?? 1) > 1 || $server->installed_at !== null,
                    'after_stop' => (bool) $anterior?->wasStoppedBySystem(),
                    'over_limit' => $minutes >= $this->settings->int('watchdog_minutes', 1, 1440),
                    'free_ports' => $this->ports->freeCount($server->node_id),
                    'admin_url' => url('/admin/servers/view/' . $server->id),
                ];
            }

            usort($rows, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);
        } catch (\Throwable $e) {
            // Antes, un fallo aqui devolvia un 500 y la pantalla se quedaba en
            // "Cargando..." sin decir nada. Ahora se ve el motivo.
            report($e);
            $error = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }

        return new JsonResponse([
            'servers' => $rows,
            'error' => $error,
            'limit_minutes' => $this->settings->int('watchdog_minutes', 1, 1440),
            'watchdog_enabled' => $this->settings->bool('watchdog_enabled'),
            'diagnostico' => $this->diagnostico(),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Cuantos servidores hay en cada estado, leido directamente de la tabla.
     *
     * Sirve para responder a "el sistema no detecta las instalaciones": si el
     * panel dice que hay servidores instalando y aqui salen cero, el problema
     * es que el estado en la base de datos no es "installing"; si salen y la
     * tabla de arriba esta vacia, el problema es de la extension.
     *
     * @return array<string, mixed>
     */
    private function diagnostico(): array
    {
        try {
            $porEstado = Server::query()
                ->selectRaw('COALESCE(status, \'(instalado / sin estado)\') as estado, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'estado')
                ->all();

            return [
                'servidores_por_estado' => $porEstado,
                'total_servidores' => (int) Server::query()->count(),
                'abiertos_en_historial' => (int) InstallEvent::query()
                    ->where('status', InstallEvent::STATUS_INSTALLING)
                    ->count(),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Para la instalacion de un servidor desde el panel de administracion.
     */
    public function stop(Request $request, string $server)
    {
        $model = Server::query()->with(['user', 'node', 'allocation', 'egg'])->find((int) $server);

        if (!$model) {
            return back()->with('logspterodactyl_error', 'Ese servidor ya no existe.');
        }

        if ($model->status !== Server::STATUS_INSTALLING) {
            return back()->with('logspterodactyl_error', 'Ese servidor no esta instalando ahora mismo.');
        }

        $mode = (string) $request->input('mode', InstallGuard::MODE_FAIL_ROTATE);

        if (!in_array($mode, [InstallGuard::MODE_FAIL, InstallGuard::MODE_FAIL_ROTATE], true)) {
            $mode = InstallGuard::MODE_FAIL_ROTATE;
        }

        $by = $request->user()->email ?? 'administrador';

        try {
            $result = $this->guard->stop($model, $mode, $by, $request->boolean('notify', true));
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', 'No se pudo detener la instalacion: ' . $e->getMessage());
        }

        $message = 'Instalacion de "' . $model->name . '" detenida.';

        if ($result['unblocked']) {
            $message .= ' El servidor queda marcado como instalado, igual que con el boton "Toggle Install Status".';
        }

        if ($result['port_changed']) {
            $message .= ' Puerto cambiado de ' . ($result['old_allocation'] ?? '?') . ' a ' . $result['new_allocation'] . '.';
        }

        $message .= ' El cliente ya puede entrar en su servidor, revisar sus datos y reinstalarlo cuando quiera.';

        foreach ($result['warnings'] as $warning) {
            $message .= ' Aviso: ' . $warning;
        }

        return back()->with('logspterodactyl_success', $message);
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
