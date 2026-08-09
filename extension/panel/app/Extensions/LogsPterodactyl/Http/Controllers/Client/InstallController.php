<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Services\InstallGuard;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
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
            // Si acaba de fallar, el aviso se queda para ofrecer el boton de
            // volver a instalar en cuanto el cliente corrija sus datos.
            $fallida = in_array($model->status, [
                Server::STATUS_INSTALL_FAILED,
                Server::STATUS_REINSTALL_FAILED,
            ], true);

            return new JsonResponse([
                'installing' => false,
                'failed' => $fallida,
                'can_reinstall' => $fallida && $this->settings->bool('client_can_reinstall'),
                'server' => $model->name,
            ]);
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

        // Por defecto se corta de raiz. Si no, el contenedor de instalacion
        // del nodo sigue trabajando y cuando termina avisa al panel: el
        // cliente pulsa "detener", le decimos que si, y sigue viendo
        // "instalando". Eso es exactamente lo que hay que evitar.
        if ($this->settings->bool('client_cancel_force')) {
            $mode = InstallGuard::MODE_FORCE_ROTATE;
        } elseif ($this->settings->bool('client_cancel_rotate_port')) {
            $mode = InstallGuard::MODE_FAIL_ROTATE;
        } else {
            $mode = InstallGuard::MODE_FAIL;
        }

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
            'can_reinstall' => $this->settings->bool('client_can_reinstall'),
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * El cliente relanza la instalacion despues de corregir sus datos.
     *
     * Hace falta un camino propio porque tras una parada forzada el servidor ya
     * no existe en el nodo y el boton de reinstalar del panel daria un 404.
     * Aqui se vuelve a crear y se lanza la instalacion en una sola accion.
     */
    public function reinstall(Request $request, string $server): JsonResponse
    {
        if (!$this->settings->bool('client_can_reinstall')) {
            return new JsonResponse(['error' => 'Esta opcion no esta disponible. Ponte en contacto con el soporte.'], 403);
        }

        $model = $this->resolve($request, $server);

        if (!$model) {
            return new JsonResponse(['error' => 'Servidor no encontrado.'], 404);
        }

        if ($model->status === Server::STATUS_INSTALLING) {
            return new JsonResponse(['error' => 'Este servidor ya esta instalando.'], 409);
        }

        if ($model->status === Server::STATUS_SUSPENDED) {
            return new JsonResponse(['error' => 'Este servidor esta suspendido. Ponte en contacto con el soporte.'], 403);
        }

        try {
            $this->guard->recreate($model);
        } catch (\Throwable $e) {
            report($e);

            return new JsonResponse([
                'error' => 'No se pudo relanzar la instalacion. Ponte en contacto con el soporte.',
            ], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'Instalacion relanzada. Puede tardar unos minutos.',
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
