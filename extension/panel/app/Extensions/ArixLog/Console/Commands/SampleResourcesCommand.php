<?php

namespace Pterodactyl\Extensions\ArixLog\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\ArixLog\Services\ResourceMonitor;
use Pterodactyl\Extensions\ArixLog\Support\Settings;

/**
 * Toma una muestra del consumo de todos los servidores encendidos.
 * El scheduler del panel lo llama cada minuto.
 */
class SampleResourcesCommand extends Command
{
    protected $signature = 'arixlog:sample {--force : Ignora el interruptor de la configuracion}';

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
