<?php

namespace Pterodactyl\Extensions\DnsReverse;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Extensions\DnsReverse\Http\Middleware\InjectAssets;
use Pterodactyl\Extensions\DnsReverse\Listeners\ServerCreatedListener;
use Pterodactyl\Extensions\DnsReverse\Services\ProxyManager;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;

/**
 * Punto de entrada de DNS Reverse.
 *
 * Igual que la otra extension de este repositorio, aqui no se sobrescribe ni
 * un solo archivo del panel:
 *
 *  - Todo el codigo vive en app/Extensions/DnsReverse y los recursos en
 *    public/extensions/dnsreverse.
 *  - No se recompila el frontend de React (nada de yarn ni webpack). La
 *    version anterior de esta extension SI lo hacia, y por eso desaparecia
 *    cada vez que se actualizaba el panel o el tema.
 *  - Rutas, vistas, middleware y tareas programadas se enganchan en caliente
 *    desde aqui.
 *  - Cualquier fallo interno se traga: si la extension se rompe, el panel
 *    tiene que seguir funcionando.
 */
class DnsReverseServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    public function register(): void
    {
        $this->app->singleton(Settings::class, fn () => new Settings());
        $this->app->bind(ProxyManager::class, fn ($app) => new ProxyManager($app->make(Settings::class)));
    }

    public function boot(): void
    {
        try {
            $this->bootExtension();
        } catch (\Throwable $e) {
            $this->reportSilently($e);
        }
    }

    private function bootExtension(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'dnsreverse');
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        $this->registerBladeDirectives();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerListeners();
        $this->registerSchedule();
    }

    /**
     * @dnsicon('nombre', 16) dibuja un icono SVG en linea, sin depender de
     * Font Awesome ni de Lucide (cada tema trae una libreria distinta).
     */
    private function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::directive('dnsicon', function ($expression) {
            return "<?php echo \\Pterodactyl\\Extensions\\DnsReverse\\Support\\Icons::svg({$expression}); ?>";
        });
    }

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
            ->prefix('admin/dnsreverse')
            ->name('admin.dnsreverse.')
            ->group(__DIR__ . '/routes/admin.php');

        // Las rutas del cliente cuelgan de /api porque routes/base.php del
        // panel tiene un comodin que se queda con todo lo que no empiece por
        // api, auth, admin o daemon y se lo entrega a React.
        Route::middleware(['web', 'auth'])
            ->prefix('api/dnsreverse')
            ->name('dnsreverse.client.')
            ->group(__DIR__ . '/routes/client.php');
    }

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
            Console\Commands\SyncCommand::class,
            Console\Commands\RenewCertificatesCommand::class,
        ]);
    }

    /**
     * Cada servidor nuevo nace pudiendo crear su DNS.
     *
     * La version anterior los dejaba a 0, asi que el administrador tenia que
     * entrar servidor por servidor a subir el limite. Aqui se usa el valor de
     * la configuracion (por defecto 1) en cuanto el servidor se crea.
     */
    private function registerListeners(): void
    {
        try {
            \Pterodactyl\Models\Server::created([ServerCreatedListener::class, 'handle']);
        } catch (\Throwable $e) {
            $this->reportSilently($e);
        }
    }

    private function registerSchedule(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function () {
            try {
                /** @var Schedule $schedule */
                $schedule = $this->app->make(Schedule::class);

                // Los certificados de Let's Encrypt duran 90 dias. Se revisan
                // todas las madrugadas y se renuevan los que caducan pronto.
                $schedule->command('dnsreverse:renew')
                    ->dailyAt('03:25')
                    ->withoutOverlapping()
                    ->runInBackground();
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
            logger()->warning('[DnsReverse] fallo al arrancar la extension: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable) {
            // Ni el logger esta disponible: no hay nada que hacer.
        }
    }
}
