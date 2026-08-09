<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\NodeAccess;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * Conexion SSH a los nodos para soltar el bloqueo de instalacion de wings.
 *
 * Por que hace falta: wings guarda en su memoria un "estoy instalando" por
 * servidor y no lo suelta hasta que el contenedor de instalacion termina. Si
 * ese contenedor se queda colgado, el bloqueo no se suelta nunca y wings
 * rechaza cualquier instalacion nueva de ese servidor. Su API no tiene ninguna
 * orden para cancelarlo, asi que la unica salida es entrar en la maquina y
 * matar el contenedor. Eso es exactamente lo que se hace aqui.
 *
 * Sobre la seguridad, tres decisiones que conviene conocer:
 *
 *  - Se usa phpseclib, que ya viene con Pterodactyl. Nada de exec(), ni de
 *    sshpass, ni de dejar contrasenas en la linea de comandos (donde las ve
 *    cualquiera con un `ps`).
 *  - Los comandos NO se escriben desde la pantalla. Solo se pueden lanzar los
 *    de la lista de aqui abajo, y el unico dato variable es el uuid de un
 *    servidor, que se valida antes. Una extension del panel no tiene por que
 *    ser tambien una consola remota.
 *  - La huella de la maquina se guarda y se comprueba en cada conexion. Si
 *    cambia, no se conecta: hay que confirmarlo a mano. Y cuando reinstalas la
 *    VPS se olvida con un boton, que es el equivalente al ssh-keygen -R de
 *    toda la vida.
 */
class NodeSsh
{
    private const TIMEOUT = 15;

    /**
     * ¿Se puede usar esto en este panel?
     */
    public function available(): bool
    {
        return class_exists(SSH2::class);
    }

    /**
     * Comprueba que se puede entrar y que docker responde.
     *
     * @return array{ok: bool, salida: string, error: ?string, huella: ?string, huella_nueva: bool}
     */
    public function test(NodeAccess $access, bool $trustNewKey = false): array
    {
        return $this->run($access, 'id -un; docker version --format "docker {{.Server.Version}}" 2>&1', $trustNewKey);
    }

    /**
     * ¿Sigue vivo el contenedor de instalacion de este servidor?
     *
     * @return array{ok: bool, existe: bool, detalle: string, error: ?string}
     */
    public function installerStatus(NodeAccess $access, string $uuid): array
    {
        $nombre = $this->containerName($uuid);

        $resultado = $this->run(
            $access,
            'docker ps -a --filter name=^' . $nombre . '$ --format "{{.Status}} ({{.Image}})" 2>&1'
        );

        $salida = trim($resultado['salida']);

        return [
            'ok' => $resultado['ok'],
            'existe' => $resultado['ok'] && $salida !== '',
            'detalle' => $salida,
            'error' => $resultado['error'],
        ];
    }

    /**
     * Mata el contenedor de instalacion colgado.
     *
     * Solo mata ESE contenedor. Los archivos del servidor viven en otro sitio
     * (/var/lib/pterodactyl/volumes) y no se tocan.
     *
     * @return array{ok: bool, salida: string, error: ?string}
     */
    public function killInstaller(NodeAccess $access, string $uuid): array
    {
        $nombre = $this->containerName($uuid);
        $resultado = $this->run($access, 'docker rm -f ' . $nombre . ' 2>&1');

        ExtensionEvent::log(
            $resultado['ok'] ? 'info' : 'error',
            'nodos',
            $resultado['ok']
                ? 'Contenedor de instalacion eliminado en el nodo'
                : 'No se pudo eliminar el contenedor de instalacion en el nodo',
            [
                'host' => $access->host,
                'contenedor' => $nombre,
                'salida' => mb_substr(trim($resultado['salida']), 0, 300),
                'error' => $resultado['error'],
            ]
        );

        return $resultado;
    }

    /**
     * Reinicia wings. Es el martillo grande: suelta todos los bloqueos de esa
     * maquina, pero corta un momento la consola de todos sus servidores (los
     * servidores en si no se paran).
     *
     * @return array{ok: bool, salida: string, error: ?string}
     */
    public function restartWings(NodeAccess $access): array
    {
        $resultado = $this->run($access, 'systemctl restart wings && sleep 2 && systemctl is-active wings 2>&1');

        ExtensionEvent::log(
            $resultado['ok'] ? 'warning' : 'error',
            'nodos',
            $resultado['ok'] ? 'Wings reiniciado desde la extension' : 'No se pudo reiniciar wings',
            ['host' => $access->host, 'salida' => mb_substr(trim($resultado['salida']), 0, 300)]
        );

        return $resultado;
    }

