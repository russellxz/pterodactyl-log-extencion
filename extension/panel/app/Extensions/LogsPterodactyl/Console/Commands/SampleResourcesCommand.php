<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\LogsPterodactyl\Services\ResourceMonitor;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Toma una muestra del consumo de todos los servidores encendidos.
 * El scheduler del panel lo llama cada minuto.
 */
class SampleResourcesCommand extends Command
{
    protected $signature = 'logspterodactyl:sample {--force : Ignora el interruptor de la configuracion}';

    protected $description = 'Guarda una muestra del consumo de recursos de cada servidor encendido';

    public function handle(ResourceMonitor $monitor, Settings $settings): int
    {
        if (!$settings->bool('resources_enabled') && !$this->option('force')) {
            $this->line('El monitor de recursos esta desactivado en la configuracion.');

            return self::SUCCESS;
        }

        $count = $monitor->sample();

        $this->line(sprintf('%d muestra(s) guardadas.', $count));

        return self::SUCCESS;
    }
}
