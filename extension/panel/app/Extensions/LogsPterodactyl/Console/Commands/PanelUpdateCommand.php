<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\LogsPterodactyl\Services\PanelUpdater;

/**
 * Actualiza el panel desde la linea de comandos.
 *
 * Es la forma recomendada: por SSH y como root hay permisos para tocar los
 * archivos, ejecutar composer y devolver los permisos al servidor web. Desde
 * el navegador el proceso es el mismo, pero corre como el usuario de PHP y
 * puede quedarse corto de permisos.
 */
class PanelUpdateCommand extends Command
{
    protected $signature = 'logspterodactyl:panel-update
                            {--to= : Version concreta a la que actualizar (por defecto, la ultima publicada)}
                            {--no-theme : No restaurar el tema Arix despues}
                            {--dry-run : Solo preparar el guion, sin ejecutarlo}
                            {--force : Saltarse las comprobaciones previas}';

    protected $description = 'Actualiza el panel de Pterodactyl conservando el tema Arix';

    public function handle(PanelUpdater $updater): int
    {
        $current = $updater->currentVersion();
        $latest = $updater->latestVersion(true);

        $this->line('');
        $this->line('  Version instalada: ' . $current);
        $this->line('  Ultima publicada:  ' . ($latest['version'] ?: 'no se pudo consultar'));

        $theme = $updater->themeInfo();

        if ($theme['installed']) {
            $this->line('  Tema Arix:         detectado' . ($theme['staged_path'] ? ' (restaurable)' : ' (SIN carpeta arix/<version>)'));
        }

        $this->line('');

        $blocking = $updater->preflightBlocking();

        if ($blocking !== [] && !$this->option('force')) {
            $this->error('Comprobaciones criticas sin pasar:');

            foreach ($blocking as $check) {
                $this->line('  - ' . $check['label'] . ': ' . $check['detail']);
            }

            $this->line('');
            $this->line('Corrigelas, o usa --force si sabes lo que haces.');

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm('El panel entrara en mantenimiento varios minutos. Antes se hara un respaldo completo. ¿Continuar?', false)) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        try {
            $run = $updater->prepare(
                $this->option('to') ?: null,
                null,
                !$this->option('no-theme') && $theme['installed']
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $script = $updater->runDir($run->token) . '/update.sh';

        $this->line('');
        $this->info('Guion preparado en: ' . $script);
        $this->info('Respaldo en:        ' . $run->backup_path);
        $this->info('Registro en:        ' . $run->log_path);
        $this->line('');

        if ($this->option('dry-run')) {
            $this->line('Modo simulacion: no se ha ejecutado nada.');
            $this->line('Para lanzarlo a mano: sudo bash ' . $script);

            return self::SUCCESS;
        }

        $this->info('Ejecutando... (sigue el progreso con: tail -f ' . $run->log_path . ')');
        $this->line('');

        // En consola se ejecuta en primer plano para poder ver la salida y
        // devolver un codigo de salida util a quien llame al comando.
        $code = 0;
        passthru('bash ' . escapeshellarg($script), $code);

        $updater->finalize($run);

        if ($code !== 0) {
            $this->error('La actualizacion no termino correctamente. Revisa ' . $run->log_path);
            $this->line('Para deshacerla: php artisan logspterodactyl:panel-rollback ' . $run->id);

            return self::FAILURE;
        }

        $this->info('Panel actualizado.');
        $this->line('Si algo va mal: php artisan logspterodactyl:panel-rollback ' . $run->id);

        return self::SUCCESS;
    }
}
