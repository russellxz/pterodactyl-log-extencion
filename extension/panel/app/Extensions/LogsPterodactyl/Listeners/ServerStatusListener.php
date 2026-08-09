<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Listeners;

use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Models\Server;

/**
 * Vigila los cambios de estado de los servidores que la extension desbloqueo.
 *
 * El caso que arregla es este: se para una instalacion colgada, el servidor
 * queda marcado como instalado y el cliente vuelve a tener acceso. Un rato
 * despues el contenedor de instalacion que seguia vivo en el nodo muere por
 * fin, wings avisa al panel de que aquella instalacion fallo y el panel marca
 * el servidor como "install_failed". El cliente se lo encuentra otra vez con
 * la pantalla "Running Installer" sin haber tocado nada.
 *
 * Aqui se pilla ese momento exacto (el panel guarda el servidor con Eloquent,
 * asi que salta el evento del modelo) y se deshace al instante. Si lo que ha
 * pasado es que el cliente ha vuelto a darle a instalar, no se toca nada: esa
 * instalacion es buena y tiene que seguir su curso.
 */
class ServerStatusListener
{
    /**
     * Se engancha al evento "updated" del modelo de servidor del panel.
     */
    public static function register(): void
    {
        Server::updated(function (Server $server) {
            static::handle($server);
        });
    }

    public static function handle(Server $server): void
    {
        try {
            if (!$server->wasChanged('status')) {
                return;
            }

            app(InstallGuard::class)->unblockIfGuarded($server);
        } catch (\Throwable $e) {
            // Esto corre dentro de un guardado del panel: pase lo que pase,
            // aqui no se rompe nada.
            try {
                logger()->debug('[LogsPterodactyl] no se pudo revisar el estado del servidor: ' . $e->getMessage());
            } catch (\Throwable) {
                // Nada mas que hacer.
            }
        }
    }
}
