<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Segunda barrera para el area de administracion de la extension.
 *
 * El panel ya protege /admin, pero aqui se ven y se editan tokens de
 * Cloudflare y claves privadas de certificados, asi que se vuelve a comprobar
 * explicitamente que quien entra es administrador.
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
