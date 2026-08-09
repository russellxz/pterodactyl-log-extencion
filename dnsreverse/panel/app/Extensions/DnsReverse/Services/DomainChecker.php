<?php

namespace Pterodactyl\Extensions\DnsReverse\Services;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;

/**
 * Comprueba de verdad si un dominio de un cliente esta funcionando.
 *
 * Cuando un cliente dice "mi pagina no carga" puede ser una de cuatro cosas
 * muy distintas, y desde el panel no habia forma de saber cual:
 *
 *   1. el nombre no existe en DNS (el navegador dice NXDOMAIN)
 *   2. existe pero apunta a otro sitio
 *   3. apunta bien pero el nodo no responde
 *   4. responde pero el certificado no vale
 *
 * Esto lo mira y lo dice en castellano.
 */
class DomainChecker
{
    /**
     * @return array{
     *     ok: bool,
     *     resumen: string,
     *     pasos: array<int, array{nombre: string, estado: string, detalle: string}>
     * }
     */
    public static function revisar(ProxyRecord $registro): array
    {
        $pasos = [];
        $dominio = (string) $registro->domain;
        $allocation = $registro->allocation;
        $ipEsperada = $allocation ? trim((string) $allocation->ip) : '';

        // --- 1. ¿El nombre existe? -----------------------------------------

        $direcciones = self::resolver($dominio);

        if ($direcciones === []) {
            $pasos[] = [
                'nombre' => 'El nombre existe en DNS',
                'estado' => 'error',
                'detalle' => 'No. El navegador dara "DNS_PROBE_FINISHED_NXDOMAIN". '
                    . 'El registro no esta creado en Cloudflare, o se borro. '
                    . ($registro->proxy_type === ProxyRecord::TYPE_DOMAIN
                        ? 'Como es un dominio propio del cliente, el registro lo tiene que crear el.'
                        : 'Prueba a borrar este DNS y volver a crearlo.'),
            ];

            return [
                'ok' => false,
                'resumen' => 'El dominio no existe en DNS: por eso no carga.',
                'pasos' => $pasos,
            ];
        }

        $pasos[] = [
            'nombre' => 'El nombre existe en DNS',
            'estado' => 'ok',
            'detalle' => 'Resuelve a ' . implode(', ', $direcciones) . '.',
        ];

        // --- 2. ¿Apunta a donde toca? --------------------------------------
        //
        // Con la nube naranja la IP que se ve es la de Cloudflare, no la del
        // nodo, y eso es correcto: hay que distinguirlo.

        $porCloudflare = self::pareceCloudflare($direcciones);

        if ($porCloudflare) {
            $pasos[] = [
                'nombre' => 'A donde apunta',
                'estado' => 'ok',
                'detalle' => 'A Cloudflare (nube naranja). El trafico pasa por Cloudflare antes de llegar al nodo.'
                    . ($registro->ssl_mode === ProxyRecord::SSL_LETSENCRYPT
                        ? ' OJO: con Let\'s Encrypt la nube tendria que estar en gris para poder renovar el certificado.'
                        : ''),
            ];
        } elseif ($ipEsperada !== '' && in_array($ipEsperada, $direcciones, true)) {
            $pasos[] = [
                'nombre' => 'A donde apunta',
                'estado' => 'ok',
                'detalle' => 'Directo al nodo (' . $ipEsperada . '), nube gris.',
            ];
        } else {
            $pasos[] = [
                'nombre' => 'A donde apunta',
                'estado' => 'error',
                'detalle' => 'Apunta a ' . implode(', ', $direcciones) . ' pero el servidor esta en '
                    . ($ipEsperada ?: 'otra direccion') . '. El cliente tiene que corregir su registro A.',
            ];
        }

        // --- 3. ¿Responde? --------------------------------------------------

        $protocolo = $registro->ssl_enabled ? 'https' : 'http';
        $respuesta = self::pedir($protocolo . '://' . $dominio);

        if ($respuesta['error'] !== null) {
            $esCertificado = str_contains(strtolower($respuesta['error']), 'ssl')
                || str_contains(strtolower($respuesta['error']), 'certificate');

            $pasos[] = [
                'nombre' => 'La pagina responde',
                'estado' => 'error',
                'detalle' => ($esCertificado
                    ? 'Problema con el certificado: '
                    : 'No contesta: ') . $respuesta['error']
                    . ($esCertificado
                        ? ' Revisa el certificado del dominio en la pestana Dominios, con el boton "Probar certificado".'
                        : ' Comprueba que el servidor este encendido y que nginx tenga la configuracion (boton Resincronizar).'),
            ];

            return [
                'ok' => false,
                'resumen' => $esCertificado ? 'El certificado no vale.' : 'El dominio existe pero no responde.',
                'pasos' => $pasos,
            ];
        }

        $codigo = $respuesta['codigo'];

        // Los errores 5xx propios de Cloudflare llevan explicacion conocida.
        $explicacion = match ($codigo) {
            521 => 'Cloudflare llega pero el nodo tiene el puerto cerrado o el servidor apagado.',
            522 => 'Cloudflare no consigue conectar con el nodo (cortafuegos o servidor caido).',
            523 => 'Cloudflare no encuentra el nodo: revisa que el registro apunte a la IP correcta.',
            525, 526 => 'Error de certificado entre Cloudflare y el nodo. Es lo tipico de mezclar '
                . 'Let\'s Encrypt con nube naranja, o de tener un certificado de origen que no vale para este dominio.',
            default => null,
        };

        if ($explicacion !== null) {
            $pasos[] = [
                'nombre' => 'La pagina responde',
                'estado' => 'error',
                'detalle' => 'Error ' . $codigo . ' de Cloudflare. ' . $explicacion,
            ];

            return ['ok' => false, 'resumen' => 'Cloudflare da error ' . $codigo . '.', 'pasos' => $pasos];
        }

        $pasos[] = [
            'nombre' => 'La pagina responde',
            'estado' => $codigo >= 200 && $codigo < 500 ? 'ok' : 'aviso',
            'detalle' => 'Contesta con codigo ' . $codigo . ' por ' . $protocolo . '.'
                . ($codigo >= 500 ? ' Ese error viene del propio servidor del cliente, no del DNS.' : ''),
        ];

        return [
            'ok' => true,
            'resumen' => 'El dominio funciona.',
            'pasos' => $pasos,
        ];
    }

