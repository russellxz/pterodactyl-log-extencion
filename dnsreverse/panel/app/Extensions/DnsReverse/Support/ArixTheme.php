<?php

namespace Pterodactyl\Extensions\DnsReverse\Support;

use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

/**
 * Integracion con el tema Arix.
 *
 * COMO PONE ARIX SUS BOTONES (esto es lo importante y por lo que existe esta
 * clase):
 *
 * Arix NO saca el menu del cliente de routes.ts como hace Pterodactyl de
 * serie. Lo saca de una lista de enlaces guardada en la base de datos, en la
 * tabla `settings`, bajo la clave `settings::arix:links`. Es un JSON con
 * categorias ("general", "management", "configuration") y dentro los enlaces.
 *
 * Cada enlace es asi:
 *
 *     [
 *         'icon'       => 'HiOutlineGlobeAlt',   nombre de un icono de react-icons/hi
 *         'name'       => 'DNS Reverse',         texto (pasa por el traductor)
 *         'url'        => '/dnsreverse',         relativo a /server/{id}
 *         'permission' => [],                    vacio = lo ve todo el mundo
 *         'nests'      => [],                    vacio = todos los tipos
 *         'eggs'       => [],
 *         'active'     => true,
 *     ]
 *
 * Asi que en Arix hacen falta DOS cosas para tener el boton nativo:
 *
 *   1. la ruta en resources/scripts/routers/routes.ts  -> la pagina existe
 *      (de eso se encarga tools/patch-frontend.php)
 *   2. el enlace en settings::arix:links               -> el boton se ve
 *      (de eso se encarga esta clase)
 *
 * Con eso el boton sale en TODOS los diseños del tema (barra lateral, pill,
 * slim, iconos) y ademas en su buscador, porque todos leen la misma lista.
 *
 * El enlace se guarda como uno mas: el administrador puede moverlo de sitio,
 * cambiarle el icono o el nombre desde Admin -> Arix -> Links y no se pierde.
 */
class ArixTheme
{
    public const CLAVE = 'settings::arix:links';

    /** Ruta del enlace, relativa a /server/{id}. */
    public const URL = '/dnsreverse';

    public const NOMBRE = 'DNS Reverse';

    /** Icono de react-icons/hi. Arix los carga por nombre. */
    public const ICONO = 'HiOutlineGlobeAlt';

