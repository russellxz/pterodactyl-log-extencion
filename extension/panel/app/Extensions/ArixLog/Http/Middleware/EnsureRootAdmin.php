<?php

namespace Pterodactyl\Extensions\ArixLog\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Segunda barrera de seguridad para el area de administracion de la extension.
 *
 * El panel ya protege /admin con su propio middleware, pero aqui se muestran
 * los registros del panel y datos de clientes, asi que se vuelve a comprobar
 * explicitamente que quien entra es administrador. Si una version futura del
 * panel cambiara el nombre de su middleware, esta comprobacion sigue en pie.
 */
class EnsureRootAdmin
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $user = $request->user();

        if (!$user || !(bool) ($user->root_admin ?? false)) {
            throw new AccessDeniedHttpException('Esta seccion es solo para administradores.');
        }

        return $next($request);
    }
}