    /**
     * Nombre del contenedor de instalacion, tal y como lo crea wings:
     * server/install.go -> ip.Server.ID() + "_installer".
     *
     * El uuid se valida a conciencia porque es lo unico que viaja desde la
     * peticion hasta una orden remota.
     */
    private function containerName(string $uuid): string
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            throw new \InvalidArgumentException('El identificador del servidor no es un uuid valido.');
        }

        return strtolower($uuid) . '_installer';
    }

    /**
     * Abre la conexion, comprueba la huella, ejecuta y cierra.
     *
     * @return array{ok: bool, salida: string, error: ?string, huella: ?string, huella_nueva: bool}
     */
    private function run(NodeAccess $access, string $comando, bool $trustNewKey = false): array
    {
        $respuesta = ['ok' => false, 'salida' => '', 'error' => null, 'huella' => null, 'huella_nueva' => false];

        if (!$this->available()) {
            $respuesta['error'] = 'Falta phpseclib en el panel. Ejecuta "composer install" en la carpeta del panel.';

            return $respuesta;
        }

        if (!$access->enabled) {
            $respuesta['error'] = 'El acceso a este nodo esta desactivado.';

            return $respuesta;
        }

        $ssh = null;

        try {
            $ssh = new SSH2($access->host, $access->port ?: 22, self::TIMEOUT);
            $ssh->setTimeout(self::TIMEOUT);

            // 1) La huella, antes de mandar ninguna credencial.
            $huella = $this->fingerprintOf($ssh);
            $respuesta['huella'] = $huella;

            if ($huella === null) {
                throw new \RuntimeException('No se pudo leer la clave de la maquina. ¿Responde ese host y ese puerto?');
            }

            $guardada = (string) $access->fingerprint;

            if ($guardada !== '' && !hash_equals($guardada, $huella)) {
                $respuesta['huella_nueva'] = true;

                if (!$trustNewKey) {
                    throw new \RuntimeException(
                        'La maquina ha cambiado de clave (huella ' . $huella . ', antes ' . $guardada . '). '
                        . 'Si has reinstalado la VPS, pulsa "Olvidar huella" y vuelve a probar. '
                        . 'Si no la has tocado, NO confies: alguien podria estar suplantandola.'
                    );
                }
            }

            // 2) Autenticacion.
            if ($access->usesKey()) {
                try {
                    $credencial = PublicKeyLoader::load($access->plainSecret(), $access->plainPassphrase() ?? false);
                } catch (\Throwable $e) {
                    // El "Unable to read key" pelado de phpseclib no le dice
                    // nada a nadie, y casi siempre es una de estas dos cosas.
                    throw new \RuntimeException(
                        'No se pudo leer la clave privada. Comprueba que la pegaste entera (con las lineas '
                        . 'BEGIN y END) y que la contrasena de la clave es la correcta.'
                    );
                }
            } else {
                $credencial = $access->plainSecret();
            }

            if (!$ssh->login($access->username, $credencial)) {
                throw new \RuntimeException(
                    'Usuario o ' . ($access->usesKey() ? 'clave privada' : 'contrasena') . ' incorrectos.'
                );
            }

            // 3) La orden, que siempre sale de esta clase y nunca de la pantalla.
            $salida = (string) $ssh->exec($comando);
            $codigo = $ssh->getExitStatus();

            $respuesta['salida'] = $salida;
            $respuesta['ok'] = $codigo === 0 || $codigo === false;

            if (!$respuesta['ok']) {
                $respuesta['error'] = 'El comando termino con codigo ' . $codigo . '.';
            }

            $access->forceFill([
                'fingerprint' => $huella,
                'last_checked_at' => now(),
                'last_ok_at' => $respuesta['ok'] ? now() : $access->last_ok_at,
                'last_error' => $respuesta['ok'] ? null : mb_substr(trim($salida) ?: (string) $respuesta['error'], 0, 500),
            ])->save();
        } catch (\Throwable $e) {
            $respuesta['error'] = mb_substr($e->getMessage(), 0, 500);

            try {
                $access->forceFill([
                    'last_checked_at' => now(),
                    'last_error' => $respuesta['error'],
                ])->save();
            } catch (\Throwable) {
                // Si ni siquiera se puede anotar el fallo, se devuelve y ya.
            }
        } finally {
            try {
                $ssh?->disconnect();
            } catch (\Throwable) {
                // Cerrar una conexion que ya se cayo no es un problema.
            }
        }

        return $respuesta;
    }

    /**
     * Huella de la maquina en el mismo formato que ensena OpenSSH
     * (SHA256:xxxx), para poder compararla con lo que uno ve por consola.
     */
    private function fingerprintOf(SSH2 $ssh): ?string
    {
        $clave = $ssh->getServerPublicHostKey();

        if (!is_string($clave) || $clave === '') {
            return null;
        }

        // phpseclib devuelve "ssh-rsa AAAAB3..."; la huella se calcula sobre
        // la parte binaria, igual que hace ssh-keygen -l.
        $partes = explode(' ', $clave);
        $binaria = base64_decode($partes[1] ?? '', true);

        if ($binaria === false) {
            return null;
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $binaria, true)), '=');
    }
}
