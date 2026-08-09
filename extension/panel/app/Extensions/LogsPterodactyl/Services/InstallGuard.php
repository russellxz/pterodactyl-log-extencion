<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\NodeAccess;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/**
 * Detiene instalaciones que se han quedado colgadas.
 *
 * Que hace exactamente al detener una, y en este orden:
 *
 *   1. Cambia el estado de la instalacion: el servidor se marca como
 *      INSTALADO. Es exactamente lo mismo que hace el boton rosa "Toggle
 *      Install Status" del area de administracion, y es lo unico que quita de
 *      en medio la pantalla "Running Installer" y devuelve al cliente el
 *      acceso a la consola y a la configuracion de su servidor.
 *   2. Le cambia el puerto a otro libre del mismo nodo.
 *   3. Le pasa al nodo la configuracion nueva, avisa al dueno y lo registra.
 *
 * El orden importa. Marcar el servidor como "instalacion fallida" (que es lo
 * que hacia antes) no sirve: la aplicacion del panel ensena la misma pantalla
 * de "Running Installer" para "installing", "install_failed" y
 * "reinstall_failed", asi que el cliente se quedaba igual de bloqueado. Solo
 * el estado "instalado" lo desbloquea.
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
 * termina por su cuenta; cuando lo hace, wings avisa al panel y el panel
 * vuelve a marcar el servidor como fallido, asi que la extension se queda
 * vigilando un rato para devolverlo a "instalado" (ver keepUnblocked()).
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
     *
     * Y hay un caso que hay que mirar aparte: un servidor al que ya se le paro
     * una instalacion antes. Ahi created_at puede ser de hace tres dias, asi
     * que si el cliente vuelve a instalar y se usara esa fecha, la instalacion
     * nueva pareceria colgada desde el primer minuto y el vigilante la cortaria
     * al instante. La de ahora no puede haber empezado antes de que terminara
     * la anterior.
     */
    public function startedAt(Server $server): CarbonImmutable
    {
        $tracked = $this->openEventFor($server);

        if ($tracked && $tracked->started_at) {
            return CarbonImmutable::parse($tracked->started_at);
        }

        $reference = CarbonImmutable::parse(($server->installed_at === null
            ? ($server->created_at ?: $server->updated_at)
            : ($server->updated_at ?: $server->created_at)) ?: now());

        $anterior = InstallEvent::query()
            ->where('server_id', $server->id)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        if ($anterior && $anterior->finished_at) {
            // El panel toca updated_at al poner el servidor en "instalando",
            // asi que es la mejor pista de cuando arranco este intento; el
            // final del intento anterior es el suelo por debajo del cual no
            // puede estar.
            $reference = max(
                CarbonImmutable::parse($anterior->finished_at),
                CarbonImmutable::parse($server->updated_at ?: $reference)
            );
        }

        return $reference->greaterThan(CarbonImmutable::now())
            ? CarbonImmutable::now()
            : $reference;
    }

    public function minutesInstalling(Server $server): int
    {
        return (int) $this->startedAt($server)->diffInMinutes(CarbonImmutable::now());
    }

    /**
     * Para la instalacion de un servidor.
     *
     * Primero se cambia el estado de la instalacion y despues el puerto, que es
     * el orden correcto: mientras el panel siga creyendo que el servidor esta
     * instalando (o que la instalacion fallo) el cliente no puede entrar en su
     * servidor ni ver la configuracion, por mucho puerto nuevo que se le
     * ponga.
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
     *     unblocked: bool,
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

        // 1) PRIMERO el estado de la instalacion: el servidor pasa a estar
        //    instalado, igual que con el boton "Toggle Install Status" del
        //    area de administracion. Esto es lo que desbloquea al cliente.
        $unblocked = $this->markInstalled($server);

        if (!$unblocked) {
            $warnings[] = 'El servidor esta suspendido, se ha dejado su estado como estaba.';
        }

        // 2) DESPUES el puerto: el cliente se encuentra el servidor en una
        //    direccion nueva y limpia, ya con acceso a su configuracion.
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

        // 4) Se vuelve a mirar el estado. Entre los pasos 1 y 3 puede haber
        //    llegado el aviso de wings diciendo que la instalacion termino, y
        //    ese aviso escribe en el servidor.
        $server->refresh();

        if ($unblocked && $this->isBlocked($server)) {
            $this->markInstalled($server);
        }

        // 5) Historial, con la vigilancia armada para que el aviso tardio del
        //    nodo no vuelva a dejar al cliente mirando "Running Installer".
        $event = $this->closeInstallEvent($server, $by, $minutes, $rotation, $mode);

        if ($unblocked) {
            $this->armUnblockGuard($event);
        }

        ExtensionEvent::log('warning', 'installs', sprintf(
            'Instalacion detenida en "%s" tras %d minutos (%s)',
            $server->name,
            $minutes,
            $by
        ), [
            'modo' => $mode,
            'estado_nuevo' => $unblocked ? 'instalado' : (string) $server->status,
            'puerto_anterior' => $rotation['old'] ?? null,
            'puerto_nuevo' => $rotation['new'] ?? null,
            'avisos' => $warnings,
        ], $server->id);

        // 6) Correo al dueno.
        if ($notifyOwner) {
            $this->notifyOwner($server, $minutes, $rotation, $warnings);
        }

        return [
            'status' => $unblocked ? 'instalado' : (string) $server->status,
            'unblocked' => $unblocked,
            'port_changed' => $rotation !== null,
            'old_allocation' => $rotation['old'] ?? null,
            'new_allocation' => $rotation['new'] ?? null,
            'warnings' => $warnings,
        ];
    }

    /**
     * Marca el servidor como instalado, que es lo mismo que hace el boton
     * "Toggle Install Status" del area de administracion cuando lo sacas de
     * "instalando".
     *
     * Un servidor suspendido se deja como esta: su estado no es un estado de
     * instalacion y pisarlo lo dejaria sin suspender.
     *
     * @return bool si de verdad quedo desbloqueado
     */
    public function markInstalled(Server $server): bool
    {
        if ($server->status === Server::STATUS_SUSPENDED) {
            return false;
        }

        // Se escribe con el constructor de consultas y no con save() por dos
        // motivos. Uno: asi no se vuelve a disparar el evento del modelo, que
        // es justo desde donde a veces se llama aqui. Y dos, el importante:
        // dentro del evento "updated" Eloquent todavia no ha sincronizado los
        // valores originales, asi que al dejar el campo como estaba antes del
        // guardado lo veria "sin cambios" y no escribiria nada.
        Server::query()->whereKey($server->getKey())->update(['status' => null]);

        $server->setAttribute('status', null);
        $server->syncOriginalAttribute('status');

        return true;
    }

    /**
     * Estados en los que el area de cliente ensena "Running Installer" y no
     * deja entrar en el servidor.
     */
    public function isBlocked(Server $server): bool
    {
        return in_array($server->status, [
            Server::STATUS_INSTALLING,
            Server::STATUS_INSTALL_FAILED,
            Server::STATUS_REINSTALL_FAILED,
        ], true);
    }

    /**
     * Deja anotado hasta cuando hay que mantener el servidor desbloqueado.
     */
    private function armUnblockGuard(InstallEvent $event): void
    {
        $minutes = $this->settings->int('unblock_guard_minutes', 0, 10080);

        if ($minutes <= 0) {
            return;
        }

        $event->forceFill(['unblock_until' => now()->addMinutes($minutes)])->save();
    }

    /**
     * Devuelve a "instalado" un servidor que la extension habia desbloqueado y
     * que ha vuelto a quedarse bloqueado por su cuenta.
     *
     * Pasa cuando el contenedor de instalacion colgado muere por fin, horas
     * despues: wings avisa al panel de que aquella instalacion fallo y el panel
     * marca el servidor como "install_failed". El cliente, que llevaba rato
     * trabajando tan tranquilo, se lo encuentra bloqueado sin haber tocado
     * nada. Esto lo deshace.
     *
     * Si el servidor esta instalando de verdad no se toca: eso quiere decir que
     * el cliente ha vuelto a darle a reinstalar, que es justo lo que se queria.
     */
    public function unblockIfGuarded(Server $server): bool
    {
        $event = $this->armedGuardFor($server);

        if (!$event) {
            return false;
        }

        // El cliente ha lanzado una instalacion nueva: la vigilancia sobra y
        // hay que quitarla de en medio para no estropearsela.
        if ($server->status === Server::STATUS_INSTALLING) {
            $this->disarmGuard($event);

            return false;
        }

        if (!$this->isBlocked($server)) {
            return false;
        }

        $bloqueo = (string) $server->status;

        if (!$this->markInstalled($server)) {
            return false;
        }

        $event->forceFill([
            'unblocked_times' => (int) $event->unblocked_times + 1,
            'notes' => trim((string) $event->notes . ' El nodo informo tarde de aquella instalacion y volvio a bloquear el servidor; se ha desbloqueado de nuevo.'),
        ])->save();

        ExtensionEvent::log('info', 'installs', sprintf(
            'El servidor "%s" se volvio a bloquear solo y se ha desbloqueado otra vez',
            $server->name
        ), [
            'estado_que_puso_el_panel' => $bloqueo,
            'veces' => (int) $event->unblocked_times,
        ], $server->id);

        return true;
    }

    /**
     * Repaso periodico de todos los servidores que quedaron bajo vigilancia.
     *
     * El observador de modelo ya arregla el caso normal al momento; esto es la
     * red de seguridad para cuando el estado se escribe sin pasar por Eloquent
     * y para ir retirando las vigilancias que ya no hacen falta.
     *
     * @return int cuantos servidores hubo que volver a desbloquear
     */
    public function keepUnblocked(): int
    {
        $eventos = InstallEvent::query()
            ->whereNotNull('unblock_until')
            ->get();

        if ($eventos->isEmpty()) {
            return 0;
        }

        $servidores = Server::query()
            ->whereIn('id', $eventos->pluck('server_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $arreglados = 0;

        foreach ($eventos as $evento) {
            // Vigilancia caducada o servidor que ya no existe: se retira.
            if (!$evento->unblock_until || $evento->unblock_until->isPast()) {
                $this->disarmGuard($evento);

                continue;
            }

            $server = $servidores->get($evento->server_id);

            if (!$server) {
                $this->disarmGuard($evento);

                continue;
            }

            if ($this->unblockIfGuarded($server)) {
                $arreglados++;
            }
        }

        return $arreglados;
    }

    /**
     * Vigilancia activa de un servidor, si la hay.
     */
    private function armedGuardFor(Server $server): ?InstallEvent
    {
        return InstallEvent::query()
            ->where('server_id', $server->id)
            ->whereNotNull('unblock_until')
            ->where('unblock_until', '>=', now())
            ->orderByDesc('unblock_until')
            ->first();
    }

    private function disarmGuard(InstallEvent $event): void
    {
        $event->forceFill(['unblock_until' => null])->save();
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
     * Un servidor acaba de entrar en "instalando".
     *
     * Esto se llama en el momento exacto en que el panel cambia el estado, asi
     * que la hora de arranque queda anotada al segundo. Es lo que hace que el
     * sistema se entere de las reinstalaciones sin depender de adivinarlo por
     * las fechas del servidor: `created_at` puede ser de hace tres dias y
     * `updated_at` lo toca cualquier cosa, y con cualquiera de los dos el
     * calculo salia mal (o la instalacion nueva parecia colgada desde el
     * primer minuto, o se quedaba en cero para siempre y no saltaba nunca ni
     * el corte automatico ni el boton del cliente).
     */
    public function beginInstall(Server $server): ?InstallEvent
    {
        if ($server->status !== Server::STATUS_INSTALLING) {
            return null;
        }

        // Si habia una fila abierta de un intento anterior, se cierra: el
        // servidor acaba de entrar en instalacion otra vez, asi que aquello
        // termino aunque nadie llegara a anotar como.
        $colgada = $this->openEventFor($server);

        if ($colgada) {
            $inicio = $colgada->started_at
                ? CarbonImmutable::parse($colgada->started_at)
                : CarbonImmutable::now();

            $colgada->fill([
                'status' => InstallEvent::STATUS_FAILED,
                'finished_at' => now(),
                'duration_seconds' => (int) $inicio->diffInSeconds(CarbonImmutable::now()),
                'notes' => $colgada->notes
                    ?: 'No se llego a saber como termino: el servidor volvio a entrar en instalacion.',
            ])->save();
        }

        return $this->openInstallEvent($server, $server->installed_at !== null, CarbonImmutable::now());
    }

    /**
     * Punto de entrada unico para los cambios de estado de un servidor.
     *
     * Lo llama el observador del modelo, asi que el historial se lleva al dia
     * al momento y no cada minuto cuando pasa el cron.
     */
    public function handleStatusChange(Server $server): void
    {
        if ($server->status === Server::STATUS_INSTALLING) {
            $this->beginInstall($server);
            $this->unblockIfGuarded($server);

            return;
        }

        // Ha salido de "instalando": primero se cierra la fila con lo que diga
        // el panel (que es la verdad de como acabo) y despues se mira si hay
        // que devolverlo a "instalado".
        $cerrado = $this->closeFor($server);

        // Si el fallo lo ha provocado el nodo (sigue ocupado con la
        // instalacion anterior y ha rechazado esta), el cliente no tiene
        // ninguna culpa y no se le deja el servidor bloqueado: se desbloquea y
        // se vuelve a vigilar. Un fallo de verdad si se le ensena, faltaria
        // mas, porque ahi si tiene algo que corregir.
        if ($cerrado?->diagnosis === InstallEvent::DIAG_NODE_BUSY && $this->isBlocked($server)) {
            if ($this->markInstalled($server)) {
                $this->armUnblockGuard($cerrado);
            }
        }

        $this->unblockIfGuarded($server);
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
     *
     * @return InstallEvent|null la fila que se acaba de cerrar, si habia alguna
     */
    public function closeFor(Server $server): ?InstallEvent
    {
        if ($server->status === Server::STATUS_INSTALLING) {
            return null;
        }

        $event = $this->openEventFor($server);

        if (!$event) {
            return null;
        }

        $failed = in_array($server->status, [
            Server::STATUS_INSTALL_FAILED,
            Server::STATUS_REINSTALL_FAILED,
        ], true);

        $started = $event->started_at ? CarbonImmutable::parse($event->started_at) : CarbonImmutable::now();

        // Distinguir una instalacion que acabo bien de un desbloqueo a mano.
        // Las dos dejan el servidor "instalado", pero solo cuando el nodo
        // informa de verdad se rellena installed_at (el panel escribe estado e
        // installed_at en el mismo guardado). Sin eso, lo que ha pasado es que
        // alguien le ha dado al boton "Toggle Install Status", y apuntarlo como
        // instalacion correcta falsearia el seguimiento de los reintentos.
        $completada = $server->installed_at !== null
            && CarbonImmutable::parse($server->installed_at)->greaterThanOrEqualTo($started->subMinute());

        if ($failed) {
            $estado = InstallEvent::STATUS_FAILED;
            $nota = 'El nodo informo de que la instalacion fallo.';
            $this->diagnoseFailure($event, $started);
        } elseif ($completada) {
            $estado = InstallEvent::STATUS_SUCCESS;
            $nota = 'Instalacion completada.';
        } else {
            $estado = InstallEvent::STATUS_CANCELLED;
            $nota = 'Desbloqueada a mano desde el panel: el nodo nunca llego a informar de que terminara.';
        }

        $event->fill([
            'status' => $estado,
            'cancelled_by' => $event->cancelled_by ?: ($estado === InstallEvent::STATUS_CANCELLED ? 'panel (a mano)' : null),
            'finished_at' => now(),
            'duration_seconds' => (int) $started->diffInSeconds(CarbonImmutable::now()),
            'new_allocation' => $event->new_allocation ?: $this->ports->label($server->allocation),
            'notes' => $event->notes ?: $nota,
        ])->save();

        return $event;
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

    private function closeInstallEvent(Server $server, string $by, int $minutes, ?array $rotation, string $mode): InstallEvent
    {
        $event = $this->openEventFor($server) ?? $this->openInstallEvent($server, $server->installed_at !== null);

        // Un servidor al que hay que pararle la instalacion una y otra vez sin
        // que el nodo diga nunca nada es la otra cara del mismo problema: el
        // contenedor de instalacion sigue colgado alli y no deja trabajar.
        $anterior = $event->previous();

        if ($anterior !== null && $anterior->wasStoppedBySystem()) {
            $event->diagnosis = InstallEvent::DIAG_NODE_SILENT;

            ExtensionEvent::log('warning', 'installs', sprintf(
                'Segunda parada seguida en "%s" sin que el nodo "%s" haya dicho nada',
                $server->name,
                $event->node_name ?: '?'
            ), [
                'motivo' => 'el contenedor de instalacion anterior sigue colgado en el nodo',
                'que_hacer_en_el_nodo' => $event->nodeCommands(),
            ], $server->id);
        }

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
                ? 'Instalacion detenida, servidor marcado como instalado y movido a otro puerto. El cliente ya puede entrar en su configuracion y reinstalar cuando quiera.'
                : 'Instalacion detenida y servidor marcado como instalado. No se pudo cambiar el puerto.',
        ])->save();

        return $event;
    }

    /**
     * Segundos por debajo de los cuales un fallo es sospechoso.
     *
     * Una instalacion de verdad, aunque acabe mal, tarda lo suyo: descargar la
     * imagen, crear el contenedor y correr el script. El rechazo por bloqueo,
     * en cambio, llega en un par de segundos: wings sincroniza con el panel,
     * ve que no puede coger el bloqueo y contesta.
     *
     * Se deja un margen amplio sobre esos dos segundos, pero corto para no
     * tragarse un fallo de verdad del cliente que reviente enseguida.
     */
    private const FALLO_INSTANTANEO = 20;

    /**
     * ¿Por que ha fallado esto de verdad?
     *
     * Lo que hay detras: wings guarda en memoria un "estoy instalando" por
     * servidor y no lo suelta hasta que el contenedor de instalacion termina.
     * Si aquel contenedor se quedo colgado, cualquier instalacion nueva de ese
     * servidor se rechaza al instante ("cannot obtain installation lock") y el
     * nodo le dice al panel que fallo. Visto desde fuera parece que el cliente
     * puso mal sus datos otra vez, cuando en realidad ni se ha llegado a
     * intentar y no hay nada que corregir en el panel.
     */
    private function diagnoseFailure(InstallEvent $event, CarbonImmutable $started): void
    {
        $segundos = (int) $started->diffInSeconds(CarbonImmutable::now());

        if ($segundos > self::FALLO_INSTANTANEO) {
            return;
        }

        $anterior = $event->previous();
        $trasUnaParada = $anterior !== null && $anterior->wasStoppedBySystem();

        $event->diagnosis = $trasUnaParada
            ? InstallEvent::DIAG_NODE_BUSY
            : InstallEvent::DIAG_FAST_FAIL;

        if (!$trasUnaParada) {
            return;
        }

        $event->notes = sprintf(
            'El nodo devolvio el fallo en %d segundos, justo despues de haberle parado otra instalacion a este servidor. '
            . 'Casi seguro que wings sigue ocupado con el contenedor de instalacion anterior y ha rechazado esta. '
            . 'Se suelta desde el nodo con: %s',
            $segundos,
            implode('  o bien  ', $event->nodeCommands())
        );

        ExtensionEvent::log('warning', 'installs', sprintf(
            'El nodo "%s" rechazo al instante la instalacion de "%s"',
            $event->node_name ?: '?',
            $event->server_name ?: '?'
        ), [
            'motivo' => 'wings sigue ocupado con el contenedor de instalacion anterior',
            'segundos' => $segundos,
            'que_hacer_en_el_nodo' => $event->nodeCommands(),
        ], $event->server_id);

        // Y si el nodo tiene acceso SSH configurado, se arregla solo.
        $this->fixNode($event);
    }

    /**
     * Intenta soltar el bloqueo entrando por SSH en el nodo.
     *
     * Solo se hace si el administrador ha guardado el acceso de ese nodo y ha
     * marcado "arreglarlo solo". Sin eso, la extension se limita a decir que
     * comando hay que ejecutar.
     */
    public function fixNode(InstallEvent $event): ?array
    {
        if (!$event->server_uuid || !$event->node_id) {
            return null;
        }

        try {
            $acceso = NodeAccess::query()
                ->where('node_id', $event->node_id)
                ->where('enabled', true)
                ->where('auto_fix', true)
                ->first();

            if (!$acceso) {
                return null;
            }

            $ssh = app(NodeSsh::class);
            $estado = $ssh->installerStatus($acceso, $event->server_uuid);

            if (!$estado['ok']) {
                ExtensionEvent::log('warning', 'nodos', 'No se pudo mirar el contenedor de instalacion en el nodo', [
                    'nodo' => $event->node_name,
                    'error' => $estado['error'],
                ], $event->server_id);

                return null;
            }

            if (!$estado['existe']) {
                // No habia contenedor colgado: el bloqueo era de otra cosa.
                return null;
            }

            $resultado = $ssh->killInstaller($acceso, $event->server_uuid);

            $event->forceFill([
                'notes' => trim((string) $event->notes) . ($resultado['ok']
                    ? ' | La extension entro en el nodo y elimino el contenedor colgado: el servidor ya se puede reinstalar.'
                    : ' | Se intento eliminar el contenedor en el nodo y no se pudo: ' . mb_substr((string) ($resultado['error'] ?: $resultado['salida']), 0, 200)),
            ])->save();

            if ($resultado['ok']) {
                ExtensionEvent::log('info', 'nodos', sprintf(
                    'Bloqueo de instalacion liberado solo en el nodo "%s" para "%s"',
                    $event->node_name ?: '?',
                    $event->server_name ?: '?'
                ), [
                    'contenedor_que_estaba_colgado' => $estado['detalle'],
                ], $event->server_id);
            }

            return $resultado;
        } catch (\Throwable $e) {
            ExtensionEvent::log('error', 'nodos', 'Fallo al intentar arreglar el nodo por SSH', [
                'error' => mb_substr($e->getMessage(), 0, 300),
            ], $event->server_id);

            return null;
        }
    }

    /**
     * Servidores cuyo nodo esta claramente atascado.
     *
     * Es lo que se ensena arriba del todo en la pantalla de instalaciones:
     * cuando esto sale, no hay nada que tocar en el panel ni en los datos del
     * cliente, hay que entrar al nodo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stuckNodes(int $horas = 24): array
    {
        $eventos = InstallEvent::query()
            ->whereIn('diagnosis', [InstallEvent::DIAG_NODE_BUSY, InstallEvent::DIAG_NODE_SILENT])
            ->where('finished_at', '>=', now()->subHours(max(1, $horas)))
            ->orderByDesc('finished_at')
            ->get();

        $porServidor = [];

        foreach ($eventos as $evento) {
            if (isset($porServidor[$evento->server_id])) {
                $porServidor[$evento->server_id]['veces']++;

                continue;
            }

            // Si despues de aquello ya se instalo bien, el nodo se desatasco
            // solo (el contenedor colgado acabo muriendo) y no hay que avisar.
            $resuelto = InstallEvent::query()
                ->where('server_id', $evento->server_id)
                ->where('status', InstallEvent::STATUS_SUCCESS)
                ->where('started_at', '>=', $evento->finished_at)
                ->exists();

            if ($resuelto) {
                continue;
            }

            $porServidor[$evento->server_id] = [
                'server_id' => $evento->server_id,
                'server_name' => $evento->server_name,
                'server_uuid' => $evento->server_uuid,
                'node_name' => $evento->node_name,
                'user_name' => $evento->user_name,
                'user_email' => $evento->user_email,
                'motivo' => $evento->diagnosis,
                'cuando' => $evento->finished_at,
                'veces' => 1,
                'comandos' => $evento->nodeCommands(),
                'admin_url' => url('/admin/servers/view/' . $evento->server_id),
            ];
        }

        return array_values($porServidor);
    }

    /**
     * Como acabo el ultimo intento de este servidor, si fue hace poco.
     *
     * La usa el area de cliente para seguir explicando que paso despues de que
     * el servidor quede desbloqueado: sin esto, en cuanto se desbloquea el
     * aviso desaparece y el cliente se queda sin saber por que le cambio el
     * puerto. Se mira siempre el ultimo intento, no la ultima parada, porque
     * si entre medias el nodo ha rechazado una reinstalacion eso es lo que hay
     * que contarle, y no lo de antes.
     */
    public function recentOutcome(Server $server, int $minutes = 120): ?InstallEvent
    {
        // Se ordena por id y no por fecha: el id siempre va en el orden real
        // en que se abrieron las filas, y una instalacion que ya estaba en
        // marcha puede registrarse con una fecha de arranque anterior a la de
        // la fila que se creo antes que ella.
        $ultimo = InstallEvent::query()
            ->where('server_id', $server->id)
            ->orderByDesc('id')
            ->first();

        // Si el ultimo intento sigue abierto, lo de antes ya no le sirve de
        // nada al cliente: hay una instalacion nueva en marcha.
        if (!$ultimo || $ultimo->finished_at === null) {
            return null;
        }

        return CarbonImmutable::parse($ultimo->finished_at)
            ->greaterThanOrEqualTo(CarbonImmutable::now()->subMinutes(max(1, $minutes)))
                ? $ultimo
                : null;
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
