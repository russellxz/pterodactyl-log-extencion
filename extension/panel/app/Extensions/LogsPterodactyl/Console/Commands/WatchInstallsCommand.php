<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Revisa cada minuto si hay instalaciones colgadas y las corta.
 *
 * Se ejecuta desde el propio scheduler del panel (el cron que ya tienes
 * configurado para "php artisan schedule:run"), no hace falta anadir nada.
 */
class WatchInstallsCommand extends Command
{
    protected $signature = 'logspterodactyl:watch-installs
                            {--dry-run : Solo muestra lo que haria, sin tocar nada}
                            {--force : Ignora el interruptor de la configuracion}';

    protected $description = 'Detiene las instalaciones de servidores que se han quedado colgadas';

    public function handle(InstallGuard $guard, Settings $settings): int
    {
        $enabled = $settings->bool('watchdog_enabled') || $this->option('force');

        $minutes = $settings->int('watchdog_minutes', 1, 1440);
        $mode = (string) $settings->get('watchdog_action');
        $notify = $settings->bool('watchdog_notify_owner');
        $dry = (bool) $this->option('dry-run');

        // El registro se lleva SIEMPRE, este o no encendido el corte
        // automatico: son dos cosas distintas. Antes el interruptor apagaba
        // tambien el historial, asi que con el corte desactivado la extension
        // no se enteraba de ninguna instalacion ni de ninguna reinstalacion.
        //
        // Toda instalacion en curso queda registrada aunque luego termine
        // bien: asi se puede ver despues cuanto tardo cada una.
        foreach ($guard->installing() as $server) {
            $guard->track($server);
        }

        // Y se cierran las que ya terminaron. Esto es lo que mantiene el
        // historial cuadrado aunque el panel no dispare su evento.
        $closed = $guard->reconcile();

        if ($closed > 0) {
            $this->line(sprintf('%d instalacion(es) cerradas en el historial.', $closed));
        }

        // Repaso de los servidores que se pararon hace poco: si el nodo ha
        // avisado tarde de aquella instalacion y el panel los ha vuelto a
        // bloquear, se desbloquean otra vez.
        if (!$dry) {
            $desbloqueados = $guard->keepUnblocked();

            if ($desbloqueados > 0) {
                $this->line(sprintf('%d servidor(es) se habian vuelto a bloquear solos y se han desbloqueado.', $desbloqueados));
            }
        }

        // A partir de aqui empieza el corte automatico, que si depende del
        // interruptor. El registro de arriba ya esta hecho.
        if (!$enabled) {
            $this->line('Registro actualizado. El corte automatico esta desactivado en la configuracion.');

            return self::SUCCESS;
        }

        $stuck = $guard->stuck($minutes);

        if ($stuck->isEmpty()) {
            $this->line(sprintf('Sin instalaciones colgadas (limite: %d minutos).', $minutes));

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d instalacion(es) por encima de %d minutos.', $stuck->count(), $minutes));

        foreach ($stuck as $server) {
            $elapsed = $guard->minutesInstalling($server);

            if ($dry) {
                $this->line(sprintf('  [simulacion] "%s" lleva %d min, se detendria en modo %s.', $server->name, $elapsed, $mode));

                continue;
            }

            try {
                $result = $guard->stop($server, $mode, 'sistema', $notify);

                $this->line(sprintf(
                    '  "%s": detenida tras %d min%s',
                    $server->name,
                    $elapsed,
                    $result['port_changed']
                        ? sprintf(', puerto %s -> %s', $result['old_allocation'] ?? '?', $result['new_allocation'])
                        : ''
                ));

                foreach ($result['warnings'] as $warning) {
                    $this->warn('    aviso: ' . $warning);
                }
            } catch (\Throwable $e) {
                $this->error(sprintf('  "%s": no se pudo detener (%s)', $server->name, $e->getMessage()));

                ExtensionEvent::log('error', 'installs', 'Fallo al detener una instalacion colgada', [
                    'servidor' => $server->name,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ], $server->id);
            }
        }

        return self::SUCCESS;
    }
}
