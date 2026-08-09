<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Middleware;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\DnsReverse\DnsReverseServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cuelga los recursos de la extension de las paginas HTML del panel.
 *
 * Se hace desde un middleware, no editando plantillas blade ni archivos de
 * React, por dos motivos: el tema reemplaza sus plantillas cada vez que se
 * instala o actualiza, y asi la extension no deja ni una linea escrita en
 * archivos del panel. Todo el trabajo sobre el DOM lo hace el JavaScript
 * inyectado; aqui solo se anaden etiquetas antes de </body>.
 */
class InjectAssets
{
    private const SKIP_PREFIXES = [
        'api/', 'daemon/', 'locales/', 'sw.js', 'manifest.json',
    ];

    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);

        try {
            return $this->inject($request, $response);
        } catch (\Throwable $e) {
            try {
                logger()->debug('[DnsReverse] no se pudieron inyectar los recursos: ' . $e->getMessage());
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

        if (str_contains($content, 'data-dnsreverse="1"')) {
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

        return (bool) $request->user();
    }

    private function adminTags(): string
    {
        $base = $this->assetBase();
        $version = $this->version();

        return implode('', [
            '<link data-dnsreverse="1" rel="stylesheet" href="' . $base . '/admin.css?v=' . $version . '">',
            '<script data-dnsreverse="1" src="' . $base . '/admin.js?v=' . $version . '" defer></script>',
        ]);
    }

    private function clientTags(Request $request): string
    {
        $base = $this->assetBase();
        $version = $this->version();

        $config = [
            // Ruta relativa a proposito: funciona igual detras de un proxy
            // inverso, con varios dominios apuntando al panel o si APP_URL
            // esta mal puesto.
            'endpoint' => '/api/dnsreverse',
            'csrf' => csrf_token(),
        ];

        return implode('', [
            '<link data-dnsreverse="1" rel="stylesheet" href="' . $base . '/client.css?v=' . $version . '">',
            '<script data-dnsreverse="1">window.DnsReverseConfig=' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>',
            '<script data-dnsreverse="1" src="' . $base . '/client.js?v=' . $version . '" defer></script>',
        ]);
    }

    private function assetBase(): string
    {
        return '/extensions/dnsreverse';
    }

    private function version(): string
    {
        return DnsReverseServiceProvider::VERSION;
    }
}
