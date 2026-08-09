<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/**
 * Detiene instalaciones que se han quedado colgadas.
 *
 * Que hace exactamente al detener una:
 *
 *   1. El panel deja de considerar el servidor "instalando". Esto es lo que
 *      desbloquea la pantalla del cliente al momento.
 *   2. Se le cambia el puerto a otro libre del mismo nodo.
 *   3. Se avisa al dueno y queda todo registrado.
 *
 * Y que NO hace, a proposito: no borra el servidor, ni en el panel ni en el
 * nodo. El servidor se queda donde esta, con su puerto nuevo, para que el
 * cliente revise sus datos de arranque y vuelva a instalar el mismo con el
 * boton de siempre del panel.
 *
 * Sobre el contenedor del nodo: wings no expone ninguna orden para cancelar
 * una instalacion en marcha (su API solo tiene power, commands, install,
 * reinstall, sync y delete, y power se rechaza mientras instala). La unica
 * forma de matar el contenedor seria borrar el servidor en el nodo, y eso
 * borraria tambien sus archivos, asi que no se hace. El contenedor colgado
 * termina por su cuenta y para entonces el cliente ya lleva rato pudiendo
 * trabajar, que es lo que importa.
 */
class InstallGuard
{
    public const MODE_FAIL = 'fail';
    public const MODE_FAIL_ROTATE = 'fail_rotate';

    public function __construct(
        private DaemonServerRepository $daemon,
        private PortRotator $ports,
        private Settings $settings,
    ) {
    }

    /**
     * Servidores que llevan instalando mas de $minutes minutos.
     *
     * @return Collection<int, Server>
     */
    public function stuck(int $minutes): Collection
    {
        $cutoff = CarbonImmutable::now()->subMinutes(max(1, $minutes));

        return $this->installing()
            ->filter(fn (Server $server) => $this->startedAt($server)->lessThanOrEqualTo($cutoff))
            ->values();
    }

    /**
     * Todos los servidores que el panel considera en instalacion.
     *
     * @return Collection<int, Server>
     */
    public function installing(): Collection
    {
        return Server::query()
            ->where('status', Server::STATUS_INSTALLING)
            ->with(['user', 'node', 'allocation', 'egg'])
            ->get();
    }

    /**
     * Momento en que arranco la instalacion.
     *
     * Primero se mira el historial propio, que es el dato exacto. Si no lo
     * hay se deduce del servidor: en una primera instalacion vale created_at
     * (installed_at sigue vacio porque el panel solo lo rellena cuando wings
     * responde, incluso si fallo); en una reinstalacion vale updated_at, que
     * es lo que toca el panel al pasar el servidor a "installing".
     */
    public function startedAt(Server $server): CarbonImmutable
    {
        $tracked = $this->openEventFor($server);

        if ($tracked && $tracked->started_at) {
            return CarbonImmutable::parse($tracked->started_at);
        }

        $reference = $server->installed_at === null
            ? ($server->created_at ?: $server->updated_at)
            : ($server->updated_at ?: $server->created_at);

        return CarbonImmutable::parse($reference ?: now());
    }

    public function minutesInstalling(Server $server): int
    {
        return (int) $this->startedAt($server)->diffInMinutes(CarbonImmutable::now());
    }

