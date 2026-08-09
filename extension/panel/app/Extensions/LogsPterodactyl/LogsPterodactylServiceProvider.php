<?php

namespace Pterodactyl\Extensions\LogsPterodactyl;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\LogsPterodactyl\Http\Middleware\InjectAssets;
use Pterodactyl\Extensions\LogsPterodactyl\Listeners\MailLogListener;
use Pterodactyl\Extensions\LogsPterodactyl\Listeners\ServerInstallListener;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Punto de entrada de la extension LogsPterodactyl.
 *
 * Reglas de convivencia con el panel y con el tema Arix:
 *
 *  - No se sobrescribe NINGUN archivo del panel ni del tema. Todo vive dentro
 *    de app/Extensions/LogsPterodactyl y de public/extensions/logspterodactyl.
 *  - No se recompila el frontend de React (nada de yarn/webpack), que es la
 *    causa habitual de que un tema se quede en blanco tras instalar addons.
 *  - Todo lo que se engancha al panel (rutas, vistas, middleware, tareas
 *    programadas) se registra en caliente desde este proveedor.
 *  - Cualquier fallo dentro de la extension se traga aqui: el panel debe
 *    seguir funcionando aunque la extension este rota.
 */
class LogsPterodactylServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    /**
     * Rutas del panel que jamas deben pasar por la inyeccion de HTML.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class, fn () => new Settings());
    }

    public function boot(): void
    {
        // El arranque completo va dentro de un try/catch. Si la extension
        // falla (tablas sin migrar, permisos, etc.) el panel sigue vivo.
        try {
            $this->bootExtension();
        } catch (\Throwable $e) {
            $this->reportSilently($e);
        }
    }

    private function bootExtension(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'logspterodactyl');
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        $this->registerBladeDirectives();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerListeners();
        $this->registerSchedule();
    }

    /**
     * @logsicon('nombre', 16) dibuja un icono SVG en linea.
     *
     * Se registra como directiva y no se usa @use ni imports dentro de las
     * vistas para no depender de la version de Blade: el panel va con
     * Laravel 10, 11 o 12 segun la version de Pterodactyl instalada.
     */
    private function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::directive('logsicon', function ($expression) {
            return "<?php echo \\Pterodactyl\\Extensions\\LogsPterodactyl\\Support\\Icons::svg({$expression}); ?>";
        });
    }

    /**
     * Las rutas se registran aparte de routes/admin.php y routes/base.php a
     * proposito: el tema Arix reemplaza esos dos archivos al instalarse o
     * actualizarse, y si escribieramos ahi dentro perderiamos las rutas (o
     * peor, romperiamos el archivo del tema).
     */
    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $admin = array_values(array_filter([
            'web',
            'auth',
            $this->classIfExists(\Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication::class),
            $this->classIfExists(\Pterodactyl\Http\Middleware\AdminAuthenticate::class),
            Http\Middleware\EnsureRootAdmin::class,
        ]));

        Route::middleware($admin)
            ->prefix('admin/logspterodactyl')
            ->name('admin.logspterodactyl.')
            ->group(__DIR__ . '/routes/admin.php');

        // Las rutas del cliente cuelgan de /api para no chocar con la ruta
        // comodin de React de routes/base.php, que se queda con todo lo que
        // no empiece por api|auth|admin|daemon.
        Route::middleware(['web', 'auth'])
            ->prefix('api/logspterodactyl')
            ->name('logspterodactyl.client.')
            ->group(__DIR__ . '/routes/client.php');
    }

    /**
     * El menu del admin y el aviso de instalacion del cliente se inyectan en
     * la respuesta HTML ya renderizada. Asi no hay que tocar ni el layout del
     * admin ni las plantillas blade del tema.
     */
    private function registerMiddleware(): void
    {
        if (!$this->app->runningInConsole()) {
            $this->app->make(HttpKernel::class)->pushMiddleware(InjectAssets::class);
        }
    }

    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\Commands\InstallCommand::class,
            Console\Commands\UninstallCommand::class,
            Console\Commands\DoctorCommand::class,
            Console\Commands\WatchInstallsCommand::class,
            Console\Commands\SampleResourcesCommand::class,
            Console\Commands\PruneCommand::class,
            Console\Commands\PanelUpdateCommand::class,
            Console\Commands\PanelRollbackCommand::class,
        ]);
    }

    private function registerListeners(): void
    {
        // Correos: Laravel 9/10/11/12 emiten estos tres eventos.
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, [MailLogListener::class, 'sending']);
        Event::listen(\Illuminate\Mail\Events\MessageSent::class, [MailLogListener::class, 'sent']);

        if (class_exists(\Illuminate\Mail\Events\MessageFailed::class)) {
            Event::listen(\Illuminate\Mail\Events\MessageFailed::class, [MailLogListener::class, 'failed']);
        }

        // Instalaciones: el panel dispara este evento cuando wings avisa de
        // que termino de instalar (con exito o sin el).
        if (class_exists(\Pterodactyl\Events\Server\Installed::class)) {
            Event::listen(\Pterodactyl\Events\Server\Installed::class, [ServerInstallListener::class, 'handle']);
        }
    }

    /**
     * Se engancha al scheduler del panel sin tocar app/Console/Kernel.php,
     * que es otro de los archivos que el tema Arix reemplaza.
     */
    private function registerSchedule(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function () {
            try {
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);

                $schedule->command('logspterodactyl:watch-installs')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->runInBackground();

                $schedule->command('logspterodactyl:sample')
                    ->everyMinute()
                    ->withoutOverlapping(5)
                    ->runInBackground();

                $schedule->command('logspterodactyl:prune')
                    ->dailyAt('04:10')
                    ->withoutOverlapping();
            } catch (\Throwable $e) {
                $this->reportSilently($e);
            }
        });
    }

    /**
     * @param class-string $class
     */
    private function classIfExists(string $class): ?string
    {
        return class_exists($class) ? $class : null;
    }

    private function reportSilently(\Throwable $e): void
    {
        try {
            logger()->warning('[LogsPterodactyl] fallo al arrancar la extension: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable) {
            // Ni siquiera el logger esta disponible: no hay nada que hacer.
        }
    }
}
