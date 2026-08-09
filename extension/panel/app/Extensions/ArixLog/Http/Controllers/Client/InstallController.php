<?php

namespace Pterodactyl\Extensions\ArixLog\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\ArixLog\Services\InstallGuard;
use Pterodactyl\Extensions\ArixLog\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;

/**
 * Lo que consume el aviso que ve el cliente cuando su servidor lleva
 * demasiado tiempo instalando.
 */
class InstallController extends Controller
{
    public function __construct(private InstallGuard $guard, private Settings $settings)
    {
    }

    /**
     * Estado de la instalacion de un servidor del propio usuario.
     */
    public function status(Request $request, string $server): JsonResponse
    {
        $model = $this->resolve($request, $server);

        if (!$model) {
            return new JsonResponse(['installing' => false], 404);
        }

        if ($model->status !== Server::STATUS_INSTALLING) {
            return new JsonResponse(['installing' => false]);
        }

        $minutes = $this->guard->minutesInstalling($model);
        $threshold = $this->settings->int('client_cancel_minutes', 1, 1440);

        return new JsonResponse([
            'installing' => true,
            'enabled' => $this->settings->bool('client_cancel_enabled'),
            'minutes' => $minutes,
            'threshold' => $threshold,
            'can_cancel' => $this->settings->bool('client_cancel_enabled') && $minutes >= $threshold,
            'server' => $model->name,
        ]);
    }

    /**
     * El cliente detiene la instalacion de su servidor.
     */
    public function cancel(Request $request, string $server): JsonResponse
    {
        if (!$this->settings->bool('client_cancel_enabled')) {
            return new JsonResponse([
                'error' => 'Esta opcion no esta disponible. Ponte en contacto con el soporte.',
            ], 403);
        }

        $model = $this->resolve($request, $server);

        if (!$model) {
            return new JsonResponse(['error' => 'Servidor no encontrado.'], 404);
        }

        if ($model->status !== Server::STATUS_INSTALLING) {
            return new JsonResponse(['error' => 'Este servidor ya no esta instalando.'], 409);
        }

        $minutes = $this->guard->minutesInstalling($model);
        $threshold = $this->settings->int('client_cancel_minutes', 1, 1440);

        if ($minutes < $threshold) {
            return new JsonResponse([
                'error' => sprintf(
                    'Todavia no. La instalacion puede detenerse a partir de los %d minutos y lleva %d.',
                    $threshold,
                    $minutes
                ),
            ], 425);
        }

        // El cliente nunca puede lanzar el modo forzado (que borra el servidor
        // en el nodo). Eso queda reservado al administrador.
        $mode = $this->settings->bool('client_cancel_rotate_port')
            ? InstallGuard::MODE_FAIL_ROTATE
            : InstallGuard::MODE_FAIL;

        try {
            $result = $this->guard->stop($model, $mode, (string) $request->user()->email, false);
        } catch (\Throwable $e) {
            report($e);

            return new JsonResponse([
                'error' => 'No se pudo detener la instalacion. Ponte en contacto con el soporte.',
            ], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'Instalacion detenida. Revisa los datos de arranque de tu servidor y vuelve a instalarlo.',
            'port_changed' => $result['port_changed'],
            'new_allocation' => $result['new_allocation'],
        ]);
    }

    /**
     * Busca el servidor comprobando que quien pregunta tiene derecho a verlo.
     *
     * Solo el dueno (o un administrador) puede parar una instalacion: un
     * subusuario no deberia poder tumbar la instalacion de un servidor ajeno.
     */
    private function resolve(Request $request, string $identifier): ?Server
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        // El identificador que aparece en la URL del panel es el uuid corto,
        // pero se acepta tambien el uuid completo.
        $query = Server::query()->with(['user', 'node', 'allocation', 'egg']);

        $model = strlen($identifier) === 36
            ? $query->where('uuid', $identifier)->first()
            : $query->where('uuidShort', $identifier)->first();

        if (!$model) {
            return null;
        }

        if ((bool) $user->root_admin || $model->owner_id === $user->id) {
            return $model;
        }

        return null;
    }
}
