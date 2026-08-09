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
 * Aviso importante sobre lo que se puede y lo que no se puede hacer:
 *
 * wings NO expone ningun endpoint para cancelar una instalacion en curso (se
 * ha comprobado en su router: solo hay power, commands, install, reinstall,
 * sync y delete, y las acciones de power se rechazan mientras el servidor esta
 * instalando). Por eso hay dos modos:
 *
 *  - Modo normal: el panel marca la instalacion como fallida. El usuario deja
 *    de ver la pantalla de "instalando", puede corregir sus variables y volver
 *    a instalar. El contenedor de instalacion del nodo puede seguir corriendo
 *    hasta que termine por su cuenta.
 *
 *  - Modo forzado: ademas se pide a wings que borre el servidor, lo que
 *    destruye el entorno y con el el contenedor de instalacion colgado. Es la
 *    unica forma real de cortarlo. Los archivos de una instalacion que nunca
 *    termino no tienen valor, pero como el servidor deja de existir en el nodo
 *    hay que volver a crearlo antes de reinstalar: para eso esta recreate().
 */
class InstallGuard
{
    public const MODE_FAIL = 'fail';
    public const MODE_FAIL_ROTATE = 'fail_rotate';
    public const MODE_FORCE_ROTATE = 'force_rotate';

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
     * @param string $mode  uno de los MODE_*
     * @param string $by    quien lo pide: "sistema", el correo del cliente, etc.
     *
     * @return array{
     *     status: string,
     *     port_changed: bool,
     *     old_allocation: ?string,
     *     new_allocation: ?string,
     *     wings_deleted: bool,
     *     warnings: array<int, string>
     * }
     */
    public function stop(Server $server, string $mode, string $by, bool $notifyOwner = true, bool $forceAnyway = false): array
    {
        $warnings = [];
        $wingsDeleted = false;
        $minutes = $this->minutesInstalling($server);
        $wasReinstall = $server->installed_at !== null;
        $forzado = $mode === self::MODE_FORCE_ROTATE;

        // 1) Modo forzado: se destruye el entorno en el nodo. Es LO UNICO que
        //    corta de verdad un contenedor de instalacion atascado, porque
        //    wings no tiene ninguna orden para cancelar una instalacion.
        //
        //    Sin esto pasa justo lo que se ve en la practica: el panel marca la
        //    instalacion como fallida, pero el contenedor sigue vivo y cuando
        //    termina avisa al panel, que vuelve a mover el estado del servidor.
        //    Al cliente le parece que nunca se paro.
        // Antes de borrar nada hay que estar seguro de que no hay datos del
        // cliente de por medio: borrar en el nodo destruye los archivos del
        // servidor. En una instalacion que nunca termino no hay nada que
        // perder, pero en la reinstalacion de un servidor que ya funcionaba
        // estariamos borrandole el mundo entero a un cliente.
        if ($forzado && !$forceAnyway && !$this->safeToDestroy($server)) {
            $forzado = false;
            $warnings[] = 'No se borro en el nodo porque este servidor ya tenia una instalacion completada '
                . 'y hacerlo destruiria los archivos del cliente. Se marco como fallida y se cambio el puerto; '
                . 'el contenedor de instalacion del nodo puede seguir corriendo hasta que termine solo.';

            ExtensionEvent::log('warning', 'installs', sprintf(
                'No se forzo la parada de "%s": tiene datos y borrarlo en el nodo los destruiria',
                $server->name
            ), [], $server->id);
        }

        if ($forzado) {
            try {
                $this->daemon->setServer($server)->delete();
                $wingsDeleted = true;
            } catch (\Throwable $e) {
                $mensaje = $this->short($e);

                // Un 404 significa que el servidor ya no estaba en el nodo:
                // el objetivo (que no siga instalando) ya se cumple.
                if (str_contains($mensaje, '404') || stripos($mensaje, 'not exist') !== false) {
                    $wingsDeleted = true;
                } else {
                    $warnings[] = 'No se pudo borrar el servidor en el nodo, la instalacion podria seguir corriendo alli: ' . $mensaje;
                }
            }
        }

        // 2) El panel deja de considerarlo "instalando". Esto es lo que
        //    desbloquea la pantalla del cliente.
        $status = $wasReinstall ? Server::STATUS_REINSTALL_FAILED : Server::STATUS_INSTALL_FAILED;
        $server->forceFill(['status' => $status])->save();

        // 3) Cambio de puerto.
        $rotation = null;
        $rotate = in_array($mode, [self::MODE_FAIL_ROTATE, self::MODE_FORCE_ROTATE], true);

        if ($rotate) {
            try {
                $rotation = $this->ports->rotate($server, $this->settings->bool('watchdog_same_ip'));

                if ($rotation === null) {
                    $warnings[] = 'El nodo no tiene ningun puerto libre, no se pudo cambiar la asignacion.';
                }
            } catch (\Throwable $e) {
                $warnings[] = 'No se pudo cambiar el puerto: ' . $this->short($e);
            }
        }

        // 4) Se avisa al nodo de la nueva configuracion. Si el servidor se
        //    borro en el paso 1 esto ya no aplica.
        if (!$wingsDeleted) {
            try {
                $this->daemon->setServer($server->refresh())->sync();
            } catch (\Throwable $e) {
                $warnings[] = 'No se pudo sincronizar con el nodo: ' . $this->short($e);
            }
        }

        // 5) Se vuelve a dejar el estado como debe quedar. Entre los pasos 1 y
        //    4 puede haber llegado el aviso de wings diciendo que la
        //    instalacion "termino" (mal), y ese aviso escribe en el servidor.
        $server->refresh();

        if ($server->status === Server::STATUS_INSTALLING) {
            $server->forceFill(['status' => $status])->save();
        }

        // 6) Historial.
        $this->closeInstallEvent($server, $by, $minutes, $rotation, $mode, $wingsDeleted);

        ExtensionEvent::log('warning', 'installs', sprintf(
            'Instalacion detenida en "%s" tras %d minutos (%s)',
            $server->name,
            $minutes,
            $by
        ), [
            'modo' => $mode,
            'puerto_anterior' => $rotation['old'] ?? null,
            'puerto_nuevo' => $rotation['new'] ?? null,
            'borrado_en_wings' => $wingsDeleted,
            'avisos' => $warnings,
        ], $server->id);

        // 7) Correo al dueno.
        if ($notifyOwner) {
            $this->notifyOwner($server, $minutes, $rotation, $warnings);
        }

        return [
            'status' => $status,
            'port_changed' => $rotation !== null,
            'old_allocation' => $rotation['old'] ?? null,
            'new_allocation' => $rotation['new'] ?? null,
            'wings_deleted' => $wingsDeleted,
            'needs_recreate' => $wingsDeleted,
            'forced' => $forzado,
            'warnings' => $warnings,
        ];
    }

