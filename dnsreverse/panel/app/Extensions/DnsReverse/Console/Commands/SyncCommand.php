<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\ProxyManager;

/**
 * Vuelve a mandar a los nodos la configuracion de todos los DNS guardados.
 *
 * Es el comando que salva el dia cuando:
 *   - se reinstala un nodo desde cero
 *   - se reconstruye wings y se pierde /etc/nginx/sites-enabled
 *   - se acaba de instalar esta extension sobre una instalacion antigua y se
 *     quiere confirmar que todo lo que hay en la base de datos esta de verdad
 *     montado en los nodos
 *
 * La base de datos manda. No borra nada y reutiliza los certificados que
 * sigan siendo validos, asi que no gasta cupo de Let's Encrypt.
 */
class SyncCommand extends Command
{
    protected $signature = 'dnsreverse:sync
                            {--node= : Solo los DNS de este nodo (id)}
                            {--server= : Solo los DNS de este servidor (id)}
                            {--dry-run : Solo listar, sin tocar los nodos}';

    protected $description = 'Reenvia a los nodos la configuracion de los DNS guardados';

    public function handle(ProxyManager $proxies): int
    {
        $consulta = ProxyRecord::query()->with(['server', 'allocation.node']);

        if ($this->option('node')) {
            $nodo = (int) $this->option('node');
            $consulta->whereHas('allocation', fn ($sub) => $sub->where('node_id', $nodo));
        }

        if ($this->option('server')) {
            $consulta->where('server_id', (int) $this->option('server'));
        }

        $registros = $consulta->orderBy('id')->get();

        if ($registros->isEmpty()) {
            $this->info('No hay ningun DNS que sincronizar.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  Se van a resincronizar ' . $registros->count() . ' DNS.');

        if ($this->option('dry-run')) {
            $this->line('  (--dry-run: solo se listan, no se toca ningun nodo)');
        }

        $this->line('');

        $bien = 0;
        $mal = 0;

        foreach ($registros as $registro) {
            $nodo = optional(optional($registro->allocation)->node)->name ?? 'sin nodo';

            if ($this->option('dry-run')) {
                $this->line('  - ' . $registro->domain . '  ->  ' . $nodo);

                continue;
            }

            $resultado = $proxies->sync($registro);

            if ($resultado['ok']) {
                $bien++;
                $this->line('  <fg=green>[ok]</> ' . $registro->domain);
            } else {
                $mal++;
                $this->line('  <fg=red>[!!]</> ' . $registro->domain . ': ' . $resultado['message']);
            }
        }

        if ($this->option('dry-run')) {
            $this->line('');

            return self::SUCCESS;
        }

        DnsEvent::record($mal > 0 ? 'warning' : 'info', 'sync.command', 'Resincronizacion por consola', [
            'ok' => $bien,
            'errores' => $mal,
        ]);

        $this->line('');
        $this->info('  Correctos: ' . $bien . '. Con problemas: ' . $mal . '.');

        if ($mal > 0) {
            $this->line('  Los que fallaron suelen ser nodos apagados o sin el complemento de wings.');
            $this->line('  Revisa con: php artisan dnsreverse:doctor');
        }

        $this->line('');

        return $mal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
