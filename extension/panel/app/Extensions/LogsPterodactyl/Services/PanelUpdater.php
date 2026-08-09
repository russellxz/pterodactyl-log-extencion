<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\UpdateRun;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Actualiza el panel a la ultima version conservando el tema Arix.
 *
 * Como funciona una actualizacion de Pterodactyl: se descomprime panel.tar.gz
 * encima de la carpeta del panel. Eso pisa los archivos del panel, pero no
 * borra los que no vienen en el paquete. Por eso todo lo que Arix ANADE
 * (la carpeta arix/, public/arix/, los controladores con licencia de
 * app/Http/Controllers/Admin/Arix/, config/arix.php, los idiomas...) sobrevive,
 * y lo unico que se pierde son los archivos del panel que Arix REEMPLAZA
 * (routes/admin.php, resources/views/layouts/admin.blade.php, los modelos,
 * resources/scripts/... y los assets ya compilados de public/assets).
 *
 * Restaurar el tema es entonces: volver a copiar arix/<version>/ encima y
 * recompilar los assets. Eso es exactamente lo que hace su instalador, y es
 * lo que hace este servicio, sin necesidad de volver a bajar el tema.
 */
class PanelUpdater
{
    /**
     * Paquetes de npm que anade el tema Arix. Se vuelven a instalar tras la
     * actualizacion porque el paquete del panel reescribe package.json.
     */
    private const ARIX_PACKAGES = 'react-email-editor react-colorful recharts@^2.15.4 ua-parser-js cronstrue '
        . 'react-day-picker jszip react-turnstile @dnd-kit/core @dnd-kit/sortable @dnd-kit/utilities '
        . '@types/md5 md5 react-icons@5.4.0 markdown-to-jsx@7.7.10 i18next-browser-languagedetector@7.2.1';

    public function __construct(private Settings $settings)
    {
    }

    public function currentVersion(): string
    {
        return (string) config('app.version', 'desconocida');
    }

    /**
     * Ultima version publicada de Pterodactyl.
     *
     * @return array{version: ?string, url: ?string, published_at: ?string, notes: ?string, error: ?string}
     */
    public function latestVersion(bool $fresh = false): array
    {
        $key = 'logspterodactyl:latest-panel-version';

        if (!$fresh) {
            try {
                $cached = Cache::get($key);

                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Throwable) {
                // Cache no disponible (permisos de storage/framework/cache).
                // Se consulta igualmente, solo que sin cachear.
            }
        }

        $result = $this->fetchLatest();

        try {
            // Un fallo de red se cachea poco tiempo para poder reintentar pronto.
            Cache::put($key, $result, $result['error'] === null ? 3600 : 120);
        } catch (\Throwable) {
            // Sin cache tambien se puede vivir.
        }

        return $result;
    }

