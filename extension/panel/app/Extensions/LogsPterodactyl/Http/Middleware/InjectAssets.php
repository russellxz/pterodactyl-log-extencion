<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\LogsPterodactylServiceProvider;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cuelga el aviso de instalacion atascada en la pantalla del servidor.
 *
 * QUE HACE Y QUE YA NO HACE
 * -------------------------
 * Antes esta clase metia recursos en TODAS las paginas del panel: en el area
 * de administracion colgaba un admin.js que construia la entrada del menu
 * manipulando el DOM, y en el area de cliente colgaba el aviso en cualquier
 * pagina, estuvieras donde estuvieras.
 *
 * Ya no:
 *
 *   - El menu del admin es un archivo blade de verdad, que pone el instalador
 *     (en el hueco del tema o como <li> en la plantilla). Aqui no se toca NADA
 *     del area de administracion.
 *   - El aviso del cliente solo se cuelga en /server/<id>, que es la unica
 *     pantalla donde puede salir. En el resto del panel (cuenta, ajustes,
 *     listado de servidores...) no se anade ni una etiqueta.
 *
 * Lo que queda es lo minimo imprescindible: dos etiquetas antes de </body> en
 * la pantalla de un servidor. No hay ningun MutationObserver ni ningun bucle
 * rastreando la pagina, que es lo que disparaba la CPU del navegador y hacia
 * que Cloudflare tomara al cliente por un bot.
 *
 * Sigue siendo un middleware y no una plantilla porque el area de cliente es
 * una aplicacion React: su HTML lo genera el propio panel y el tema Arix
 * reemplaza esas plantillas cada vez que se instala o actualiza.
 */
class InjectAssets
{
    /**
     * Prefijos que nunca se tocan: son APIs, descargas o el propio wings.
     *
     * "admin/" esta aqui a proposito: el menu del area de administracion ya no
     * se inyecta, lo pone el instalador como blade.
     */
    private const SKIP_PREFIXES = [
        'api/', 'daemon/', 'locales/', 'sw.js', 'manifest.json', 'admin/', 'admin',
    ];

    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);

        try {
            return $this->inject($request, $response);
        } catch (\Throwable $e) {
            // Ante cualquier duda se devuelve la respuesta original intacta.
            // Una extension no puede ser el motivo de que el panel no cargue.
            try {
                logger()->debug('[LogsPterodactyl] no se pudieron inyectar los recursos: ' . $e->getMessage());
            } catch (\Throwable) {
                // Sin logger no hay nada mas que hacer.
            }

            return $response;
        }
    }

    private function inject(Request $request, mixed $response): mixed
    {
        if (!$this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if (!is_string($content) || stripos($content, '</body>') === false) {
            return $response;
        }

        // Si ya estuviera inyectado (doble paso por el middleware) no se repite.
        if (str_contains($content, 'data-logspterodactyl="1"')) {
            return $response;
        }

        $tags = $this->clientTags($request);

        if ($tags === '') {
            return $response;
        }

        $position = strripos($content, '</body>');
        $content = substr($content, 0, $position) . $tags . substr($content, $position);

        $response->setContent($content);

        // Content-Length deja de ser valido tras modificar el cuerpo.
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function shouldInject(Request $request, mixed $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }

        if (!method_exists($response, 'getContent') || !method_exists($response, 'setContent')) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($request->ajax() || $request->wantsJson() || $request->isJson()) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type');
        if ($type !== '' && stripos($type, 'text/html') === false) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        // SOLO la pantalla de un servidor. Es donde puede salir el aviso de
        // instalacion atascada, asi que es la unica que necesita el script.
        // El resto del panel se queda exactamente como lo sirve Pterodactyl.
        if (!preg_match('#^server/[a-zA-Z0-9-]{4,40}(/|$)#', $path)) {
            return false;
        }

        // El usuario tiene que estar autenticado: no se inyecta nada en la
        // pantalla de login ni en paginas publicas.
        return (bool) $request->user();
    }

    private function clientTags(Request $request): string
    {
        $settings = Settings::make();

        if (!$settings->bool('client_cancel_enabled')) {
            return '';
        }

        $base = $this->assetBase();

        $config = [
            // Ruta relativa: asi funciona igual detras de un proxy inverso,
            // con varios dominios apuntando al panel o si APP_URL esta mal.
            'endpoint' => '/api/logspterodactyl',
            'minutes' => $settings->int('client_cancel_minutes', 1, 1440),
            'csrf' => csrf_token(),
            'locale' => substr((string) ($request->user()->language ?? 'es'), 0, 2),
        ];

        return implode('', [
            '<link data-logspterodactyl="1" rel="stylesheet" href="' . $base . '/client.css?v=' . $this->version() . '">',
            '<script data-logspterodactyl="1">window.LogsPterodactylConfig=' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>',
            '<script data-logspterodactyl="1" src="' . $base . '/client.js?v=' . $this->version() . '" defer></script>',
        ]);
    }

    /**
     * Ruta relativa a proposito: asi funciona igual detras de un proxy, con
     * varios dominios o si APP_URL esta mal puesto.
     */
    private function assetBase(): string
    {
        return '/extensions/logspterodactyl';
    }

    private function version(): string
    {
        return LogsPterodactylServiceProvider::VERSION;
    }
}
