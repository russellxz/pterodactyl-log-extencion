<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Copias de seguridad de las OTRAS extensiones que tengas en el panel.
 *
 * Para que sirve: actualizar Pterodactyl descomprime el paquete oficial encima
 * de la carpeta del panel y reescribe config/app.php. Lo que una extension
 * ANADE en carpetas propias sobrevive, pero su linea de proveedor en
 * config/app.php desaparece y el panel deja de cargarla; y si ademas toco
 * archivos del panel, esos si se pisan. El resultado es el clasico "he
 * actualizado y se me han ido los addons".
 *
 * Aqui se detecta lo que hay instalado, se empaqueta en un zip que te puedes
 * descargar, y se puede volver a dejar en su sitio despues de actualizar.
 *
 * Se usa zip y no tar por dos motivos: Pterodactyl ya exige la extension zip
 * de PHP (viene en su composer.json), asi que esta garantizada, y todo el
 * trabajo se hace desde PHP sin necesidad de ejecutar procesos, que es
 * justamente lo que suele estar capado en un panel.
 */
class ExtensionVault
{
    /**
     * Esta misma extension no se toca: tiene su propio install.sh y su
     * update.sh, y ya se vuelve a registrar sola despues de actualizar.
     */
    public const MIA = 'LogsPterodactyl';

    /**
     * Carpetas de app/Extensions que no son extensiones de nadie.
     */
    private const IGNORAR = ['.', '..', self::MIA];