    /**
     * Espera a que un nombre se vea de verdad en internet.
     *
     * Esto existe por un motivo muy concreto. Cloudflare acepta el registro al
     * instante, pero tarda unos segundos en servirlo desde todos sus
     * servidores. Si en ese hueco se le pide el certificado a Let's Encrypt,
     * su comprobacion devuelve NXDOMAIN y el certificado no se emite, aunque
     * el registro este perfectamente creado.
     *
     * Se pregunta a los resolutores publicos de Cloudflare y de Google por
     * HTTPS (DoH), que es lo mas parecido a lo que va a ver Let's Encrypt, y
     * ademas al resolutor del propio servidor.
     *
     * @return array{ok: bool, segundos: int, detalle: string}
     */
    public static function esperarResolucion(string $dominio, int $maximoSegundos = 90): array
    {
        $inicio = time();
        $espera = 2;

        while (true) {
            $direcciones = self::resolverEnPublicos($dominio);

            if ($direcciones !== []) {
                return [
                    'ok' => true,
                    'segundos' => time() - $inicio,
                    'detalle' => 'Resuelve a ' . implode(', ', $direcciones) . '.',
                ];
            }

            if ((time() - $inicio) >= $maximoSegundos) {
                return [
                    'ok' => false,
                    'segundos' => time() - $inicio,
                    'detalle' => 'Despues de ' . (time() - $inicio) . ' segundos el nombre sigue sin resolver.',
                ];
            }

            sleep($espera);
            $espera = min($espera + 2, 8);
        }
    }

    /**
     * Pregunta a 1.1.1.1 y a 8.8.8.8 por HTTPS, y de postre al resolutor del
     * sistema. Con que uno lo vea, vale.
     *
     * @return array<int, string>
     */
    private static function resolverEnPublicos(string $dominio): array
    {
        foreach (['https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve'] as $servicio) {
            try {
                $respuesta = Http::withHeaders(['Accept' => 'application/dns-json'])
                    ->timeout(6)
                    ->connectTimeout(4)
                    ->get($servicio, ['name' => $dominio, 'type' => 'A']);

                if (!$respuesta->successful()) {
                    continue;
                }

                $direcciones = [];

                foreach ((array) $respuesta->json('Answer', []) as $entrada) {
                    // Tipo 1 = A. Se ignoran los CNAME intermedios.
                    if ((int) ($entrada['type'] ?? 0) === 1 && !empty($entrada['data'])) {
                        $direcciones[] = (string) $entrada['data'];
                    }
                }

                if ($direcciones !== []) {
                    return $direcciones;
                }
            } catch (\Throwable) {
                // Ese servicio no contesta: se prueba el siguiente.
            }
        }

        return self::resolver($dominio);
    }

    /**
     * @return array<int, string>
     */
    private static function resolver(string $dominio): array
    {
        $direcciones = [];

        try {
            foreach ((array) @dns_get_record($dominio, DNS_A) as $registro) {
                if (!empty($registro['ip'])) {
                    $direcciones[] = (string) $registro['ip'];
                }
            }

            foreach ((array) @dns_get_record($dominio, DNS_AAAA) as $registro) {
                if (!empty($registro['ipv6'])) {
                    $direcciones[] = (string) $registro['ipv6'];
                }
            }
        } catch (\Throwable) {
            // Sin resolucion DNS disponible se prueba con la del sistema.
        }

        if ($direcciones === []) {
            $resuelta = @gethostbyname($dominio);

            if ($resuelta !== $dominio && filter_var($resuelta, FILTER_VALIDATE_IP)) {
                $direcciones[] = $resuelta;
            }
        }

        return array_values(array_unique($direcciones));
    }

    /**
     * Rangos publicos de Cloudflare (los mas usados). Sirve para distinguir
     * "esta detras de la nube naranja" de "apunta a cualquier otro sitio".
     */
    private static function pareceCloudflare(array $direcciones): bool
    {
        $rangos = [
            '103.21.244.', '103.22.200.', '103.31.4.', '104.16.', '104.17.', '104.18.', '104.19.',
            '104.20.', '104.21.', '104.22.', '104.23.', '104.24.', '104.25.', '104.26.', '104.27.',
            '104.28.', '108.162.', '131.0.72.', '141.101.', '162.158.', '162.159.', '172.64.',
            '172.65.', '172.66.', '172.67.', '173.245.', '188.114.', '190.93.', '197.234.', '198.41.',
        ];

        foreach ($direcciones as $direccion) {
            foreach ($rangos as $rango) {
                if (str_starts_with($direccion, $rango)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{codigo: int, error: ?string}
     */
    private static function pedir(string $url): array
    {
        try {
            $respuesta = Http::withoutVerifying()
                ->timeout(8)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'DnsReverse/1.0 (comprobacion desde el panel)'])
                ->get($url);

            return ['codigo' => $respuesta->status(), 'error' => null];
        } catch (\Throwable $e) {
            return ['codigo' => 0, 'error' => $e->getMessage()];
        }
    }
}