    /**
     * @return array{version: ?string, url: ?string, published_at: ?string, notes: ?string, error: ?string}
     */
    private function fetchLatest(): array
    {
        $empty = ['version' => null, 'url' => null, 'published_at' => null, 'notes' => null, 'error' => null];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'LogsPterodactyl'])
                ->get('https://api.github.com/repos/pterodactyl/panel/releases/latest');

            if ($response->successful()) {
                $tag = ltrim((string) $response->json('tag_name'), 'v');

                if ($tag !== '') {
                    return array_merge($empty, [
                        'version' => $tag,
                        'url' => 'https://github.com/pterodactyl/panel/releases/download/v' . $tag . '/panel.tar.gz',
                        'published_at' => (string) $response->json('published_at'),
                        'notes' => mb_substr((string) $response->json('body'), 0, 4000),
                    ]);
                }
            }
        } catch (\Throwable) {
            // Se prueba la otra fuente.
        }

        try {
            $response = Http::timeout(10)->get('https://cdn.pterodactyl.io/releases/latest.json');

            if ($response->successful()) {
                $tag = ltrim((string) $response->json('panel'), 'v');

                if ($tag !== '') {
                    return array_merge($empty, [
                        'version' => $tag,
                        'url' => 'https://github.com/pterodactyl/panel/releases/download/v' . $tag . '/panel.tar.gz',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            return array_merge($empty, ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return array_merge($empty, ['error' => 'No se pudo consultar la ultima version publicada.']);
    }

    public function updateAvailable(): bool
    {
        $latest = $this->latestVersion()['version'];
        $current = $this->currentVersion();

        if (!$latest || !preg_match('/^\d+\.\d+/', $current)) {
            return false;
        }

        return version_compare($latest, $current, '>');
    }

    /**
     * Que sabemos del tema Arix instalado.
     *
     * @return array{
     *     installed: bool,
     *     version: ?string,
     *     staged: array<int, string>,
     *     staged_path: ?string,
     *     target_panel: ?string
     * }
     */
    public function themeInfo(): array
    {
        $installed = is_dir(base_path('arix'))
            || file_exists(base_path('config/arix.php'))
            || file_exists(base_path('config/arixTheme.php'));

        $staged = [];

        foreach ((array) glob(base_path('arix/*'), GLOB_ONLYDIR) as $dir) {
            $staged[] = basename($dir);
        }

        usort($staged, 'version_compare');
        $latestStaged = $staged === [] ? null : end($staged);
        $stagedPath = $latestStaged ? base_path('arix/' . $latestStaged) : null;

        return [
            'installed' => $installed,
            'version' => (string) config('app.arix', '') ?: null,
            'staged' => $staged,
            'staged_path' => $stagedPath,
            'target_panel' => $stagedPath ? $this->themeTargetPanel($stagedPath) : null,
        ];
    }

    /**
     * Version del panel para la que se compilo el tema.
     *
     * El tema trae su propio config/app.php con la version escrita a mano.
     * Saberla permite avisar antes de actualizar a un panel mas nuevo de lo
     * que el tema soporta, que es cuando aparecen los problemas de verdad:
     * Arix reemplaza modelos y controladores del panel, y los suyos estan
     * hechos contra una version concreta.
     */
    private function themeTargetPanel(string $stagedPath): ?string
    {
        $config = $stagedPath . '/config/app.php';

        if (!is_file($config)) {
            return null;
        }

        $contents = @file_get_contents($config);

        if (!is_string($contents)) {
            return null;
        }

        if (preg_match("/['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $contents, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * True si la version de destino es mas nueva que la que el tema soporta.
     */
    public function themeLagsBehind(string $targetVersion): bool
    {
        $target = $this->themeInfo()['target_panel'];

        if (!$target || !preg_match('/^\d+\.\d+/', $target) || !preg_match('/^\d+\.\d+/', $targetVersion)) {
            return false;
        }

        return version_compare($targetVersion, $target, '>');
    }

    /**
     * Comprobaciones antes de dejar que alguien pulse el boton.
     *
     * @return array<int, array{ok: bool, level: string, label: string, detail: string}>
     */
    public function preflight(): array
    {
        $checks = [];
        $theme = $this->themeInfo();

        $checks[] = $this->check(
            $this->canExec(),
            'critico',
            'Ejecucion de comandos',
            $this->canExec()
                ? 'PHP puede lanzar procesos del sistema.'
                : 'exec() y proc_open() estan deshabilitados en php.ini. La actualizacion tendra que lanzarse por SSH.'
        );

        foreach (['tar', 'curl', 'php'] as $binary) {
            $found = $this->which($binary);
            $checks[] = $this->check((bool) $found, 'critico', 'Comando ' . $binary, $found ?: 'No se encontro en el PATH.');
        }

        foreach (['composer', 'mysqldump'] as $binary) {
            $found = $this->which($binary);
            $checks[] = $this->check((bool) $found, 'importante', 'Comando ' . $binary, $found ?: 'No se encontro en el PATH.');
        }

        if ($theme['installed']) {
            foreach (['yarn', 'node'] as $binary) {
                $found = $this->which($binary);
                $checks[] = $this->check(
                    (bool) $found,
                    'importante',
                    'Comando ' . $binary,
                    $found ?: 'Hace falta para recompilar los assets del tema Arix tras actualizar.'
                );
            }

            $checks[] = $this->check(
                $theme['staged_path'] !== null,
                'importante',
                'Copia del tema Arix',
                $theme['staged_path']
                    ? 'Se restaurara desde ' . $theme['staged_path']
                    : 'No existe la carpeta arix/<version> del tema. Sin ella habra que reinstalar Arix a mano despues de actualizar.'
            );
        }

        $backupPath = (string) $this->settings->get('backup_path');
        $parent = is_dir($backupPath) ? $backupPath : dirname($backupPath);
        $writable = is_dir($parent) ? is_writable($parent) : false;

        $checks[] = $this->check(
            $writable,
            'critico',
            'Carpeta de respaldos',
            $writable ? $backupPath . ' se puede escribir.' : 'No se puede escribir en ' . $parent . '.'
        );

        $free = @disk_free_space(base_path());
        $needed = $this->panelSize() * 2;
        $checks[] = $this->check(
            $free !== false && $free > $needed,
            'critico',
            'Espacio en disco',
            $free === false
                ? 'No se pudo comprobar el espacio libre.'
                : sprintf('%s libres, hacen falta unos %s para el respaldo.', $this->human((int) $free), $this->human((int) $needed))
        );

        $checks[] = $this->check(
            is_writable(base_path()),
            'critico',
            'Permisos del panel',
            is_writable(base_path())
                ? 'El usuario ' . $this->currentUser() . ' puede escribir en ' . base_path()
                : 'El usuario ' . $this->currentUser() . ' no puede escribir en ' . base_path() . '. Lanza la actualizacion por SSH como root.'
        );

        return $checks;
    }

    /**
     * @return array{ok: bool, level: string, label: string, detail: string}
     */
    private function check(bool $ok, string $level, string $label, string $detail): array
    {
        return ['ok' => $ok, 'level' => $level, 'label' => $label, 'detail' => $detail];
    }

    public function preflightBlocking(): array
    {
        return array_values(array_filter(
            $this->preflight(),
            fn ($c) => !$c['ok'] && $c['level'] === 'critico'
        ));
    }

    /**
     * Prepara una actualizacion: crea la fila, escribe el guion y devuelve
     * todo lo necesario para lanzarlo.
     */
    public function prepare(?string $targetVersion = null, ?int $userId = null, bool $restoreTheme = true): UpdateRun
    {
        $latest = $this->latestVersion();
        $version = $targetVersion ?: $latest['version'];

        if (!$version || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new \RuntimeException('No se pudo determinar a que version actualizar.');
        }

        // Ultima red de seguridad: aunque la configuracion se valide al
        // guardarla, aqui se vuelve a comprobar antes de escribir nada. El
        // respaldo contiene la base de datos entera y la contrasena de MySQL.
        $backupRoot = rtrim((string) $this->settings->get('backup_path'), '/');
        $panel = rtrim(base_path(), '/');

        if ($backupRoot === '' || !str_starts_with($backupRoot, '/') || str_starts_with($backupRoot . '/', $panel . '/')) {
            throw new \RuntimeException(
                'La carpeta de respaldos (' . ($backupRoot ?: 'vacia') . ') tiene que ser una ruta absoluta '
                . 'fuera de la carpeta del panel. Cambiala en la configuracion de LogsPterodactyl.'
            );
        }

        $theme = $this->themeInfo();
        $token = bin2hex(random_bytes(16));

        // Copia de las OTRAS extensiones antes de tocar nada. El respaldo
        // completo del panel ya las lleva dentro, pero enterradas en un tar de
        // varios cientos de megas; esto deja un zip pequeno, con manifiesto,
        // que se puede descargar y restaurar con un boton desde la pantalla de
        // extensiones. Si falla no se aborta la actualizacion: se avisa.
        $extensiones = null;

        try {
            $vault = app(ExtensionVault::class);

            if ($vault->detect() !== []) {
                $extensiones = $vault->backup();
            }
        } catch (\Throwable $e) {
            ExtensionEvent::log('warning', 'extensiones', 'No se pudo respaldar las otras extensiones antes de actualizar', [
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        if ($extensiones !== null) {
            ExtensionEvent::log('info', 'extensiones', 'Copia de las otras extensiones hecha antes de actualizar el panel', [
                'archivo' => $extensiones['file'],
                'incluidas' => $extensiones['incluidas'],
            ]);
        }

        $run = UpdateRun::create([
            'token' => $token,
            'from_version' => $this->currentVersion(),
            'to_version' => $version,
            'theme_version' => $theme['installed'] ? ($theme['version'] ?: basename((string) $theme['staged_path'])) : null,
            'status' => UpdateRun::STATUS_RUNNING,
            'backup_path' => $this->backupDir($token),
            'log_path' => $this->runDir($token) . '/update.log',
            'started_by' => $userId,
            'started_at' => now(),
        ]);

        $this->writeScript($run, $version, $restoreTheme && $theme['installed'] ? (string) $theme['staged_path'] : null);

        return $run;
    }

    /**
     * Lanza el guion en segundo plano y devuelve inmediatamente.
     */
    public function launch(UpdateRun $run): void
    {
        $script = $this->runDir($run->token) . '/update.sh';
        $log = $run->log_path;

        if (!$this->canExec()) {
            throw new \RuntimeException(
                'PHP no puede lanzar procesos en este servidor. Ejecuta por SSH: sudo bash ' . $script
            );
        }

        ExtensionEvent::log('warning', 'updater', sprintf(
            'Actualizacion del panel lanzada: %s -> %s',
            (string) $run->from_version,
            (string) $run->to_version
        ), ['token' => $run->token]);

        $command = sprintf('nohup bash %s >> %s 2>&1 & echo $!', escapeshellarg($script), escapeshellarg($log));

        @exec($command);
    }

    /**
     * Estado de una actualizacion, leido del archivo que va escribiendo el
     * guion. Se lee del archivo y no de la base de datos porque durante la
     * actualizacion el panel esta en modo mantenimiento.
     *
     * @return array<string, mixed>
     */
    public function status(UpdateRun $run): array
    {
        $file = $this->runDir($run->token) . '/status.json';
        $status = ['state' => $run->status, 'step' => null, 'steps' => [], 'finished' => false];

        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);

            if (is_array($decoded)) {
                $status = array_merge($status, $decoded);
            }
        }

        $status['log'] = $this->tailLog($run);

        return $status;
    }

    public function tailLog(UpdateRun $run, int $bytes = 200000): string
    {
        $path = (string) $run->log_path;

        if (!is_file($path)) {
            return '';
        }

        $size = (int) filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        if ($size > $bytes) {
            fseek($handle, $size - $bytes);
        }

        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function runDir(string $token): string
    {
        return public_path('extensions/logspterodactyl/runs/' . $token);
    }

    public function backupDir(string $token): string
    {
        return rtrim((string) $this->settings->get('backup_path'), '/') . '/' . $token;
    }

    /**
     * Escribe el guion de actualizacion y el archivo de credenciales de MySQL.
     */
    private function writeScript(UpdateRun $run, string $version, ?string $themePath): void
    {
        $runDir = $this->runDir($run->token);
        $backupDir = $this->backupDir($run->token);

        @mkdir($runDir, 0755, true);
        @mkdir($backupDir, 0700, true);

        $credentials = $this->writeMysqlCredentials($backupDir);

        $script = view('logspterodactyl::scripts.update', [
            'panel' => base_path(),
            'runDir' => $runDir,
            'backupDir' => $backupDir,
            'version' => $version,
            'url' => 'https://github.com/pterodactyl/panel/releases/download/v' . $version . '/panel.tar.gz',
            'themePath' => $themePath,
            'arixPackages' => self::ARIX_PACKAGES,
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'credentials' => $credentials,
            'registerTool' => base_path('app/Extensions/LogsPterodactyl/tools/register-provider.php'),
            'versionTool' => base_path('app/Extensions/LogsPterodactyl/tools/sync-version.php'),
            'webUser' => $this->webUser(),
        ])->render();

        file_put_contents($runDir . '/update.sh', $script);
        @chmod($runDir . '/update.sh', 0750);
    }

    /**
     * Archivo con las credenciales de la base de datos en formato de MySQL.
     *
     * Se hace asi, y no pasando la contrasena por la linea de comandos, para
     * que no aparezca en la lista de procesos del servidor.
     */
    private function writeMysqlCredentials(string $backupDir): string
    {
        $connection = config('database.default');
        $config = config('database.connections.' . $connection, []);
        $path = $backupDir . '/.my.cnf';

        $contents = "[client]\n"
            . 'host=' . ($config['host'] ?? '127.0.0.1') . "\n"
            . 'port=' . ($config['port'] ?? 3306) . "\n"
            . 'user=' . ($config['username'] ?? '') . "\n"
            . 'password="' . str_replace(['\\', '"'], ['\\\\', '\"'], (string) ($config['password'] ?? '')) . "\"\n";

        file_put_contents($path, $contents);
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Marca el resultado leyendo el archivo de estado. Lo llama el panel al
     * volver a estar disponible.
     */
    public function finalize(UpdateRun $run): UpdateRun
    {
        $status = $this->status($run);
        $state = (string) ($status['state'] ?? 'running');

        if (in_array($state, ['success', 'failed'], true) && $run->status === UpdateRun::STATUS_RUNNING) {
            $run->fill([
                'status' => $state === 'success' ? UpdateRun::STATUS_SUCCESS : UpdateRun::STATUS_FAILED,
                'finished_at' => now(),
                'error' => $state === 'failed' ? mb_substr((string) ($status['error'] ?? ''), 0, 1000) : null,
            ])->save();

            ExtensionEvent::log(
                $state === 'success' ? 'info' : 'error',
                'updater',
                $state === 'success'
                    ? 'Panel actualizado a la version ' . $run->to_version
                    : 'La actualizacion del panel fallo',
                ['token' => $run->token, 'error' => $status['error'] ?? null]
            );
        }

        return $run->refresh();
    }

    /**
     * Prepara el guion que deshace la actualizacion.
     */
    public function prepareRollback(UpdateRun $run): string
    {
        $runDir = $this->runDir($run->token);
        $backupDir = (string) $run->backup_path;

        if (!is_dir($backupDir)) {
            throw new \RuntimeException('El respaldo de esa actualizacion ya no existe en ' . $backupDir);
        }

        $script = view('logspterodactyl::scripts.rollback', [
            'panel' => base_path(),
            'parent' => dirname(base_path()),
            'folder' => basename(base_path()),
            'runDir' => $runDir,
            'backupDir' => $backupDir,
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'credentials' => $backupDir . '/.my.cnf',
            'registerTool' => base_path('app/Extensions/LogsPterodactyl/tools/register-provider.php'),
            'webUser' => $this->webUser(),
        ])->render();

        $path = $runDir . '/rollback.sh';
        file_put_contents($path, $script);
        @chmod($path, 0750);

        return $path;
    }

    public function launchRollback(UpdateRun $run): void
    {
        $script = $this->prepareRollback($run);
        $log = $this->runDir($run->token) . '/rollback.log';

        if (!$this->canExec()) {
            throw new \RuntimeException('PHP no puede lanzar procesos. Ejecuta por SSH: sudo bash ' . $script);
        }

        ExtensionEvent::log('warning', 'updater', 'Se lanzo la vuelta atras del panel', ['token' => $run->token]);

        @exec(sprintf('nohup bash %s >> %s 2>&1 & echo $!', escapeshellarg($script), escapeshellarg($log)));
    }

    /**
     * Borra respaldos antiguos dejando los ultimos N.
     */
    public function pruneBackups(): int
    {
        $keep = $this->settings->int('update_keep_backups', 1, 20);

        $runs = UpdateRun::query()
            ->orderByDesc('started_at')
            ->get();

        $removed = 0;

        foreach ($runs->slice($keep) as $run) {
            $dir = (string) $run->backup_path;

            if ($dir !== '' && is_dir($dir) && str_starts_with($dir, (string) $this->settings->get('backup_path'))) {
                $this->removeDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    public function canExec(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && !in_array('exec', $disabled, true);
    }

    private function which(string $binary): ?string
    {
        if (!$this->canExec()) {
            return null;
        }

        $output = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        $path = is_string($output) ? trim($output) : '';

        return $path !== '' ? $path : null;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = @posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return (string) (getenv('USER') ?: 'desconocido');
    }

    /**
     * Usuario del servidor web, para devolver los permisos al terminar.
     */
    private function webUser(): string
    {
        foreach (['www-data', 'nginx', 'apache'] as $candidate) {
            if (function_exists('posix_getpwnam') && @posix_getpwnam($candidate)) {
                return $candidate;
            }
        }

        return 'www-data';
    }

    private function panelSize(): int
    {
        // Estimacion barata: el panel sin node_modules ronda los 300 MB.
        return 350 * 1024 * 1024;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1) . ' ' . $units[$index];
    }
}
