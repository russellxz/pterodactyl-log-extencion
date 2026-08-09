<?php

namespace Pterodactyl\Extensions\DnsReverse\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Models\Server;

/**
 * Cada servidor nuevo nace con su limite de DNS ya puesto.
 *
 * En la version anterior todos los servidores se creaban con proxy_limit = 0,
 * asi que el cliente entraba en su panel, veia "no se pueden crear DNS porque
 * el limite es 0" y tenia que abrir un ticket. Aqui se pone el valor de la
 * configuracion (por defecto 1) en cuanto el servidor existe.
 *
 * Solo se toca si el valor que trae es 0 o nulo: si el administrador creo el
 * servidor con un limite mayor, se respeta.
 */
class ServerCreatedListener
{
    public function handle(Server $server): void
    {
        try {
            if (!Schema::hasColumn('servers', 'proxy_limit')) {
                return;
            }

            $porDefecto = Settings::make()->int('default_proxy_limit', 0, 100);

            if ($porDefecto < 1) {
                return;
            }

            $actual = (int) ($server->getAttribute('proxy_limit') ?? 0);

            if ($actual > 0) {
                return;
            }

            // Se escribe con una consulta directa a proposito: asi no se
            // disparan observadores del panel ni de otras extensiones, y no
            // hace falta que 'proxy_limit' este en $fillable del modelo del
            // nucleo (que despues de actualizar el panel no lo estara).
            DB::table('servers')->where('id', $server->id)->update(['proxy_limit' => $porDefecto]);

            $server->setAttribute('proxy_limit', $porDefecto);
        } catch (\Throwable $e) {
            try {
                logger()->debug('[DnsReverse] no se pudo poner el limite de DNS al servidor nuevo: ' . $e->getMessage());
            } catch (\Throwable) {
                // Sin logger no hay nada que hacer.
            }
        }
    }
}
