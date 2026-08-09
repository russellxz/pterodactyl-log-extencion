<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Middleware;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\LogsPterodactylServiceProvider;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cuelga los recursos de la extension de las paginas HTML del panel.
 *
 * Se hace desde un middleware, y no editando las plantillas blade, por dos
 * motivos: el tema Arix reemplaza sus propias plantillas cada vez que se
 * instala o actualiza (y se llevaria por delante cualquier etiqueta que le
 * hubieramos metido), y asi la extension no deja ni una linea escrita en
 * archivos del panel.
 *
 * Todo el trabajo sobre el DOM lo hace el JavaScript inyectado. Aqui no se
 * toca el HTML del tema, solo se anaden etiquetas antes de </body>, que es la
 * operacion mas inocua posible.
 */
class InjectAssets
{
    /**
     * Prefijos que nunca se tocan: son APIs, descargas o el propio wings.
     */
    private const SKIP_PREFIXES = [
        'api/', 'daemon/', 'locales/', 'sw.js', 'manifest.json',
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

        $isAdmin = $request->is('admin') || $request->is('admin/*');
        $tags = $isAdmin ? $this->adminTags() : $this->clientTags($request);

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

        // El usuario tiene que estar autenticado: no se inyecta nada en la
        // pantalla de login ni en paginas publicas.
        return (bool) $request->user();
    }

    private function adminTags(): string
    {
        $base = $this->assetBase();

        return implode('', [
            '<link data-logspterodactyl="1" rel="stylesheet" href="' . $base . '/admin.css?v=' . $this->version() . '">',
            '<script data-logspterodactyl="1" src="' . $base . '/admin.js?v=' . $this->version() . '" defer></script>',
        ]);
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
