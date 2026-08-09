<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\DnsReverse\DnsReverseServiceProvider;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;

/**
 * Deja la extension lista: migraciones y valores por defecto.
 *
 * El registro del proveedor en config/app.php lo hace install.sh (o el script
 * suelto tools/register-provider.php), porque ese paso tiene que ocurrir antes
 * de que artisan sepa siquiera que esta extension existe.
 *
 * Es seguro ejecutarlo las veces que haga falta: no borra nada y no pisa la
 * configuracion que ya estuviera guardada.
 */
class InstallCommand extends Command
{
    /**
     * Tablas y columnas que tienen que existir. Sirve para detectar una base
     * de datos a medias (migraciones marcadas como aplicadas pero sin efecto).
     */
    private const ESPERADO = [
        'dnsreverse_settings' => [],
        'dnsreverse_domains' => ['cf_token', 'ssl_cert', 'proxied_mode'],
        'dnsreverse_events' => [],
        'server_proxy' => ['proxy_type', 'base_domain', 'cf_record_id', 'ssl_mode', 'domain_id'],
    ];

    protected $signature = 'dnsreverse:install
                            {--limit= : Cuantos DNS puede crear un servidor nuevo}
                            {--unlock-all : Sube a todos los servidores que estan a 0}';

    protected $description = 'Prepara la base de datos y la configuracion de DNS Reverse';

    public function handle(Settings $settings): int
    {
        $this->line('');
        $this->line('  DNS Reverse v' . DnsReverseServiceProvider::VERSION);
        $this->line('  ------------------------------------------');

        $this->info('Aplicando migraciones...');
        Artisan::call('migrate', ['--force' => true], $this->getOutput());

        if (!$this->esquemaCompleto()) {
            $this->warn('La base de datos no esta completa aunque las migraciones figuraban aplicadas. Reintentando...');

            try {
                DB::table('migrations')->whereIn('migration', $this->nombresDeMigraciones())->delete();
            } catch (\Throwable $e) {
                $this->error('No se pudieron desmarcar las migraciones: ' . $e->getMessage());
            }

            Artisan::call('migrate', ['--force' => true], $this->getOutput());
        }

        if (!Schema::hasTable(Settings::TABLE)) {
            $this->error('Las tablas no se crearon. Revisa la conexion a la base de datos.');

            return self::FAILURE;
        }

        if (!$this->esquemaCompleto()) {
            $this->error('Faltan tablas o columnas. Ejecuta "php artisan migrate --force" y mira el error que salga.');

            return self::FAILURE;
        }

        // Solo se escriben las claves que aun no existen, para no pisar la
        // configuracion en una reinstalacion o en una actualizacion.
        $guardado = $settings->stored();
        $escritas = 0;

        foreach (Settings::DEFAULTS as $clave => $valor) {
            if (!array_key_exists($clave, $guardado) || $guardado[$clave] === null) {
                $settings->set($clave, $valor);
                $escritas++;
            }
        }

        if ($escritas > 0) {
            $this->info($escritas . ' valor(es) de configuracion inicializados.');
        }

        if ($this->option('limit') !== null) {
            $limite = max(0, min(100, (int) $this->option('limit')));
            $settings->set('default_proxy_limit', $limite);
            $this->info('Los servidores nuevos podran crear ' . $limite . ' DNS.');
        }

        if ($this->option('unlock-all') && Schema::hasColumn('servers', 'proxy_limit')) {
            $limite = $settings->int('default_proxy_limit', 0, 100);
            $afectados = DB::table('servers')->where('proxy_limit', 0)->update(['proxy_limit' => $limite]);
            $this->info($afectados . ' servidor(es) que estaban bloqueados ahora pueden crear ' . $limite . ' DNS.');
        }

        // --- Resumen de lo que ya habia ------------------------------------
        $existentes = ProxyRecord::count();
        $dominios = DnsDomain::count();

        // Los DNS que vienen de la version anterior no guardaban a que dominio
        // pertenecen. Se enganchan ahora para que se cuenten bien.
        $enganchados = DnsDomain::vincularProxysSueltos();

        $this->line('');

        if ($existentes > 0) {
            $this->info('  Se han encontrado ' . $existentes . ' DNS ya creados. Siguen intactos y ya aparecen en el panel.');
        }

        if ($enganchados > 0) {
            $this->info('  ' . $enganchados . ' de ellos se han agrupado bajo su dominio.');
        }

        if ($dominios > 0) {
            $this->info('  Dominios dados de alta: ' . $dominios . '.');
        }

        DnsEvent::record('info', 'setup', 'Extension instalada o actualizada', [
            'version' => DnsReverseServiceProvider::VERSION,
            'dns_existentes' => $existentes,
        ]);

        $this->line('');
        $this->info('  Listo. Entra en el panel: /admin/dnsreverse');
        $this->line('');
        $this->line('  Siguientes pasos:');
        $this->line('   1. Dominios -> anade tu dominio con su token de Cloudflare.');
        $this->line('   2. Nodos -> instala el complemento de wings en cada nodo.');
        $this->line('   3. Comprueba que el cron del panel esta corriendo (renueva los certificados):');
        $this->line('      * * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1');
        $this->line('');

        return self::SUCCESS;
    }

    private function esquemaCompleto(): bool
    {
        foreach (self::ESPERADO as $tabla => $columnas) {
            if (!Schema::hasTable($tabla)) {
                return false;
            }

            foreach ($columnas as $columna) {
                if (!Schema::hasColumn($tabla, $columna)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function nombresDeMigraciones(): array
    {
        $nombres = [];

        foreach ((array) glob(__DIR__ . '/../../database/migrations/*.php') as $archivo) {
            $nombres[] = basename($archivo, '.php');
        }

        return $nombres;
    }
}
