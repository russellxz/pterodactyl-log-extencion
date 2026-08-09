<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Listeners;

use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Models\Server;

/**
 * Vigila los cambios de estado de los servidores.
 *
 * Hace dos cosas, las dos en el momento exacto en que el panel guarda el
 * servidor (lo hace con Eloquent, asi que salta el evento del modelo).
 *
 * 1. **Se entera de las instalaciones y reinstalaciones en cuanto empiezan.**
 *    Antes esto se deducia cada minuto desde el cron, mirando las fechas del
 *    servidor, y en un servidor que ya habia pasado por el problema salia mal:
 *    `created_at` podia ser de hace tres dias y `updated_at` lo toca cualquier
 *    cosa. Resultado: o la instalacion nueva parecia colgada desde el primer
 *    minuto, o se quedaba contando cero para siempre y no saltaba nunca ni el
 *    corte automatico ni el boton del cliente. Anotando la hora aqui, el dato
 *    es exacto y no depende de adivinar nada.
 *
 * 2. **Deshace los bloqueos que vuelven solos.** Se para una instalacion
 *    colgada, el servidor queda marcado como instalado y el cliente vuelve a
 *    tener acceso. Un rato despues el contenedor que seguia vivo en el nodo
 *    muere por fin, wings avisa al panel de que aquella instalacion fallo y el
 *    panel marca el servidor como "install_failed": el cliente se lo encuentra
 *    otra vez con la pantalla "Running Installer" sin haber tocado nada. Aqui
 *    se deshace al instante. Si lo que ha pasado es que el cliente ha vuelto a
 *    darle a instalar, no se toca: esa instalacion es buena y tiene que seguir
 *    su curso.
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

            app(InstallGuard::class)->handleStatusChange($server);
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
