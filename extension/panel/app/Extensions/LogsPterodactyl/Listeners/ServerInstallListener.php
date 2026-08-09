<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Listeners;

use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Models\Server;

/**
 * Cierra la fila del historial cuando el panel recibe de wings el aviso de
 * que una instalacion termino.
 *
 * Ojo: el panel solo dispara este evento si las notificaciones de instalacion
 * estan activadas en su configuracion, asi que no se puede depender solo de
 * el. El comando logspterodactyl:watch-installs vuelve a cuadrar el historial cada
 * minuto comparando con el estado real de cada servidor.
 */
class ServerInstallListener
{
    public function __construct(private InstallGuard $guard)
    {
    }

    public function handle(object $event): void
    {
        try {
            $server = $event->server ?? null;

            if (!$server instanceof Server) {
                return;
            }

            $this->guard->closeFor($server->refresh());
        } catch (\Throwable $e) {
            try {
                logger()->debug('[LogsPterodactyl] no se pudo cerrar el historial de instalacion: ' . $e->getMessage());
            } catch (\Throwable) {
                // Nada mas que hacer.
            }
        }
    }
}