    /**
     * Para la instalacion de un servidor.
     *
     * No se borra nada: el servidor se queda en su sitio, con puerto nuevo,
     * listo para que el cliente revise sus datos y lo reinstale el mismo con
     * el boton de siempre del panel.
     *
     * @param string $mode  MODE_FAIL (solo parar) o MODE_FAIL_ROTATE (y cambiar puerto)
     * @param string $by    quien lo pide: "sistema", el correo del cliente, etc.
     *
     * @return array{
     *     status: string,
     *     port_changed: bool,
     *     old_allocation: ?string,
     *     new_allocation: ?string,
     *     warnings: array<int, string>
     * }
     */
    public function stop(Server $server, string $mode, string $by, bool $notifyOwner = true): array
    {
        $warnings = [];
        $minutes = $this->minutesInstalling($server);
        $wasReinstall = $server->installed_at !== null;

        // 1) El panel deja de considerarlo "instalando". Esto es lo que
        //    desbloquea la pantalla del cliente al momento.
        $status = $wasReinstall ? Server::STATUS_REINSTALL_FAILED : Server::STATUS_INSTALL_FAILED;
        $server->forceFill(['status' => $status])->save();

        // 2) Cambio de puerto, que es la otra mitad del trabajo: el cliente se
        //    encuentra el servidor en una direccion nueva y limpia.
        $rotation = null;

        if ($mode === self::MODE_FAIL_ROTATE) {
            try {
                $rotation = $this->ports->rotate($server, $this->settings->bool('watchdog_same_ip'));

                if ($rotation === null) {
                    $warnings[] = 'El nodo no tiene ningun puerto libre, no se pudo cambiar la asignacion.';
                }
            } catch (\Throwable $e) {
                $warnings[] = 'No se pudo cambiar el puerto: ' . $this->short($e);
            }
        }

        // 3) Se le pasa al nodo la configuracion nueva (el puerto).
        try {
            $this->daemon->setServer($server->refresh())->sync();
        } catch (\Throwable $e) {
            $warnings[] = 'No se pudo sincronizar con el nodo: ' . $this->short($e);
        }

        // 4) Se vuelve a fijar el estado. Entre los pasos 1 y 3 puede haber
        //    llegado el aviso de wings diciendo que la instalacion termino, y
        //    ese aviso escribe en el servidor.
        $server->refresh();

        if ($server->status === Server::STATUS_INSTALLING) {
            $server->forceFill(['status' => $status])->save();
        }

        // 5) Historial.
        $this->closeInstallEvent($server, $by, $minutes, $rotation, $mode);

        ExtensionEvent::log('warning', 'installs', sprintf(
            'Instalacion detenida en "%s" tras %d minutos (%s)',
            $server->name,
            $minutes,
            $by
        ), [
            'modo' => $mode,
            'puerto_anterior' => $rotation['old'] ?? null,
            'puerto_nuevo' => $rotation['new'] ?? null,
            'avisos' => $warnings,
        ], $server->id);

        // 6) Correo al dueno.
        if ($notifyOwner) {
            $this->notifyOwner($server, $minutes, $rotation, $warnings);
        }

        return [
            'status' => $status,
            'port_changed' => $rotation !== null,
            'old_allocation' => $rotation['old'] ?? null,
            'new_allocation' => $rotation['new'] ?? null,
            'warnings' => $warnings,
        ];
    }

    /**
     * Abre una fila de historial para una instalacion en curso.
     *
     * $startedAt permite registrar instalaciones que ya estaban en marcha
     * antes de instalar la extension, sin falsear su antiguedad.
     */
    public function openInstallEvent(Server $server, bool $isReinstall = false, ?CarbonImmutable $startedAt = null): InstallEvent
    {
        $server->loadMissing(['user', 'node', 'egg', 'allocation']);

        // El intento anterior de ESTE mismo servidor, para poder seguir la
        // historia completa: se atasco, el sistema lo paro, se reinstalo y
        // esta vez si fue bien.
        $anterior = InstallEvent::query()
            ->where('server_id', $server->id)
            ->orderByDesc('attempt')
            ->orderByDesc('id')
            ->first();

        // Se considera reinstalacion si ya hubo un intento antes, aunque el
        // panel no lo sepa: si la primera instalacion nunca llego a terminar,
        // installed_at sigue vacio y por si solo no basta.
        $esReintento = $isReinstall || $anterior !== null;

        return InstallEvent::create([
            'attempt' => $anterior ? ((int) $anterior->attempt + 1) : 1,
            'previous_id' => $anterior?->id,
            'server_id' => $server->id,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'user_id' => $server->owner_id,
            'user_name' => trim(($server->user->name_first ?? '') . ' ' . ($server->user->name_last ?? '')) ?: ($server->user->username ?? null),
            'user_email' => $server->user->email ?? null,
            'node_id' => $server->node_id,
            'node_name' => $server->node->name ?? null,
            'egg_id' => $server->egg_id,
            'egg_name' => $server->egg->name ?? null,
            'is_reinstall' => $esReintento,
            'status' => InstallEvent::STATUS_INSTALLING,
            'old_allocation' => $this->ports->label($server->allocation),
            'started_at' => $startedAt ?: now(),
        ]);
    }

    /**
     * Se asegura de que exista una fila de historial para una instalacion que
     * ya esta en marcha. Es idempotente: se puede llamar cada minuto.
     */
    public function track(Server $server): InstallEvent
    {
        return $this->openEventFor($server)
            ?? $this->openInstallEvent($server, $server->installed_at !== null, $this->startedAt($server));
    }