    /**
     * ¿Se puede borrar este servidor en el nodo sin cargarse datos del cliente?
     *
     * Solo si nunca llego a completarse una instalacion. Ojo con un detalle:
     * el panel rellena installed_at incluso cuando la instalacion FALLA, asi
     * que ese campo por si solo no vale. Por eso se mira tambien el historial
     * propio: si todos los intentos anteriores acabaron mal, no hay nada
     * valioso en el disco.
     */
    public function safeToDestroy(Server $server): bool
    {
        // Nunca se instalo: seguro.
        if ($server->installed_at === null) {
            return true;
        }

        $historial = InstallEvent::query()
            ->where('server_id', $server->id)
            ->whereIn('status', [
                InstallEvent::STATUS_SUCCESS,
                InstallEvent::STATUS_FAILED,
                InstallEvent::STATUS_TIMEOUT,
                InstallEvent::STATUS_CANCELLED,
            ])
            ->get(['status']);

        // Sin historial propio no se puede saber, asi que se es prudente:
        // installed_at con fecha puede significar un servidor que funcionaba.
        if ($historial->isEmpty()) {
            return false;
        }

        // Si alguna vez termino bien, tiene datos del cliente.
        return !$historial->contains(fn ($e) => $e->status === InstallEvent::STATUS_SUCCESS);
    }

    /**
     * Vuelve a crear el servidor en el nodo y lanza la instalacion.
     *
     * Hace falta despues de un corte forzado, porque el servidor ya no existe
     * en wings y el boton nativo de reinstalar del panel daria un 404. Si
     * resulta que si existe (porque el corte no llego a borrarlo), se usa la
     * reinstalacion normal en vez de intentar crearlo dos veces.
     */
    public function recreate(Server $server): void
    {
        $server->forceFill(['status' => Server::STATUS_INSTALLING])->save();

        try {
            $this->daemon->setServer($server)->create(false);
            $via = 'recreado en el nodo';
        } catch (\Throwable $e) {
            $mensaje = $this->short($e);
            $yaExiste = str_contains($mensaje, '409')
                || str_contains($mensaje, '422')
                || stripos($mensaje, 'already exists') !== false;

            if (!$yaExiste) {
                // No se pudo crear y tampoco es que ya existiera: se deja el
                // servidor como estaba para que no quede colgado en
                // "instalando" para siempre.
                $server->forceFill([
                    'status' => $server->installed_at === null
                        ? Server::STATUS_INSTALL_FAILED
                        : Server::STATUS_REINSTALL_FAILED,
                ])->save();

                throw $e;
            }

            // El servidor seguia en el nodo: se reinstala por la via normal.
            $this->daemon->setServer($server)->reinstall();
            $via = 'reinstalado (seguia existiendo en el nodo)';
        }

        $this->openInstallEvent($server, true);

        ExtensionEvent::log('info', 'installs', sprintf(
            'Servidor "%s" %s, instalando de nuevo',
            $server->name,
            $via
        ), ['puerto' => $this->ports->label($server->allocation)], $server->id);
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

    private function closeInstallEvent(Server $server, string $by, int $minutes, ?array $rotation, string $mode, bool $wingsDeleted): void
    {
        $event = $this->openEventFor($server) ?? $this->openInstallEvent($server, $server->installed_at !== null);

        $event->fill([
            'status' => $by === 'sistema' ? InstallEvent::STATUS_TIMEOUT : InstallEvent::STATUS_CANCELLED,
            'resolution' => $mode,
            'forced' => $mode === self::MODE_FORCE_ROTATE,
            'wings_deleted' => $wingsDeleted,
            'cancelled_by' => $by,
            'new_allocation' => $rotation['new'] ?? null,
            'old_allocation' => $rotation['old'] ?? $event->old_allocation,
            'finished_at' => now(),
            'duration_seconds' => $minutes * 60,
            'notes' => $wingsDeleted
                ? 'Instalacion cortada de raiz: el servidor se borro en el nodo para matar el contenedor. Hay que recrearlo antes de reinstalar.'
                : 'La instalacion se marco como fallida en el panel (el contenedor del nodo puede seguir corriendo).',
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
