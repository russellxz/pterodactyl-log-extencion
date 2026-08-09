<?php

namespace Pterodactyl\Extensions\DnsReverse\Services;

/**
 * Revisa un certificado antes de que llegue al nodo.
 *
 * Un certificado mal pegado (a medias, sin la clave que le corresponde, o de
 * otro dominio) no da error al guardarlo: da error MESES DESPUES, cuando el
 * cliente entra a su pagina y el navegador le dice que el sitio no es seguro.
 * Por eso se comprueba aqui, en el momento de pegarlo, y se explica en
 * castellano que es lo que falla.
 */
class CertificateInspector
{
    /**
     * @return array{
     *     ok: bool,
     *     errores: array<int, string>,
     *     avisos: array<int, string>,
     *     datos: array<string, mixed>
     * }
     */
    public static function revisar(?string $certificado, ?string $clave, ?string $dominio = null): array
    {
        $certificado = trim((string) $certificado);
        $clave = trim((string) $clave);

        $resultado = ['ok' => false, 'errores' => [], 'avisos' => [], 'datos' => []];

        if ($certificado === '' && $clave === '') {
            $resultado['errores'][] = 'No hay ningun certificado puesto.';

            return $resultado;
        }

        if (!extension_loaded('openssl')) {
            $resultado['avisos'][] = 'El panel no tiene la extension openssl de PHP, asi que no se puede comprobar el certificado a fondo.';
            $resultado['ok'] = $certificado !== '' && $clave !== '';

            return $resultado;
        }

        // --- Forma del texto ---------------------------------------------

        if (!str_contains($certificado, '-----BEGIN CERTIFICATE-----')) {
            $resultado['errores'][] = 'El certificado tiene que empezar por -----BEGIN CERTIFICATE-----. '
                . 'Copialo entero, incluidas esas lineas.';
        }

        if (!str_contains($certificado, '-----END CERTIFICATE-----')) {
            $resultado['errores'][] = 'Falta el final del certificado (-----END CERTIFICATE-----). Parece cortado.';
        }

        if ($clave === '') {
            $resultado['errores'][] = 'Falta la clave privada.';
        } elseif (!preg_match('/-----BEGIN (RSA |EC |ENCRYPTED )?PRIVATE KEY-----/', $clave)) {
            $resultado['errores'][] = 'La clave privada tiene que empezar por -----BEGIN PRIVATE KEY----- '
                . '(o BEGIN RSA PRIVATE KEY). Revisa que no hayas pegado otra vez el certificado.';
        }

        if (str_contains($clave, 'BEGIN CERTIFICATE')) {
            $resultado['errores'][] = 'En el hueco de la clave privada has pegado un certificado. '
                . 'Son dos textos distintos: el certificado arriba y la clave abajo.';
        }

        if ($resultado['errores'] !== []) {
            return $resultado;
        }

        // --- Contenido del certificado ------------------------------------

        $x509 = @openssl_x509_read($certificado);

        if ($x509 === false) {
            $resultado['errores'][] = 'El certificado no se puede leer: esta corrupto o le falta algun trozo.';

            return $resultado;
        }

        $detalle = @openssl_x509_parse($x509);

        if (!is_array($detalle)) {
            $resultado['errores'][] = 'El certificado no se puede interpretar.';

            return $resultado;
        }

        $nombres = self::nombresQueCubre($detalle);
        $caduca = isset($detalle['validTo_time_t']) ? (int) $detalle['validTo_time_t'] : 0;
        $empieza = isset($detalle['validFrom_time_t']) ? (int) $detalle['validFrom_time_t'] : 0;
        $emisor = self::emisor($detalle);

        $resultado['datos'] = [
            'nombres' => $nombres,
            'emisor' => $emisor,
            'caduca' => $caduca > 0 ? date('d/m/Y', $caduca) : null,
            'dias_restantes' => $caduca > 0 ? (int) floor(($caduca - time()) / 86400) : null,
            'es_de_origen' => self::esDeOrigenCloudflare($detalle),
        ];

        // --- La clave tiene que ser LA de este certificado -----------------

        $claveValida = @openssl_pkey_get_private($clave);

        if ($claveValida === false) {
            $resultado['errores'][] = 'La clave privada no se puede leer. Si te la dieron protegida con '
                . 'contrasena, no sirve: hace falta sin contrasena.';
        } elseif (!@openssl_x509_check_private_key($x509, $claveValida)) {
            $resultado['errores'][] = 'La clave privada NO es la de este certificado. Son de dos generaciones '
                . 'distintas: vuelve a Cloudflare, genera el certificado otra vez y copia los dos textos de esa misma pantalla '
                . '(la clave solo se muestra una vez).';
        }

        // --- Fechas --------------------------------------------------------

        if ($empieza > 0 && $empieza > time()) {
            $resultado['errores'][] = 'Este certificado todavia no es valido: empieza el ' . date('d/m/Y', $empieza) . '.';
        }

        if ($caduca > 0 && $caduca < time()) {
            $resultado['errores'][] = 'Este certificado esta CADUCADO desde el ' . date('d/m/Y', $caduca) . '. Genera uno nuevo.';
        } elseif ($caduca > 0 && ($caduca - time()) < 30 * 86400) {
            $resultado['avisos'][] = 'Caduca pronto: el ' . date('d/m/Y', $caduca) . '.';
        }

        // --- ¿Sirve para este dominio? -------------------------------------

        if ($dominio !== null && $dominio !== '') {
            if (!self::cubre($nombres, $dominio)) {
                $resultado['errores'][] = 'Este certificado no vale para ' . $dominio . '. Sirve para: '
                    . implode(', ', $nombres) . '. Al generarlo en Cloudflare pon tanto ' . $dominio
                    . ' como *.' . $dominio . '.';
            } elseif (!self::cubre($nombres, 'cualquiera.' . $dominio)) {
                $resultado['avisos'][] = 'Vale para ' . $dominio . ' pero NO para sus subdominios. '
                    . 'Si tus clientes van a pedir subdominios, genera el certificado con *.' . $dominio . ' incluido.';
            }
        }

        if (!$resultado['datos']['es_de_origen'] && $resultado['errores'] === []) {
            $resultado['avisos'][] = 'No parece un Certificado de Origen de Cloudflare (lo emitio "' . $emisor . '"). '
                . 'Funciona igual si es un certificado publico valido, pero recuerda que el de origen SOLO lo acepta '
                . 'Cloudflare: el dominio tiene que ir con la nube naranja.';
        }

        $resultado['ok'] = $resultado['errores'] === [];

        return $resultado;
    }