    /**
     * ¿Esta puesto el tema Arix en este panel?
     */
    public static function instalado(): bool
    {
        if (class_exists(\Pterodactyl\Http\ViewComposers\ArixConfiguration::class)) {
            return true;
        }

        if (is_file(config_path('arixTheme.php'))) {
            return true;
        }

        try {
            return \Illuminate\Support\Facades\DB::table('settings')
                ->where('key', 'like', 'settings::arix:%')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * ¿Esta ya el enlace de DNS Reverse en el menu del tema?
     */
    public static function tieneEnlace(): bool
    {
        foreach (self::enlaces() as $categoria) {
            foreach ($categoria['links'] ?? [] as $enlace) {
                if (self::esNuestro($enlace)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Anade el enlace al menu del tema. Se puede llamar mil veces.
     *
     * @return array{ok: bool, cambiado: bool, message: string}
     */
    public static function anadirEnlace(): array
    {
        if (!self::instalado()) {
            return ['ok' => false, 'cambiado' => false, 'message' => 'El tema Arix no esta instalado en este panel.'];
        }

        $enlaces = self::enlaces();

        if ($enlaces === []) {
            return ['ok' => false, 'cambiado' => false, 'message' => 'No se pudo leer el menu del tema Arix.'];
        }

        if (self::tieneEnlace()) {
            return ['ok' => true, 'cambiado' => false, 'message' => 'El enlace ya estaba en el menu del tema.'];
        }

        // Va en "management", junto a Archivos, Bases de datos y Red, que es
        // donde encaja un dominio. Si ese grupo no existe (el administrador
        // puede haber renombrado los suyos), se pone en el ultimo.
        $destino = array_key_exists('management', $enlaces)
            ? 'management'
            : array_key_last($enlaces);

        $enlaces[$destino]['links'][] = self::plantilla();

        if (!self::guardar($enlaces)) {
            return ['ok' => false, 'cambiado' => false, 'message' => 'No se pudo guardar el menu del tema Arix.'];
        }

        return [
            'ok' => true,
            'cambiado' => true,
            'message' => 'Enlace anadido al menu del tema, en el grupo "' . $destino . '".',
        ];
    }

    /**
     * Quita el enlace del menu del tema.
     *
     * @return array{ok: bool, cambiado: bool, message: string}
     */
    public static function quitarEnlace(): array
    {
        if (!self::instalado()) {
            return ['ok' => true, 'cambiado' => false, 'message' => 'El tema Arix no esta instalado: no hay nada que quitar.'];
        }

        $enlaces = self::enlaces();
        $quitados = 0;

        foreach ($enlaces as $clave => $categoria) {
            $antes = count($categoria['links'] ?? []);

            $enlaces[$clave]['links'] = array_values(array_filter(
                $categoria['links'] ?? [],
                fn ($enlace) => !self::esNuestro($enlace)
            ));

            $quitados += $antes - count($enlaces[$clave]['links']);
        }

        if ($quitados === 0) {
            return ['ok' => true, 'cambiado' => false, 'message' => 'El enlace no estaba en el menu del tema.'];
        }

        if (!self::guardar($enlaces)) {
            return ['ok' => false, 'cambiado' => false, 'message' => 'No se pudo guardar el menu del tema Arix.'];
        }

        return ['ok' => true, 'cambiado' => true, 'message' => 'Enlace quitado del menu del tema.'];
    }

    // -----------------------------------------------------------------------

    /**
     * El menu del tema tal y como esta ahora mismo.
     *
     * Si el administrador nunca ha tocado la pantalla de enlaces, la clave no
     * existe y el tema esta usando sus valores de serie. En ese caso se cogen
     * esos mismos valores del propio tema, para no inventarse un menu distinto
     * del que el cliente esta viendo.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enlaces(): array
    {
        $guardado = null;

        try {
            $guardado = app(SettingsRepositoryInterface::class)->get(self::CLAVE);
        } catch (\Throwable) {
            try {
                $guardado = \Illuminate\Support\Facades\DB::table('settings')
                    ->where('key', self::CLAVE)
                    ->value('value');
            } catch (\Throwable) {
                $guardado = null;
            }
        }

        if (is_string($guardado) && $guardado !== '') {
            $lista = json_decode($guardado, true);

            if (is_array($lista) && $lista !== []) {
                return $lista;
            }
        }

        return self::porDefectoDelTema();
    }

    /**
     * El menu de serie, preguntandoselo al propio tema.
     *
     * Se saca de su ArixConfiguration para que sea exactamente el que el tema
     * esta pintando ahora mismo, aunque una version nueva lo cambie. Si no se
     * puede leer, se usa la copia de abajo (Arix 2.1.2).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function porDefectoDelTema(): array
    {
        $clase = \Pterodactyl\Http\ViewComposers\ArixConfiguration::class;

        if (class_exists($clase)) {
            try {
                $metodo = new \ReflectionMethod($clase, 'defaultLinks');
                $metodo->setAccessible(true);

                $lista = $metodo->invoke($metodo->isStatic() ? null : app($clase));

                if (is_array($lista) && $lista !== []) {
                    return $lista;
                }
            } catch (\Throwable) {
                // Se sigue con la copia de abajo.
            }
        }

        return self::copiaDeSeguridadDelMenu();
    }

    /**
     * Copia del menu de serie de Arix 2.1.2.
     *
     * Solo se usa si no se puede preguntar al tema. Es preferible esto a
     * dejar al cliente sin menu.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function copiaDeSeguridadDelMenu(): array
    {
        $grupo = fn (string $nombre, array $enlaces) => [
            'name' => $nombre,
            'permission' => [],
            'nests' => [],
            'eggs' => [],
            'active' => true,
            'links' => $enlaces,
        ];

        $enlace = fn (string $icono, string $nombre, string $url, array $permisos = []) => [
            'icon' => $icono,
            'name' => $nombre,
            'url' => $url,
            'permission' => $permisos,
            'nests' => [],
            'eggs' => [],
            'active' => true,
        ];

        return [
            'general' => $grupo('general', [
                $enlace('HiOutlineViewGrid', 'dashboard', '/'),
                $enlace('HiOutlineTerminal', 'console', '/console'),
                $enlace('HiOutlineCog', 'settings', '/settings', ['settings.*', 'file.sftp']),
                $enlace('HiOutlineEye', 'activity', '/activity', ['activity.*']),
            ]),
            'management' => $grupo('management', [
                $enlace('HiOutlineFolderOpen', 'files', '/files', ['file.*']),
                $enlace('HiOutlineDatabase', 'databases', '/databases', ['database.*']),
                $enlace('HiOutlineArchive', 'backups', '/backups', ['backup.*']),
                $enlace('HiOutlineGlobe', 'network', '/network', ['network.*']),
            ]),
            'configuration' => $grupo('configuration', [
                $enlace('HiOutlineCalendar', 'schedules', '/schedules', ['schedule.*']),
                $enlace('HiOutlineUsers', 'users', '/users', ['user.*']),
                $enlace('HiOutlineAdjustments', 'startup', '/startup', ['startup.*']),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function plantilla(): array
    {
        return [
            'icon' => self::ICONO,
            'name' => self::NOMBRE,
            'url' => self::URL,
            // Vacio a proposito: quien puede o no crear DNS se decide en el
            // servidor (limite del servidor, tipo de servidor y permisos del
            // subusuario), no escondiendo un boton.
            'permission' => [],
            'nests' => [],
            'eggs' => [],
            'active' => true,
        ];
    }

    private static function esNuestro(mixed $enlace): bool
    {
        if (!is_array($enlace)) {
            return false;
        }

        return rtrim((string) ($enlace['url'] ?? ''), '/') === rtrim(self::URL, '/');
    }

    /**
     * @param array<string, array<string, mixed>> $enlaces
     */
    private static function guardar(array $enlaces): bool
    {
        $json = json_encode($enlaces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {
            return false;
        }

        try {
            app(SettingsRepositoryInterface::class)->set(self::CLAVE, $json);

            return true;
        } catch (\Throwable) {
            // Ultimo recurso: escribir la fila a mano.
        }

        try {
            \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
                ['key' => self::CLAVE],
                ['value' => $json]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