    /**
     * Fila de historial abierta para este servidor, si la hay.
     */
    public function openEventFor(Server $server): ?InstallEvent
    {
        return InstallEvent::query()
            ->where('server_id', $server->id)
            ->where('status', InstallEvent::STATUS_INSTALLING)
            ->latest('started_at')
            ->first();
    }

    /**
     * Cierra la fila del historial usando el estado real del servidor.
     *
     * Es idempotente y no toca nada si el servidor sigue instalando, asi que
     * se puede llamar tantas veces como haga falta.
     */
    public function closeFor(Server $server): void
    {
        if ($server->status === Server::STATUS_INSTALLING) {
            return;
        }

        $event = $this->openEventFor($server);

        if (!$event) {
            return;
        }

        $failed = in_array($server->status, [
            Server::STATUS_INSTALL_FAILED,
            Server::STATUS_REINSTALL_FAILED,
        ], true);

        $started = $event->started_at ? CarbonImmutable::parse($event->started_at) : CarbonImmutable::now();

        $event->fill([
            'status' => $failed ? InstallEvent::STATUS_FAILED : InstallEvent::STATUS_SUCCESS,
            'finished_at' => now(),
            'duration_seconds' => (int) $started->diffInSeconds(CarbonImmutable::now()),
            'new_allocation' => $event->new_allocation ?: $this->ports->label($server->allocation),
            'notes' => $event->notes ?: ($failed
                ? 'El nodo informo de que la instalacion fallo.'
                : 'Instalacion completada.'),
        ])->save();
    }

    /**
     * Cierra todas las filas abiertas cuyo servidor ya no esta instalando.
     * Tambien cierra las de servidores borrados, para que no queden colgadas.
     *
     * @return int cuantas filas se cerraron
     */
    public function reconcile(): int
    {
        $open = InstallEvent::query()
            ->where('status', InstallEvent::STATUS_INSTALLING)
            ->get();

        if ($open->isEmpty()) {
            return 0;
        }

        $servers = Server::query()
            ->whereIn('id', $open->pluck('server_id')->filter()->all())
            ->with('allocation')
            ->get()
            ->keyBy('id');

        $closed = 0;

        foreach ($open as $event) {
            $server = $servers->get($event->server_id);

            if (!$server) {
                $event->fill([
                    'status' => InstallEvent::STATUS_FAILED,
                    'finished_at' => now(),
                    'notes' => 'El servidor ya no existe en el panel.',
                ])->save();
                $closed++;

                continue;
            }

            if ($server->status === Server::STATUS_INSTALLING) {
                continue;
            }

            $this->closeFor($server);
            $closed++;
        }

        return $closed;
    }

    private function closeInstallEvent(Server $server, string $by, int $minutes, ?array $rotation, string $mode): void
    {
        $event = $this->openEventFor($server) ?? $this->openInstallEvent($server, $server->installed_at !== null);

        $event->fill([
            'status' => $by === 'sistema' ? InstallEvent::STATUS_TIMEOUT : InstallEvent::STATUS_CANCELLED,
            'resolution' => $mode,
            'forced' => true,
            'wings_deleted' => false,
            'cancelled_by' => $by,
            'new_allocation' => $rotation['new'] ?? null,
            'old_allocation' => $rotation['old'] ?? $event->old_allocation,
            'finished_at' => now(),
            'duration_seconds' => $minutes * 60,
            'notes' => $rotation
                ? 'Instalacion detenida y servidor movido a otro puerto. El cliente puede revisar sus datos y reinstalar cuando quiera.'
                : 'Instalacion detenida. No se pudo cambiar el puerto.',
        ])->save();
    }

    private function notifyOwner(Server $server, int $minutes, ?array $rotation, array $warnings): void
    {
        $user = $server->user;

        if (!$user || !filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::send('logspterodactyl::mail.install-stopped', [
                'user' => $user,
                'server' => $server,
                'minutes' => $minutes,
                'rotation' => $rotation,
                'panelUrl' => rtrim((string) config('app.url'), '/') . '/server/' . $server->uuidShort,
                'appName' => config('app.name', 'Pterodactyl'),
            ], function ($message) use ($user, $server) {
                $message->to($user->email)
                    ->subject('Instalacion detenida: ' . $server->name);
            });
        } catch (\Throwable $e) {
            ExtensionEvent::log('warning', 'installs', 'No se pudo avisar por correo al dueno del servidor', [
                'error' => $this->short($e),
                'servidor' => $server->name,
            ], $server->id);
        }
    }

    private function short(\Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 200);
    }
}