    /**
     * app/Extensions NO es una carpeta de addons: la trae el propio
     * Pterodactyl y dentro estan SUS clases (Backups, Themes, Illuminate...).
     * Sin esta lista, la pantalla se llenaba de carpetas del panel como si
     * fueran extensiones instaladas.
     *
     * Ademas de la lista, se pide una senal positiva (ver pareceExtension):
     * ninguna de estas carpetas del panel registra un ServiceProvider, y una
     * extension de verdad si. Asi tambien quedan fuera las que anada
     * Pterodactyl en el futuro y no esten en esta lista.
     */
    private const NUCLEO_PTERODACTYL = [
        'Backups', 'Facades', 'Filesystem', 'Illuminate',
        'Laravel', 'Lcobucci', 'League', 'Spatie', 'Themes',
    ];

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Todo lo que parece una extension instalada en este panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detect(): array
    {
        $encontradas = [];

        foreach ($this->scanApp() as $clave => $datos) {
            $encontradas[$clave] = $datos;
        }

        foreach ($this->scanPublic() as $clave => $datos) {
            if (isset($encontradas[$clave])) {
                $encontradas[$clave]['paths'] = array_values(array_unique(
                    array_merge($encontradas[$clave]['paths'], $datos['paths'])
                ));

                continue;
            }

            $encontradas[$clave] = $datos;
        }

        foreach ($this->scanBlueprint() as $clave => $datos) {
            $encontradas[$clave] = $datos;
        }

        foreach ($this->scanOtherLayouts() as $clave => $datos) {
            if (isset($encontradas[$clave])) {
                $encontradas[$clave]['paths'] = array_values(array_unique(
                    array_merge($encontradas[$clave]['paths'], $datos['paths'])
                ));

                continue;
            }

            $encontradas[$clave] = $datos;
        }

        // Las lineas de proveedor son lo primero que se pierde al actualizar,
        // asi que se guardan aunque no se sepa de que extension son.
        $proveedores = $this->providerLines();

        foreach ($encontradas as $clave => $datos) {
            $suyas = array_values(array_filter(
                $proveedores,
                fn (string $linea) => stripos($linea, $datos['name']) !== false
            ));

            $encontradas[$clave]['providers'] = $suyas;
            $encontradas[$clave]['size'] = $this->sizeOf($datos['paths']);
        }

        $lista = array_values($encontradas);
        usort($lista, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $lista;
    }

    /**
     * Extensiones en app/Extensions/<Nombre>.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scanApp(): array
    {
        $base = base_path('app/Extensions');

        if (!is_dir($base)) {
            return [];
        }

        $salida = [];

        foreach ((array) @scandir($base) as $nombre) {
            if (in_array($nombre, self::IGNORAR, true) || !is_dir($base . '/' . $nombre)) {
                continue;
            }

            if (in_array($nombre, self::NUCLEO_PTERODACTYL, true)) {
                continue;
            }

            if (!$this->pareceExtension($base . '/' . $nombre, $nombre)) {
                continue;
            }

            $salida[strtolower($nombre)] = [
                'key' => strtolower($nombre),
                'name' => $nombre,
                'type' => 'app/Extensions',
                'paths' => ['app/Extensions/' . $nombre],
                'detail' => 'Extension con codigo propio en app/Extensions.',
            ];
        }

        return $salida;
    }

    /**
     * ¿Esta carpeta es de verdad una extension de alguien?
     *
     * Se pide una senal clara, no que este dentro de app/Extensions: ahi el
     * propio Pterodactyl guarda sus clases. Una extension de verdad hace al
     * menos una de estas tres cosas.
     */
    private function pareceExtension(string $ruta, string $nombre): bool
    {
        // 1) Registra un proveedor de servicios (es lo que hace toda extension
        //    que quiera engancharse al panel).
        foreach ([$ruta . '/*ServiceProvider.php', $ruta . '/*/*ServiceProvider.php'] as $patron) {
            if ((array) glob($patron) !== []) {
                return true;
            }
        }

        // 2) O aparece en config/app.php, que es donde se registran.
        foreach ($this->providerLines() as $linea) {
            if (stripos($linea, '\\' . $nombre . '\\') !== false) {
                return true;
            }
        }

        // 3) O se instala como un paquete propio.
        foreach (['composer.json', 'extension.json', 'install.sh', 'plugin.json'] as $marca) {
            if (is_file($ruta . '/' . $marca)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursos publicos en public/extensions/<nombre>.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scanPublic(): array
    {
        $base = public_path('extensions');

        if (!is_dir($base)) {
            return [];
        }

        $salida = [];

        foreach ((array) @scandir($base) as $nombre) {
            if (in_array($nombre, ['.', '..'], true) || !is_dir($base . '/' . $nombre)) {
                continue;
            }

            if (strcasecmp($nombre, self::MIA) === 0 || strtolower($nombre) === strtolower(self::MIA)) {
                continue;
            }

            $salida[strtolower($nombre)] = [
                'key' => strtolower($nombre),
                'name' => $nombre,
                'type' => 'public/extensions',
                'paths' => ['public/extensions/' . $nombre],
                'detail' => 'Recursos publicos (js, css, imagenes).',
            ];
        }

        return $salida;
    }

    /**
     * Extensiones de Blueprint, que es como se instala la mayoria de addons
     * de pago para Pterodactyl.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scanBlueprint(): array
    {
        $base = base_path('.blueprint/extensions');

        if (!is_dir($base)) {
            return [];
        }

        $salida = [];

        foreach ((array) @scandir($base) as $nombre) {
            if (in_array($nombre, ['.', '..'], true) || !is_dir($base . '/' . $nombre)) {
                continue;
            }

            $rutas = ['.blueprint/extensions/' . $nombre];

            foreach ([
                'public/extensions/' . $nombre,
                'resources/views/blueprint/extensions/' . $nombre,
                'app/BlueprintFramework/Extensions/' . $nombre,
            ] as $extra) {
                if (file_exists(base_path($extra))) {
                    $rutas[] = $extra;
                }
            }

            $salida['blueprint:' . strtolower($nombre)] = [
                'key' => 'blueprint:' . strtolower($nombre),
                'name' => $nombre,
                'type' => 'blueprint',
                'paths' => $rutas,
                'detail' => 'Extension instalada con Blueprint.',
            ];
        }

        return $salida;
    }

    /**
     * Otros sitios donde los addons de Pterodactyl suelen dejar sus cosas.
     *
     * No todos usan app/Extensions: hay muchos que cuelgan sus controladores y
     * sus vistas de las carpetas del panel, en subcarpetas propias.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scanOtherLayouts(): array
    {
        $salida = [];

        $bases = [
            'app/Http/Controllers/Admin/Extensions' => 'Controladores propios dentro del panel.',
            'resources/views/admin/extensions' => 'Vistas propias dentro del panel.',
            'app/BlueprintFramework/Extensions' => 'Extension de Blueprint.',
        ];

        foreach ($bases as $relativa => $detalle) {
            $base = base_path($relativa);

            if (!is_dir($base)) {
                continue;
            }

            foreach ((array) @scandir($base) as $nombre) {
                if (in_array($nombre, ['.', '..'], true) || !is_dir($base . '/' . $nombre)) {
                    continue;
                }

                if (strcasecmp($nombre, self::MIA) === 0) {
                    continue;
                }

                $clave = strtolower($nombre);

                if (isset($salida[$clave])) {
                    $salida[$clave]['paths'][] = $relativa . '/' . $nombre;

                    continue;
                }

                $salida[$clave] = [
                    'key' => $clave,
                    'name' => $nombre,
                    'type' => 'addon del panel',
                    'paths' => [$relativa . '/' . $nombre],
                    'detail' => $detalle,
                ];
            }
        }

        return $salida;
    }

    /**
     * Lineas de proveedor de config/app.php que no son del panel ni de Laravel.
     *
     * Es lo primero que se pierde al actualizar: el paquete oficial trae su
     * propio config/app.php y se lleva por delante todo lo que hubiera anadido
     * cualquier addon.
     *
     * @return array<int, string>
     */
    public function providerLines(): array
    {
        $config = @file_get_contents(base_path('config/app.php'));

        if (!is_string($config)) {
            return [];
        }

        $lineas = [];

        foreach (preg_split('/\R/', $config) ?: [] as $linea) {
            $limpia = trim($linea);

            if (!str_contains($limpia, 'ServiceProvider::class')) {
                continue;
            }

            // Los proveedores del propio Pterodactyl y de Laravel no cuentan.
            if (preg_match('#^//#', $limpia)
                || str_contains($limpia, 'Illuminate\\')
                || str_contains($limpia, 'Laravel\\')
                || preg_match('#Pterodactyl\\\\Providers\\\\#', $limpia)) {
                continue;
            }

            $lineas[] = $limpia;
        }

        return $lineas;
    }

    /**
     * Empaqueta las extensiones indicadas en un zip descargable.
     *
     * @param array<int, string> $claves
     *
     * @return array{file: string, path: string, size: int, incluidas: array<int, string>, avisos: array<int, string>}
     */
    public function backup(array $claves = [], ?int $userId = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException(
                'Falta la extension zip de PHP. Instalala (php-zip) y vuelve a intentarlo.'
            );
        }

        $todas = $this->detect();

        $elegidas = $claves === []
            ? $todas
            : array_values(array_filter($todas, fn (array $e) => in_array($e['key'], $claves, true)));

        if ($elegidas === []) {
            throw new \RuntimeException('No hay ninguna extension que respaldar.');
        }

        $dir = $this->vaultDir();

        // El sufijo aleatorio no es decorativo: dos copias hechas en el mismo
        // segundo (por ejemplo la automatica de "quitar extension" justo
        // despues de una manual) se pisaban la una a la otra.
        $nombre = 'extensiones-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.zip';
        $destino = $dir . '/' . $nombre;

        $zip = new \ZipArchive();

        if ($zip->open($destino, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo en ' . $dir . '. Revisa los permisos de esa carpeta.');
        }

        $avisos = [];
        $incluidas = [];
        $manifiesto = [
            'creado' => now()->toDateTimeString(),
            'panel' => base_path(),
            'version_panel' => (string) config('app.version', ''),
            'extensiones' => [],
            'proveedores' => $this->providerLines(),
        ];

        foreach ($elegidas as $extension) {
            $archivos = 0;

            foreach ($extension['paths'] as $relativa) {
                $absoluta = base_path($relativa);

                if (!file_exists($absoluta)) {
                    continue;
                }

                $archivos += $this->addPath($zip, $absoluta, 'archivos/' . $relativa, $avisos);
            }

            if ($archivos === 0) {
                $avisos[] = 'De "' . $extension['name'] . '" no se pudo leer ningun archivo.';

                continue;
            }

            $incluidas[] = $extension['name'];
            $manifiesto['extensiones'][] = [
                'key' => $extension['key'],
                'name' => $extension['name'],
                'type' => $extension['type'],
                'paths' => $extension['paths'],
                'providers' => $extension['providers'] ?? [],
                'archivos' => $archivos,
            ];
        }

        $zip->addFromString('manifest.json', (string) json_encode($manifiesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('LEEME.txt', $this->readme($manifiesto));
        $zip->close();

        @chmod($destino, 0640);

        ExtensionEvent::log('info', 'extensiones', 'Copia de seguridad de las extensiones del panel', [
            'archivo' => $nombre,
            'incluidas' => $incluidas,
            'avisos' => $avisos,
        ]);

        return [
            'file' => $nombre,
            'path' => $destino,
            'size' => (int) @filesize($destino),
            'incluidas' => $incluidas,
            'avisos' => $avisos,
        ];
    }

    /**
     * Mete en el zip un archivo o una carpeta entera.
     *
     * @param array<int, string> $avisos
     *
     * @return int cuantos archivos entraron
     */
    private function addPath(\ZipArchive $zip, string $absoluta, string $dentro, array &$avisos): int
    {
        if (is_file($absoluta)) {
            if (!is_readable($absoluta)) {
                $avisos[] = 'No se pudo leer ' . $absoluta;

                return 0;
            }

            return $zip->addFile($absoluta, $dentro) ? 1 : 0;
        }

        if (!is_dir($absoluta)) {
            return 0;
        }

        $zip->addEmptyDir($dentro);
        $total = 0;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relativa = substr($item->getPathname(), strlen($absoluta) + 1);
            $relativa = str_replace(DIRECTORY_SEPARATOR, '/', $relativa);

            // node_modules dentro de una extension es basura reinstalable y
            // puede pesar cientos de megas. Se salta la carpeta entera, no
            // solo lo que hay dentro.
            if ($this->esBasura($relativa)) {
                continue;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($dentro . '/' . $relativa);

                continue;
            }

            if (!$item->isReadable()) {
                $avisos[] = 'No se pudo leer ' . $item->getPathname();

                continue;
            }

            if ($zip->addFile($item->getPathname(), $dentro . '/' . $relativa)) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Carpetas que no tiene sentido meter en la copia: se reinstalan solas y
     * pueden ocupar mas que todo lo demas junto.
     */
    private function esBasura(string $relativa): bool
    {
        foreach (explode('/', $relativa) as $parte) {
            if (in_array($parte, ['node_modules', '.git', 'vendor'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copias que hay guardadas ahora mismo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function backups(): array
    {
        $dir = $this->vaultDir(false);

        if (!is_dir($dir)) {
            return [];
        }

        $salida = [];

        foreach ((array) glob($dir . '/extensiones-*.zip') as $archivo) {
            $salida[] = [
                'file' => basename($archivo),
                'size' => (int) @filesize($archivo),
                'created_at' => date('Y-m-d H:i:s', (int) @filemtime($archivo)),
                'contenido' => $this->manifestOf($archivo)['extensiones'] ?? [],
            ];
        }

        usort($salida, fn ($a, $b) => strcmp($b['file'], $a['file']));

        return $salida;
    }

    /**
     * Ruta absoluta de una copia, comprobando que el nombre es de los nuestros.
     *
     * Nunca se construye la ruta con lo que llegue por la peticion sin pasar
     * por aqui: es lo unico que separa una descarga de un "dame /etc/passwd".
     */
    public function pathFor(string $file): ?string
    {
        if (!preg_match('/^extensiones-\d{8}-\d{6}(-[a-f0-9]{4})?\.zip$/', $file)) {
            return null;
        }

        $ruta = $this->vaultDir(false) . '/' . $file;

        return is_file($ruta) ? $ruta : null;
    }

    public function deleteBackup(string $file): bool
    {
        $ruta = $this->pathFor($file);

        if (!$ruta) {
            return false;
        }

        ExtensionEvent::log('info', 'extensiones', 'Copia de extensiones borrada', ['archivo' => $file]);

        return @unlink($ruta);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestOf(string $ruta): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        $zip = new \ZipArchive();

        if ($zip->open($ruta) !== true) {
            return [];
        }

        $json = $zip->getFromName('manifest.json');
        $zip->close();

        if (!is_string($json)) {
            return [];
        }

        return (array) json_decode($json, true);
    }

    /**
     * Vuelve a dejar en su sitio las extensiones de una copia.
     *
     * @return array{restauradas: array<int, string>, proveedores: array<int, string>, avisos: array<int, string>}
     */
    public function restore(string $file): array
    {
        $ruta = $this->pathFor($file);

        if (!$ruta) {
            throw new \RuntimeException('Esa copia no existe.');
        }

        $manifiesto = $this->manifestOf($ruta);

        if (($manifiesto['extensiones'] ?? []) === []) {
            throw new \RuntimeException('La copia no tiene manifiesto: no se sabe donde iba cada archivo.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($ruta) !== true) {
            throw new \RuntimeException('No se pudo abrir la copia.');
        }

        $avisos = [];
        $restauradas = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $dentro = (string) $zip->getNameIndex($i);

            if (!str_starts_with($dentro, 'archivos/')) {
                continue;
            }

            $relativa = substr($dentro, strlen('archivos/'));

            // Cinturon y tirantes: aunque el zip lo hayamos hecho nosotros,
            // nada de rutas que se salgan de la carpeta del panel.
            if ($relativa === '' || str_contains($relativa, '..')) {
                $avisos[] = 'Se ignoro una ruta sospechosa dentro de la copia: ' . $dentro;

                continue;
            }

            $destino = base_path($relativa);

            if (str_ends_with($dentro, '/')) {
                @mkdir($destino, 0755, true);

                continue;
            }

            @mkdir(dirname($destino), 0755, true);

            $contenido = $zip->getFromIndex($i);

            if ($contenido === false || @file_put_contents($destino, $contenido) === false) {
                $avisos[] = 'No se pudo escribir ' . $relativa;
            }
        }

        $zip->close();

        foreach ($manifiesto['extensiones'] as $extension) {
            $restauradas[] = (string) ($extension['name'] ?? '?');
        }

        // Las lineas de proveedor no se tocan solas: se le ensenan al
        // administrador para que las pegue el, porque meter codigo a ciegas en
        // config/app.php es la mejor forma de dejar el panel en blanco.
        $proveedores = array_values(array_unique(array_merge(
            (array) ($manifiesto['proveedores'] ?? []),
            ...array_map(fn ($e) => (array) ($e['providers'] ?? []), $manifiesto['extensiones'])
        )));

        $faltan = array_values(array_filter(
            $proveedores,
            fn (string $linea) => !in_array($linea, $this->providerLines(), true)
        ));

        ExtensionEvent::log('info', 'extensiones', 'Extensiones restauradas desde una copia', [
            'archivo' => $file,
            'restauradas' => $restauradas,
            'proveedores_que_faltan' => $faltan,
            'avisos' => $avisos,
        ]);

        return ['restauradas' => $restauradas, 'proveedores' => $faltan, 'avisos' => $avisos];
    }

    /**
     * Quita una extension del panel, guardando antes una copia.
     *
     * Solo se borran las carpetas propias de la extension. Lo que haya tocado
     * de los archivos del panel no se puede deshacer desde aqui, y se dice
     * claramente en la pantalla.
     *
     * @return array{copia: string, borradas: array<int, string>, proveedores: array<int, string>}
     */
    public function remove(string $clave): array
    {
        $extension = null;

        foreach ($this->detect() as $candidata) {
            if ($candidata['key'] === $clave) {
                $extension = $candidata;

                break;
            }
        }

        if (!$extension) {
            throw new \RuntimeException('Esa extension ya no esta instalada.');
        }

        // Nunca se borra sin copia previa.
        $copia = $this->backup([$clave]);

        $borradas = [];

        foreach ($extension['paths'] as $relativa) {
            $absoluta = base_path($relativa);

            if (!file_exists($absoluta)) {
                continue;
            }

            $this->removeDirectory($absoluta);
            $borradas[] = $relativa;
        }

        ExtensionEvent::log('warning', 'extensiones', 'Extension quitada del panel: ' . $extension['name'], [
            'carpetas' => $borradas,
            'copia' => $copia['file'],
            'proveedores_pendientes' => $extension['providers'] ?? [],
        ]);

        return [
            'copia' => $copia['file'],
            'borradas' => $borradas,
            'proveedores' => (array) ($extension['providers'] ?? []),
        ];
    }

    private function removeDirectory(string $ruta): void
    {
        if (is_file($ruta) || is_link($ruta)) {
            @unlink($ruta);

            return;
        }

        if (!is_dir($ruta)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($ruta);
    }

    /**
     * Carpeta donde viven las copias, dentro de la de respaldos configurada.
     */
    public function vaultDir(bool $crear = true): string
    {
        $dir = rtrim((string) $this->settings->get('backup_path'), '/') . '/extensiones';

        if ($crear && !is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /**
     * @param array<int, string> $paths
     */
    private function sizeOf(array $paths): int
    {
        $total = 0;

        foreach ($paths as $relativa) {
            $absoluta = base_path($relativa);

            if (is_file($absoluta)) {
                $total += (int) @filesize($absoluta);

                continue;
            }

            if (!is_dir($absoluta)) {
                continue;
            }

            try {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($absoluta, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($items as $item) {
                    /** @var \SplFileInfo $item */
                    $relativa = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($absoluta) + 1));

                    // Se cuenta lo mismo que se copia, para que el tamano que
                    // sale en pantalla sea el del zip y no una cifra inflada.
                    if ($item->isFile() && !$this->esBasura($relativa)) {
                        $total += (int) $item->getSize();
                    }
                }
            } catch (\Throwable) {
                // Una carpeta ilegible no puede tumbar la pantalla entera.
            }
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $manifiesto
     */
    private function readme(array $manifiesto): string
    {
        $lineas = [
            'Copia de seguridad de extensiones del panel',
            'Creada el ' . ($manifiesto['creado'] ?? '?') . ' desde ' . ($manifiesto['panel'] ?? '?'),
            'Version del panel en ese momento: ' . ($manifiesto['version_panel'] ?: 'desconocida'),
            '',
            'Dentro de la carpeta "archivos" esta cada extension con la MISMA ruta que',
            'tenia en el panel. Para restaurar a mano basta con copiar el contenido de',
            '"archivos" encima de la carpeta del panel:',
            '',
            '    unzip esta-copia.zip -d /tmp/copia',
            '    cp -a /tmp/copia/archivos/. /var/www/pterodactyl/',
            '    chown -R www-data:www-data /var/www/pterodactyl',
            '',
            'Extensiones incluidas:',
        ];

        foreach ((array) ($manifiesto['extensiones'] ?? []) as $extension) {
            $lineas[] = '  - ' . ($extension['name'] ?? '?') . ' (' . ($extension['type'] ?? '?') . ')';

            foreach ((array) ($extension['paths'] ?? []) as $ruta) {
                $lineas[] = '      ' . $ruta;
            }
        }

        $proveedores = (array) ($manifiesto['proveedores'] ?? []);

        if ($proveedores !== []) {
            $lineas[] = '';
            $lineas[] = 'Lineas de proveedor que habia en config/app.php (actualizar el panel se las lleva';
            $lineas[] = 'por delante; si una extension no arranca despues de restaurar, es por esto):';

            foreach ($proveedores as $linea) {
                $lineas[] = '  ' . $linea;
            }
        }

        return implode("\n", $lineas) . "\n";
    }
}
