<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Models\UpdateRun;
use Pterodactyl\Extensions\LogsPterodactyl\Services\PanelUpdater;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Actualizacion del panel desde el area de administracion.
 */
class UpdaterController extends Controller
{
    public function __construct(private PanelUpdater $updater)
    {
    }

    public function index()
    {
        $latest = $this->updater->latestVersion();
        $running = UpdateRun::query()->where('status', UpdateRun::STATUS_RUNNING)->latest('started_at')->first();

        return view('logspterodactyl::admin.updater', [
            'current' => $this->updater->currentVersion(),
            'latest' => $latest,
            'available' => $this->updater->updateAvailable(),
            'theme' => $this->updater->themeInfo(),
            'themeLags' => $latest['version'] ? $this->updater->themeLagsBehind($latest['version']) : false,
            'checks' => $this->updater->preflight(),
            'blocking' => $this->updater->preflightBlocking(),
            'runs' => UpdateRun::query()->orderByDesc('started_at')->limit(10)->get(),
            'running' => $running,
            'canExec' => $this->updater->canExec(),
        ]);
    }

    public function check()
    {
        $latest = $this->updater->latestVersion(true);

        if ($latest['error']) {
            return back()->with('logspterodactyl_error', 'No se pudo consultar la ultima version: ' . $latest['error']);
        }

        return back()->with(
            'logspterodactyl_success',
            $this->updater->updateAvailable()
                ? 'Hay una version nueva disponible: ' . $latest['version']
                : 'El panel ya esta en la ultima version (' . $this->updater->currentVersion() . ').'
        );
    }

    public function start(Request $request)
    {
        $request->validate([
            'confirm' => 'required|accepted',
            'version' => 'nullable|string|max:20',
            'restore_theme' => 'nullable|boolean',
        ]);

        $blocking = $this->updater->preflightBlocking();

        if ($blocking !== [] && !$request->boolean('ignore_checks')) {
            return back()->with(
                'logspterodactyl_error',
                'No se puede actualizar todavia: ' . implode(' | ', array_map(fn ($c) => $c['label'] . ' - ' . $c['detail'], $blocking))
            );
        }

        if (UpdateRun::query()->where('status', UpdateRun::STATUS_RUNNING)->exists()) {
            return back()->with('logspterodactyl_error', 'Ya hay una actualizacion en marcha.');
        }

        try {
            $run = $this->updater->prepare(
                $request->filled('version') ? (string) $request->input('version') : null,
                $request->user()->id ?? null,
                $request->boolean('restore_theme', true)
            );
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', 'No se pudo preparar la actualizacion: ' . $e->getMessage());
        }

        try {
            $this->updater->launch($run);
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => UpdateRun::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();

            return back()->with('logspterodactyl_error', $e->getMessage());
        }

        return redirect()
            ->route('admin.logspterodactyl.updater')
            ->with('logspterodactyl_success', 'Actualizacion lanzada. El panel entrara en mantenimiento unos minutos.')
            ->with('logspterodactyl_watch', $run->token);
    }

    /**
     * Estado de la actualizacion.
     *
     * Durante la actualizacion el panel esta en modo mantenimiento y esta ruta
     * deja de responder, por eso la pantalla lee ademas el archivo estatico
     * /extensions/logspterodactyl/runs/<token>/status.json, que sirve el servidor web
     * directamente sin pasar por PHP.
     */
    public function status(string $run): JsonResponse
    {
        $model = UpdateRun::query()->findOrFail((int) $run);

        $this->updater->finalize($model);

        return new JsonResponse([
            'run' => [
                'id' => $model->id,
                'token' => $model->token,
                'status' => $model->status,
                'from' => $model->from_version,
                'to' => $model->to_version,
            ],
            'status' => $this->updater->status($model),
            'static_url' => '/extensions/logspterodactyl/runs/' . $model->token . '/status.json',
        ]);
    }

    public function rollback(Request $request, string $run)
    {
        $request->validate(['confirm' => 'required|accepted']);

        $model = UpdateRun::query()->findOrFail((int) $run);

        try {
            $this->updater->launchRollback($model);
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', $e->getMessage());
        }

        $model->forceFill(['status' => UpdateRun::STATUS_ROLLED_BACK])->save();

        return back()->with(
            'logspterodactyl_success',
            'Vuelta atras lanzada. El panel volvera al estado anterior a esa actualizacion en unos minutos.'
        );
    }
}
