<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\InstallEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\MailLog;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ResourceSample;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Limpieza diaria. Sin esto la tabla de muestras de recursos crece sin freno
 * (un servidor encendido son 1.440 filas al dia).
 */
class PruneCommand extends Command
{
    protected $signature = 'logspterodactyl:prune';

    protected $description = 'Borra los registros antiguos de la extension segun la retencion configurada';

    public function handle(Settings $settings): int
    {
        $samples = ResourceSample::query()
            ->where('sampled_at', '<', now()->subDays($settings->int('resources_retention_days', 1, 3650)))
            ->delete();

        $events = ExtensionEvent::query()
            ->where('created_at', '<', now()->subDays($settings->int('events_retention_days', 1, 3650)))
            ->delete();

        $mails = MailLog::query()
            ->where('created_at', '<', now()->subDays($settings->int('mail_log_retention_days', 1, 3650)))
            ->delete();

        $installs = InstallEvent::query()
            ->where('status', '!=', InstallEvent::STATUS_INSTALLING)
            ->where('created_at', '<', now()->subDays($settings->int('installs_retention_days', 1, 3650)))
            ->delete();

        // Un correo que se quedo en "queued" y nunca confirmo su envio es un
        // envio fallido: el transporte lanzo una excepcion y Laravel no emite
        // ningun evento en ese caso.
        $stuck = MailLog::query()
            ->where('status', MailLog::STATUS_QUEUED)
            ->where('created_at', '<', now()->subMinutes(15))
            ->update([
                'status' => MailLog::STATUS_FAILED,
                'error' => 'No se recibio confirmacion del servidor de correo. Revisa la configuracion SMTP y los logs del panel.',
                'updated_at' => now(),
            ]);

        $this->line(sprintf(
            'Limpieza: %d muestras, %d eventos, %d correos, %d instalaciones. %d envio(s) sin confirmar marcados como fallidos.',
            $samples,
            $events,
            $mails,
            $installs,
            $stuck
        ));

        return self::SUCCESS;
    }
}
