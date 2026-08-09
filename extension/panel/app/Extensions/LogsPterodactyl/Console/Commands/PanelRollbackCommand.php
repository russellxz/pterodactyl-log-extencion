<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\LogsPterodactyl\Models\UpdateRun;
use Pterodactyl\Extensions\LogsPterodactyl\Services\PanelUpdater;

/**
 * Deshace una actualizacion del panel restaurando su respaldo.
 */
class PanelRollbackCommand extends Command
{
    protected $signature = 'logspterodactyl:panel-rollback
                            {run? : Id de la actualizacion (por defecto, la ultima)}
                            {--list : Solo listar las actualizaciones disponibles}
                            {--force : No preguntar}';

    protected $description = 'Deshace una actualizacion del panel usando su respaldo';

    public function handle(PanelUpdater $updater): int
    {
        $runs = UpdateRun::query()->orderByDesc('started_at')->limit(20)->get();

        if ($runs->isEmpty()) {
            $this->error('No hay ninguna actualizacion registrada.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->table(
                ['Id', 'Fecha', 'Desde', 'Hasta', 'Estado', 'Respaldo'],
                $runs->map(fn (UpdateRun $run) => [
                    $run->id,
                    optional($run->started_at)->format('d/m/Y H:i'),
                    $run->from_version,
                    $run->to_version,
                    $run->status,
                    is_dir((string) $run->backup_path) ? 'disponible' : 'borrado',
                ])->all()
            );

            return self::SUCCESS;
        }

        $run = $this->argument('run')
            ? UpdateRun::query()->find((int) $this->argument('run'))
            : $runs->first();

        if (!$run) {
            $this->error('No se encontro esa actualizacion. Usa --list para verlas.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  Actualizacion #' . $run->id . ': ' . $run->from_version . ' -> ' . $run->to_version);
        $this->line('  Respaldo: ' . $run->backup_path);
        $this->line('');
        $this->warn('  Se restauraran los archivos Y la base de datos a como estaban antes.');
        $this->warn('  Todo lo ocurrido en el panel despues de esa actualizacion se perdera.');
        $this->line('');

        if (!$this->option('force') && !$this->confirm('¿Continuar?', false)) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        try {
            $script = $updater->prepareRollback($run);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Ejecutando ' . $script);
        $this->line('');

        $code = 0;
        passthru('bash ' . escapeshellarg($script), $code);

        if ($code !== 0) {
            $this->error('La vuelta atras no termino correctamente. Revisa el registro en ' . dirname($script) . '/rollback.log');

            return self::FAILURE;
        }

        $run->forceFill(['status' => UpdateRun::STATUS_ROLLED_BACK])->save();

        $this->info('El panel ha vuelto al estado anterior.');

        return self::SUCCESS;
    }
}
