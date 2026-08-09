<?php

namespace Pterodactyl\Extensions\DnsReverse\Support;

use Illuminate\Support\Facades\Route;

/**
 * Enlaces a pantallas del propio panel, a prueba de temas.
 *
 * El tema Arix reemplaza routes/admin.php por el suyo. Si en alguna version
 * cambia o quita el nombre de una ruta, un route('admin.servers.view') sin
 * red de seguridad revienta la pagina entera con un error 500. Aqui se
 * comprueba antes y, si no existe, se devuelve la direccion a pelo, que en
 * Pterodactyl siempre ha sido la misma.
 */
class PanelLinks
{
    public static function server(int|string|null $id): string
    {
        return self::enlace('admin.servers.view', $id, '/admin/servers/view/');
    }

    public static function node(int|string|null $id): string
    {
        return self::enlace('admin.nodes.view', $id, '/admin/nodes/view/');
    }

    public static function user(int|string|null $id): string
    {
        return self::enlace('admin.users.view', $id, '/admin/users/view/');
    }

    private static function enlace(string $nombre, int|string|null $id, string $porDefecto): string
    {
        if ($id === null || $id === '') {
            return '#';
        }

        try {
            if (Route::has($nombre)) {
                return route($nombre, $id);
            }
        } catch (\Throwable) {
            // El tema tiene esa ruta con otros parametros: se usa la de siempre.
        }

        return url($porDefecto . $id);
    }
}