    /**
     * Nombres que cubre el certificado: el comun y todos los alternativos.
     *
     * @return array<int, string>
     */
    private static function nombresQueCubre(array $detalle): array
    {
        $nombres = [];

        if (!empty($detalle['subject']['CN'])) {
            $nombres[] = strtolower((string) $detalle['subject']['CN']);
        }

        $alternativos = $detalle['extensions']['subjectAltName'] ?? '';

        foreach (explode(',', (string) $alternativos) as $entrada) {
            $entrada = trim($entrada);

            if (str_starts_with($entrada, 'DNS:')) {
                $nombres[] = strtolower(substr($entrada, 4));
            }
        }

        return array_values(array_unique(array_filter($nombres)));
    }

    /**
     * ¿Alguno de los nombres del certificado sirve para este dominio?
     * Se tienen en cuenta los comodines (*.dominio.com).
     */
    private static function cubre(array $nombres, string $dominio): bool
    {
        $dominio = strtolower(trim($dominio));

        foreach ($nombres as $nombre) {
            if ($nombre === $dominio) {
                return true;
            }

            if (str_starts_with($nombre, '*.')) {
                $base = substr($nombre, 2);

                // Un comodin cubre UN nivel: *.midominio.com vale para
                // web.midominio.com pero no para uno.dos.midominio.com.
                if (str_ends_with($dominio, '.' . $base)) {
                    $prefijo = substr($dominio, 0, -(strlen($base) + 1));

                    if ($prefijo !== '' && !str_contains($prefijo, '.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Nombre del emisor para enseñarlo por pantalla.
     *
     * Se prefiere la unidad organizativa cuando existe: los certificados de
     * origen de Cloudflare NO llevan CN, y todo lo que los identifica esta en
     * OU ("CloudFlare Origin SSL Certificate Authority").
     */
    private static function emisor(array $detalle): string
    {
        $emisor = $detalle['issuer'] ?? [];

        foreach (['OU', 'CN', 'O'] as $campo) {
            if (!empty($emisor[$campo])) {
                return is_array($emisor[$campo]) ? (string) reset($emisor[$campo]) : (string) $emisor[$campo];
            }
        }

        return 'desconocido';
    }

    /**
     * Todo el emisor en una sola linea, para poder buscar dentro.
     */
    private static function emisorCompleto(array $detalle): string
    {
        $partes = [];

        foreach ((array) ($detalle['issuer'] ?? []) as $clave => $valor) {
            foreach ((array) $valor as $uno) {
                $partes[] = $clave . '=' . $uno;
            }
        }

        return strtolower(implode(', ', $partes));
    }

    /**
     * ¿Es un Certificado de Origen de Cloudflare?
     *
     * Estos certificados vienen sin CN y con la pista en OU, asi que hay que
     * mirar el emisor entero. Mirando un solo campo no se reconocian, que era
     * justo lo que pasaba antes.
     */
    private static function esDeOrigenCloudflare(array $detalle): bool
    {
        $emisor = self::emisorCompleto($detalle);

        return str_contains($emisor, 'cloudflare') && str_contains($emisor, 'origin');
    }

    /**
     * Enlace directo al generador de certificados de origen de Cloudflare,
     * ya posicionado en la zona del dominio.
     */
    public static function enlaceCloudflare(string $dominio): string
    {
        return 'https://dash.cloudflare.com/?to=/:account/' . rawurlencode($dominio) . '/ssl-tls/origin';
    }
}
