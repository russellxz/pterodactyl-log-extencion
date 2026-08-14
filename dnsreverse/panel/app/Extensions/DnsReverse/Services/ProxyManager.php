<?php

namespace Pterodactyl\Extensions\DnsReverse\Services;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * El cerebro de la extension: crear, borrar y resincronizar DNS.
 *
 * Todo lo que puede salir mal se comprueba ANTES de tocar nada, y si algo
 * falla a mitad se deshace lo que ya se habia hecho (registro de Cloudflare,
 * configuracion de nginx). Nunca se queda un dominio a medias apuntando a
 * ningun sitio.
 */
class ProxyManager
{
    /**
     * Un nombre de dominio valido, en minusculas y sin protocolo. Se aplica
     * SIEMPRE, tanto al dominio propio del cliente como al subdominio que
     * escribe: el nombre acaba metido en una ruta de archivo y en un archivo
     * de configuracion de nginx dentro del nodo, asi que aqui no puede pasar
     * nada raro.
     */
    private const DOMINIO = '/^(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/';

    /** Solo la parte de la izquierda, la que escribe el cliente. */
    private const ETIQUETA = '/^(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(private Settings $settings)
    {
    }

    // -----------------------------------------------------------------------
    //  Consultas
    // -----------------------------------------------------------------------

    /**
     * DNS ya creados por un servidor.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ProxyRecord>
     */
    public function forServer(Server $server)
    {
        return ProxyRecord::query()
            ->with('allocation')
            ->where('server_id', $server->id)
            ->orderBy('id')
            ->get();
    }

    public function limitFor(Server $server): int
    {
        $limite = $server->getAttribute('proxy_limit');

        if ($limite === null) {
            // La columna no existe (panel sin migrar). Se usa el valor por
            // defecto de la configuracion para no dejar a nadie bloqueado.
            return $this->settings->int('default_proxy_limit', 0, 100);
        }

        return max(0, (int) $limite);
    }

    public function remainingFor(Server $server): int
    {
        return max(0, $this->limitFor($server) - ProxyRecord::where('server_id', $server->id)->count());
    }

    /**
     * Que tipos de DNS admite este servidor, segun el egg.
     *
     * @return array<int, string>
     */
    public function allowedTypes(Server $server): array
    {
        $modo = 'normal';

        try {
            $modo = (string) ($server->egg->getAttribute('proxy_mode') ?? 'normal');
        } catch (\Throwable) {
            // Egg borrado o columna sin migrar: se asume el modo normal.
        }

        return match ($modo) {
            'disabled' => [],
            'srv' => [ProxyRecord::TYPE_SRV],
            'both' => [ProxyRecord::TYPE_DOMAIN, ProxyRecord::TYPE_SUBDOMAIN, ProxyRecord::TYPE_SRV],
            default => [ProxyRecord::TYPE_DOMAIN, ProxyRecord::TYPE_SUBDOMAIN],
        };
    }

    /**
     * Dominios base que el cliente puede elegir.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DnsDomain>
     */
    public function availableDomains()
    {
        return DnsDomain::query()->where('active', true)->orderBy('domain')->get();
    }

    // -----------------------------------------------------------------------
    //  Crear
    // -----------------------------------------------------------------------

    /**
     * @param array{
     *     type: string, name: string, domain_id: ?int, allocation_id: int,
     *     ssl_mode: string, ssl_cert: ?string, ssl_key: ?string
     * } $entrada
     *
     * @throws \RuntimeException con un mensaje pensado para que lo lea el cliente
     */
    public function create(Server $server, User $user, array $entrada): ProxyRecord
    {
        $tipo = (string) ($entrada['type'] ?? ProxyRecord::TYPE_DOMAIN);

        if (!in_array($tipo, $this->allowedTypes($server), true)) {
            throw new \RuntimeException('Este tipo de servidor no admite ese tipo de DNS.');
        }

        if ($this->remainingFor($server) < 1) {
            throw new \RuntimeException(
                'Has llegado al limite de DNS de este servidor. Pide al administrador que te suba el limite.'
            );
        }

        $allocation = $this->resolverAllocation($server, (int) ($entrada['allocation_id'] ?? 0));
        $nodo = $server->node;

        if ($nodo === null) {
            throw new \RuntimeException('Este servidor no tiene nodo asignado.');
        }

        $dominioBase = null;
        $nombre = strtolower(trim((string) ($entrada['name'] ?? '')));

        if ($tipo === ProxyRecord::TYPE_DOMAIN) {
            if (!$this->settings->bool('allow_custom_domains')) {
                throw new \RuntimeException('Ahora mismo no se pueden usar dominios propios, solo subdominios.');
            }

            $dominio = $nombre;

            if (!preg_match(self::DOMINIO, $dominio)) {
                throw new \RuntimeException('Ese dominio no es valido. Escribelo en minusculas y sin http:// ni barras (por ejemplo: mipagina.com).');
            }
        } else {
            $dominioBase = $this->resolverDominioBase((int) ($entrada['domain_id'] ?? 0), $tipo);

            if (!preg_match(self::ETIQUETA, $nombre)) {
                throw new \RuntimeException('El subdominio solo puede llevar letras, numeros y guiones, y no puede empezar ni acabar en guion.');
            }

            if (in_array($nombre, $dominioBase->reservedNames(), true)) {
                throw new \RuntimeException('Ese nombre esta reservado. Elige otro.');
            }

            $dominio = $nombre . '.' . $dominioBase->domain;
        }

        $this->comprobarDominioLibre($dominio);

        [$modoSsl, $cert, $clave] = $this->resolverCertificado($entrada, $dominioBase, $tipo, $dominio);

        $ipDestino = trim((string) $allocation->ip);

        // --- Antes de tocar nada: ¿va a poder resolver este nombre? ---------
        //
        // Esto es lo que fallaba y por lo que el cliente acababa viendo
        // "DNS_PROBE_FINISHED_NXDOMAIN" con todo verde en el panel. Un registro
        // creado en una zona que no esta activa en Cloudflare (los nameservers
        // del dominio no apuntan a Cloudflare) se guarda sin dar ningun error,
        // pero no existe para nadie. Mas vale no crear nada y decirlo claro.

        $avisos = [];
        $cliente = null;

        if ($dominioBase !== null) {
            if ($dominioBase->hasToken()) {
                $cliente = CloudflareClient::for($dominioBase);
                $zona = $cliente->zonaLista();

                if (!$zona['ok']) {
                    throw new \RuntimeException('No se puede crear el DNS ahora mismo. ' . $zona['message']);
                }
            } else {
                // Sin token no se puede crear el registro. Antes se saltaba
                // este paso en silencio y el dominio no resolvia nunca.
                $avisos[] = 'Este dominio no tiene Cloudflare configurado en el panel, asi que el registro DNS no se '
                    . 'ha creado solo. Un administrador tiene que crear a mano un registro A de ' . $dominio
                    . ' apuntando a ' . $ipDestino . ', o el dominio no funcionara.';
            }
        }

        // --- A partir de aqui ya se toca el mundo exterior -----------------
        //
        // EL ORDEN IMPORTA, y mucho. Antes se creaba el registro en Cloudflare
        // y se le pedia el certificado a Let's Encrypt en el mismo instante.
        // Cloudflare acepta el registro al momento pero tarda unos segundos en
        // servirlo, asi que Let's Encrypt llegaba a mirar cuando el nombre
        // todavia no existia y contestaba NXDOMAIN. El certificado no se
        // emitia, y como el fallo tumbaba toda la operacion, hasta el registro
        // DNS se borraba: el cliente se quedaba sin nada.
        //
        // Ahora va por pasos, y cada uno se apoya en el anterior:
        //   1. registro en Cloudflare, y se vuelve a leer para confirmarlo
        //   2. el sitio se monta en el nodo por http (sin certificado)
        //   3. se espera a que el nombre resuelva DE VERDAD
        //   4. y solo entonces se pide el certificado
        //
        // Si el certificado falla, el dominio se queda funcionando por http y
        // se reintenta luego. Nunca se pierde el trabajo hecho.

        $registroCf = null;
        $registroSrv = null;
        $avisoCertificado = null;
        $modoGuardado = $modoSsl;
        $resuelve = null;

        try {
            // --- 1. Cloudflare ---
            if ($cliente !== null) {
                $naranja = $tipo === ProxyRecord::TYPE_SRV
                    // Con SRV la nube tiene que ser gris SIEMPRE: Cloudflare no
                    // sabe hablar el protocolo de Minecraft.
                    ? false
                    : $dominioBase->shouldProxy($modoSsl);

                $creado = $cliente->ensureAddressRecord($dominio, $ipDestino, $naranja);
                $registroCf = $creado['id'];

                if ($creado['aviso'] !== null) {
                    $avisos[] = $creado['aviso'];
                }

                if ($tipo === ProxyRecord::TYPE_SRV) {
                    $registroSrv = $cliente->createMinecraftSrv($nombre, $dominio, (int) $allocation->port);
                }

                // Se vuelve a leer de Cloudflare: si lo que hay puesto no es lo
                // que se pidio, se para aqui en vez de dejar al cliente con un
                // dominio que apunta a cualquier sitio.
                $comprobacion = $cliente->verificarRegistro($registroCf, $dominio, $ipDestino);

                if (!$comprobacion['ok']) {
                    throw new \RuntimeException('Cloudflare acepto el registro pero al releerlo no cuadra: ' . $comprobacion['message']);
                }
            }

            $wings = WingsClient::for($nodo);

            $peticion = [
                'domain' => $dominio,
                'ip' => $ipDestino,
                'port' => (int) $allocation->port,
                'ssl' => false,
                'mode' => ProxyRecord::SSL_NONE,
                'email' => (string) $user->email,
                'cert' => '',
                'key' => '',
                'uuid' => (string) $server->uuid,
            ];

            // --- 2. El sitio, primero sin certificado ---
            //
            // Con Let's Encrypt esto ademas es imprescindible: la comprobacion
            // del certificado entra por el puerto 80 de este mismo sitio.
            if ($modoSsl === ProxyRecord::SSL_LETSENCRYPT) {
                $wings->createProxy($peticion);

                // --- 3. Esperar a que el nombre exista de verdad ---
                if ($registroCf !== null) {
                    $espera = DomainChecker::esperarResolucion($dominio, 90);
                    $resuelve = $espera['ok'];

                    DnsEvent::record($espera['ok'] ? 'info' : 'warning', 'dns.wait',
                        'Espera a que resuelva el DNS: ' . $espera['detalle'],
                        ['segundos' => $espera['segundos']], $dominio, (int) $server->id, (int) $user->id);

                    if (!$espera['ok']) {
                        $avisoCertificado = 'El dominio ya esta creado y funcionando por http, pero todavia no se ve '
                            . 'desde fuera, asi que no se ha podido pedir el certificado. Es normal que tarde unos '
                            . 'minutos la primera vez. Se reintentara solo esta madrugada.';
                    }
                }

                // --- 4. Y ahora si, el certificado ---
                if ($avisoCertificado === null) {
                    try {
                        $wings->createProxy(array_merge($peticion, [
                            'ssl' => true,
                            'mode' => ProxyRecord::SSL_LETSENCRYPT,
                        ]));
                    } catch (\Throwable $e) {
                        // El certificado es lo unico que ha fallado. El dominio
                        // funciona por http, asi que NO se tira todo abajo.
                        $avisoCertificado = 'Tu dominio ya funciona, pero el certificado no se ha podido emitir todavia: '
                            . $e->getMessage();
                    }
                }

                if ($avisoCertificado !== null) {
                    // Queda pendiente: la tarea de las madrugadas lo reintenta.
                    $modoGuardado = ProxyRecord::SSL_LETSENCRYPT;
                }
            } else {
                // Sin certificado, o con certificado de origen: de una vez.
                $wings->createProxy(array_merge($peticion, [
                    'ssl' => $modoSsl !== ProxyRecord::SSL_NONE,
                    'mode' => $modoSsl,
                    'cert' => $cert,
                    'key' => $clave,
                ]));

                // Aqui no hace falta esperar para nada tecnico, pero si para
                // poder decirle la verdad al cliente: si el nombre todavia no
                // resuelve, su pagina no va a cargar y tiene que saberlo ahora,
                // no descubrirlo el solo con un error del navegador.
                if ($registroCf !== null) {
                    $espera = DomainChecker::esperarResolucion($dominio, 30);
                    $resuelve = $espera['ok'];

                    if (!$espera['ok']) {
                        $avisos[] = 'El dominio esta creado y el servidor ya lo esta esperando, pero todavia no se ve '
                            . 'desde fuera (' . $espera['detalle'] . '). Suele tardar unos minutos la primera vez; '
                            . 'si dentro de un rato sigue igual, avisa al administrador.';
                    }
                }
            }

            // Dominio propio del cliente: aqui el registro DNS no lo creamos
            // nosotros, lo tiene que crear el en su proveedor. Se comprueba y
            // se le dice exactamente que le falta.
            if ($tipo === ProxyRecord::TYPE_DOMAIN) {
                $apunta = DomainChecker::apuntaA($dominio, $ipDestino);
                $resuelve = $apunta['resuelve'];

                if (!$apunta['ok']) {
                    $avisos[] = $apunta['mensaje'];
                }
            }
        } catch (\Throwable $e) {
            // Se deshace lo que si se habia llegado a crear.
            if ($cliente !== null) {
                foreach ([$registroSrv, $registroCf] as $id) {
                    if (is_string($id) && $id !== '') {
                        $cliente->deleteRecord($id);
                    }
                }
            }

            DnsEvent::record('error', 'create', $e->getMessage(), [
                'tipo' => $tipo,
                'modo_ssl' => $modoSsl,
            ], $dominio, (int) $server->id, (int) $user->id);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if ($avisoCertificado !== null) {
            array_unshift($avisos, $avisoCertificado);
        }

        $registro = new ProxyRecord();
        $registro->fill([
            'server_id' => $server->id,
            'domain' => $dominio,
            'proxy_type' => $tipo,
            'base_domain' => $dominioBase?->domain,
            'cf_record_id' => $registroSrv !== null ? ($registroCf . ',' . $registroSrv) : $registroCf,
            'allocation_id' => $allocation->id,
            // Si el certificado quedo pendiente, el sitio va por http de
            // momento: ssl_enabled a false para que nginx no apunte a un
            // certificado que no existe.
            'ssl_enabled' => $modoSsl !== ProxyRecord::SSL_NONE && $avisoCertificado === null,
            'ssl_mode' => $modoGuardado,
            'domain_id' => $dominioBase?->id,
            'created_by' => $user->id,
            'synced_at' => now(),
            // Let's Encrypt emite siempre a 90 dias. Antes esto solo se
            // apuntaba al renovar, asi que un certificado recien emitido
            // aparecia en el panel sin fecha de caducidad.
            'cert_expires_at' => $modoGuardado === ProxyRecord::SSL_LETSENCRYPT && $avisoCertificado === null
                ? now()->addDays(90)
                : null,
            'last_error' => $avisos === [] ? null : implode(' ', $avisos),
        ]);

        // El certificado del cliente se guarda con el DNS (cifrado), no solo en
        // el nodo. Si el nodo se reinstala, la resincronizacion puede volver a
        // ponerlo; antes se perdia y el dominio se quedaba sin certificado sin
        // que nadie pudiera recuperarlo desde el panel.
        if ($modoSsl === ProxyRecord::SSL_ORIGIN && $tipo === ProxyRecord::TYPE_DOMAIN) {
            $registro->guardarCertificado($cert, $clave);
        }

        $registro->save();

        DnsEvent::record($avisos === [] ? 'info' : 'warning', 'create',
            $avisos === [] ? 'DNS creado' : 'DNS creado con avisos: ' . implode(' ', $avisos), [
                'tipo' => $tipo,
                'modo_ssl' => $modoGuardado,
                'destino' => $allocation->ip . ':' . $allocation->port,
                'nodo' => $nodo->name,
                'resuelve' => $resuelve,
            ], $dominio, (int) $server->id, (int) $user->id);

        $this->registrarActividadDelPanel('server:dnsreverse.create', $server, [
            'domain' => $dominio,
            'type' => $tipo,
            'ssl' => $modoSsl,
        ]);

        return $registro->fresh(['allocation']);
    }

    /**
     * Vuelve a intentar el certificado de un dominio que se quedo a medias.
     *
     * Pasa cuando el certificado no se pudo emitir en el momento de crear el
     * dominio (casi siempre porque el DNS aun no se veia desde fuera). El
     * dominio esta funcionando por http y aqui se le pone el candado.
     *
     * @return array{ok: bool, message: string}
     */
    public function reintentarCertificado(ProxyRecord $registro): array
    {
        $allocation = $registro->allocation;
        $servidor = $registro->server;
        $nodo = $allocation?->node ?? $servidor?->node;

        if ($allocation === null || $nodo === null) {
            return ['ok' => false, 'message' => 'Sin asignacion o sin nodo.'];
        }

        $espera = DomainChecker::esperarResolucion((string) $registro->domain, 30);

        if (!$espera['ok']) {
            $registro->forceFill(['last_error' => 'El dominio sigue sin verse desde fuera. ' . $espera['detalle']])->save();

            return ['ok' => false, 'message' => 'Todavia no resuelve: ' . $espera['detalle']];
        }

        $correo = '';

        try {
            $correo = (string) ($servidor?->user?->email ?? '');
        } catch (\Throwable) {
            // Dueño borrado: wings usa la cuenta ACME que ya tiene el nodo.
        }

        try {
            WingsClient::for($nodo)->createProxy([
                'domain' => (string) $registro->domain,
                'ip' => trim((string) $allocation->ip),
                'port' => (int) $allocation->port,
                'ssl' => true,
                'mode' => ProxyRecord::SSL_LETSENCRYPT,
                'email' => $correo,
                'cert' => '',
                'key' => '',
                'uuid' => $servidor?->uuid,
            ]);
        } catch (\Throwable $e) {
            $registro->forceFill(['last_error' => $e->getMessage()])->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $registro->forceFill([
            'ssl_enabled' => true,
            'ssl_mode' => ProxyRecord::SSL_LETSENCRYPT,
            'cert_expires_at' => now()->addDays(90),
            'last_error' => null,
            'synced_at' => now(),
        ])->save();

        DnsEvent::record('info', 'cert.retry', 'Certificado emitido en el reintento', [], (string) $registro->domain, $registro->server_id);

        return ['ok' => true, 'message' => 'Certificado emitido.'];
    }

    /**
     * Vuelve a poner en Cloudflare el registro de un DNS que ya existe en el
     * panel.
     *
     * Es la reparacion de lo que se creo mientras la extension se saltaba
     * Cloudflare en silencio (dominio sin token, zona sin activar o registro
     * borrado a mano). El DNS sigue en el panel y el sitio sigue montado en el
     * nodo; lo unico que falta es el registro, y eso es lo que se arregla aqui.
     *
     * No toca nada del nodo ni del certificado: solo el DNS.
     *
     * @return array{ok: bool, cambiado: bool, message: string}
     */
    public function repararDns(ProxyRecord $registro): array
    {
        if ($registro->proxy_type === ProxyRecord::TYPE_DOMAIN) {
            return [
                'ok' => true,
                'cambiado' => false,
                'message' => 'Es un dominio propio del cliente: el registro lo tiene que crear el en su proveedor.',
            ];
        }

        $dominioBase = $this->dominioDeRegistro($registro);

        if ($dominioBase === null) {
            return ['ok' => false, 'cambiado' => false, 'message' => 'Este DNS no esta enganchado a ningun dominio del panel.'];
        }

        if (!$dominioBase->hasToken()) {
            return [
                'ok' => false,
                'cambiado' => false,
                'message' => 'El dominio ' . $dominioBase->domain . ' no tiene token de Cloudflare. Ponlo en la pestana Dominios.',
            ];
        }

        $allocation = $registro->allocation;

        if ($allocation === null) {
            return ['ok' => false, 'cambiado' => false, 'message' => 'Este DNS no tiene puerto asignado.'];
        }

        $cliente = CloudflareClient::for($dominioBase);
        $zona = $cliente->zonaLista();

        if (!$zona['ok']) {
            $registro->forceFill(['last_error' => $zona['message']])->save();

            return ['ok' => false, 'cambiado' => false, 'message' => $zona['message']];
        }

        $ip = trim((string) $allocation->ip);
        $naranja = $registro->proxy_type === ProxyRecord::TYPE_SRV
            ? false
            : $dominioBase->shouldProxy((string) $registro->ssl_mode);

        try {
            $resultado = $cliente->ensureAddressRecord((string) $registro->domain, $ip, $naranja);
            $identificadores = [$resultado['id']];

            if ($registro->proxy_type === ProxyRecord::TYPE_SRV) {
                $etiqueta = explode('.', (string) $registro->domain)[0] ?? '';

                if ($etiqueta !== '') {
                    $identificadores[] = $cliente->createMinecraftSrv(
                        $etiqueta,
                        (string) $registro->domain,
                        (int) $allocation->port
                    );
                }
            }

            $comprobacion = $cliente->verificarRegistro($resultado['id'], (string) $registro->domain, $ip);

            if (!$comprobacion['ok']) {
                $registro->forceFill(['last_error' => $comprobacion['message']])->save();

                return ['ok' => false, 'cambiado' => false, 'message' => $comprobacion['message']];
            }
        } catch (\Throwable $e) {
            $registro->forceFill(['last_error' => $e->getMessage()])->save();

            return ['ok' => false, 'cambiado' => false, 'message' => $e->getMessage()];
        }

        $registro->forceFill([
            'cf_record_id' => implode(',', $identificadores),
            'last_error' => null,
            'synced_at' => now(),
        ])->save();

        DnsEvent::record('info', 'dns.repair',
            $resultado['creado'] ? 'Registro DNS creado de nuevo en Cloudflare' : 'Registro DNS corregido en Cloudflare',
            ['ip' => $ip, 'naranja' => $naranja], (string) $registro->domain, $registro->server_id);

        return [
            'ok' => true,
            'cambiado' => true,
            'message' => $resultado['creado']
                ? 'Registro creado en Cloudflare apuntando a ' . $ip . '.'
                : 'El registro ya estaba y se ha dejado apuntando a ' . $ip . '.',
        ];
    }

    /**
     * DNS a los que les falta el certificado.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ProxyRecord>
     */
    public function certificadosPendientes()
    {
        return ProxyRecord::query()
            ->with(['server', 'allocation.node'])
            ->where('ssl_mode', ProxyRecord::SSL_LETSENCRYPT)
            ->where('ssl_enabled', false)
            ->get();
    }

    // -----------------------------------------------------------------------
    //  Borrar
    // -----------------------------------------------------------------------

    /**
     * Borra un DNS: registro de Cloudflare, configuracion del nodo y fila.
     *
     * Si el nodo no responde se borra igualmente del panel y se avisa: dejar
     * la fila colgada solo consigue que el cliente no pueda volver a crear el
     * dominio nunca.
     *
     * @return array<int, string> avisos para enseñar a quien lo borro
     */
    public function delete(ProxyRecord $registro, ?User $user = null): array
    {
        $avisos = [];
        $servidor = $registro->server;
        $allocation = $registro->allocation;

        // --- Cloudflare ---
        $dominioBase = $this->dominioDeRegistro($registro);

        if ($dominioBase !== null && $dominioBase->hasToken() && !empty($registro->cf_record_id)) {
            $cliente = CloudflareClient::for($dominioBase);

            foreach (explode(',', (string) $registro->cf_record_id) as $id) {
                $id = trim($id);

                if ($id !== '' && !$cliente->deleteRecord($id)) {
                    $avisos[] = 'No se pudo borrar un registro en Cloudflare. Revisalo a mano si sigue apareciendo.';
                }
            }
        }

        // --- Nodo ---
        $nodo = $allocation?->node ?? $servidor?->node;

        if ($nodo !== null && $allocation !== null) {
            $borrado = WingsClient::for($nodo)->deleteProxy(
                (string) $registro->domain,
                (int) $allocation->port,
                $servidor?->uuid
            );

            if (!$borrado) {
                $avisos[] = 'El nodo no confirmo el borrado de la configuracion. Si el dominio sigue respondiendo, avisa al administrador.';
            }
        }

        DnsEvent::record('info', 'delete', 'DNS borrado', [
            'avisos' => $avisos,
        ], (string) $registro->domain, $registro->server_id, $user?->id);

        if ($servidor !== null) {
            $this->registrarActividadDelPanel('server:dnsreverse.delete', $servidor, [
                'domain' => (string) $registro->domain,
            ]);
        }

        $registro->delete();

        return $avisos;
    }

    // -----------------------------------------------------------------------
    //  Resincronizar
    // -----------------------------------------------------------------------

    /**
     * Vuelve a mandar al nodo la configuracion de un DNS ya guardado.
     *
     * Es lo que se usa cuando se reinstala un nodo, cuando se reconstruye
     * wings o despues de actualizar el panel: la base de datos manda, y el
     * nodo se pone al dia sin que ningun cliente tenga que volver a crear
     * nada. Los certificados que sigan siendo validos se reutilizan, asi que
     * no se gasta cupo de Let's Encrypt.
     *
     * @return array{ok: bool, message: string}
     */
    public function sync(ProxyRecord $registro): array
    {
        $allocation = $registro->allocation;
        $servidor = $registro->server;
        $nodo = $allocation?->node ?? $servidor?->node;

        if ($allocation === null || $nodo === null) {
            $mensaje = 'Sin asignacion o sin nodo: este DNS esta huerfano.';
            $registro->forceFill(['last_error' => $mensaje])->save();

            return ['ok' => false, 'message' => $mensaje];
        }

        $dominioBase = $this->dominioDeRegistro($registro);
        $modo = (string) $registro->ssl_mode;
        $cert = '';
        $clave = '';

        if ($modo === ProxyRecord::SSL_ORIGIN) {
            // Primero el certificado propio del cliente (dominio suyo), que es
            // el unico que sirve para ese dominio. Si no tiene, el comodin del
            // dominio de la casa.
            [$cert, $clave] = $registro->certificadoGuardado();

            if (($cert === '' || $clave === '') && $dominioBase !== null) {
                $cert = (string) $dominioBase->ssl_cert;
                $clave = (string) $dominioBase->ssl_key;
            }
        }

        if ($modo === ProxyRecord::SSL_LEGACY) {
            // No sabemos con que se genero. El nodo reutiliza el certificado
            // que ya tiene en disco, que es lo correcto: se conserva tal cual.
            $modo = $registro->ssl_enabled ? ProxyRecord::SSL_LETSENCRYPT : ProxyRecord::SSL_NONE;

            [$certGuardado, $claveGuardada] = $registro->certificadoGuardado();

            if ($certGuardado !== '' && $claveGuardada !== '') {
                $modo = ProxyRecord::SSL_ORIGIN;
                $cert = $certGuardado;
                $clave = $claveGuardada;
            } elseif ($dominioBase !== null && $dominioBase->hasOriginCertificate()) {
                $modo = ProxyRecord::SSL_ORIGIN;
                $cert = (string) $dominioBase->ssl_cert;
                $clave = (string) $dominioBase->ssl_key;
            }
        }

        $correo = '';

        try {
            $correo = (string) ($servidor?->user?->email ?? '');
        } catch (\Throwable) {
            // Dueño borrado: se deja vacio y wings usa la cuenta ACME que ya
            // tiene registrada en el nodo.
        }

        try {
            WingsClient::for($nodo)->createProxy([
                'domain' => (string) $registro->domain,
                'ip' => trim((string) $allocation->ip),
                'port' => (int) $allocation->port,
                'ssl' => (bool) $registro->ssl_enabled,
                'mode' => $modo,
                'email' => $correo,
                'cert' => $cert,
                'key' => $clave,
                'uuid' => $servidor?->uuid,
            ]);
        } catch (\Throwable $e) {
            $registro->forceFill(['last_error' => $e->getMessage()])->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $registro->forceFill(['synced_at' => now(), 'last_error' => null])->save();

        return ['ok' => true, 'message' => 'Configuracion enviada al nodo ' . $nodo->name . '.'];
    }

    // -----------------------------------------------------------------------
    //  Utilidades internas
    // -----------------------------------------------------------------------

    private function resolverAllocation(Server $server, int $id): Allocation
    {
        $allocation = Allocation::query()
            ->where('id', $id)
            ->where('server_id', $server->id)
            ->first();

        if ($allocation === null) {
            throw new \RuntimeException('El puerto elegido no pertenece a este servidor.');
        }

        return $allocation;
    }

    private function resolverDominioBase(int $id, string $tipo): DnsDomain
    {
        $dominio = DnsDomain::query()->where('id', $id)->where('active', true)->first();

        if ($dominio === null) {
            throw new \RuntimeException('Ese dominio no esta disponible. Recarga la pagina y vuelve a intentarlo.');
        }

        if ($tipo === ProxyRecord::TYPE_SRV && !$dominio->allow_srv) {
            throw new \RuntimeException('Ese dominio no admite registros SRV de Minecraft.');
        }

        if ($tipo === ProxyRecord::TYPE_SUBDOMAIN && !$dominio->allow_subdomain) {
            throw new \RuntimeException('Ese dominio no admite subdominios ahora mismo.');
        }

        return $dominio;
    }

    private function comprobarDominioLibre(string $dominio): void
    {
        if (strlen($dominio) > 253) {
            throw new \RuntimeException('Ese dominio es demasiado largo.');
        }

        if (in_array($dominio, $this->settings->blockedDomains(), true)) {
            throw new \RuntimeException('Ese dominio no se puede usar.');
        }

        if (ProxyRecord::query()->where('domain', $dominio)->exists()) {
            throw new \RuntimeException('Ese dominio ya esta en uso. Elige otro.');
        }
    }

    /**
     * Decide con que certificado se va a servir el dominio.
     *
     * @return array{0: string, 1: string, 2: string} modo, certificado, clave
     */
    private function resolverCertificado(array $entrada, ?DnsDomain $dominioBase, string $tipo, string $dominioCompleto = ''): array
    {
        $modo = (string) ($entrada['ssl_mode'] ?? ProxyRecord::SSL_NONE);

        if (!in_array($modo, [ProxyRecord::SSL_NONE, ProxyRecord::SSL_ORIGIN, ProxyRecord::SSL_LETSENCRYPT], true)) {
            throw new \RuntimeException('Opcion de certificado no valida.');
        }

        if ($modo === ProxyRecord::SSL_NONE) {
            return [ProxyRecord::SSL_NONE, '', ''];
        }

        if ($modo === ProxyRecord::SSL_LETSENCRYPT) {
            if (!$this->settings->bool('letsencrypt_enabled')) {
                throw new \RuntimeException('Los certificados automaticos estan desactivados en este panel.');
            }

            if ($dominioBase !== null && !$dominioBase->allow_letsencrypt) {
                throw new \RuntimeException('Ese dominio no admite certificados automaticos. Usa el certificado de origen.');
            }

            if ($tipo === ProxyRecord::TYPE_SRV) {
                throw new \RuntimeException('Un registro SRV de Minecraft no lleva certificado web.');
            }

            return [ProxyRecord::SSL_LETSENCRYPT, '', ''];
        }

        // --- Certificado de origen ---
        $cert = trim((string) ($entrada['ssl_cert'] ?? ''));
        $clave = trim((string) ($entrada['ssl_key'] ?? ''));

        // Para subdominios de la casa se usa el certificado del dominio, que
        // es lo normal: el administrador pone un comodin *.dominio.com una vez
        // y todos los clientes lo aprovechan sin ver ni tocar la clave.
        if ($cert === '' && $dominioBase !== null && $dominioBase->hasOriginCertificate()) {
            $cert = (string) $dominioBase->ssl_cert;
            $clave = (string) $dominioBase->ssl_key;
        }

        if ($cert === '' || $clave === '') {
            throw new \RuntimeException(
                'Falta el certificado de origen. Pegalo entero (certificado y clave privada) o elige el certificado automatico.'
            );
        }

        // Se revisa de verdad, no solo que "tenga pinta": que la clave sea la
        // de ese certificado, que no este caducado y que sirva para el dominio
        // que se esta creando. Si no, el fallo aparece cuando el cliente entra
        // a su pagina y nadie sabe de donde viene.
        $revision = CertificateInspector::revisar($cert, $clave, $dominioCompleto);

        if (!$revision['ok']) {
            throw new \RuntimeException(implode(' ', $revision['errores']));
        }

        return [ProxyRecord::SSL_ORIGIN, $cert, $clave];
    }

    private function dominioDeRegistro(ProxyRecord $registro): ?DnsDomain
    {
        if ($registro->domain_id) {
            $dominio = DnsDomain::find($registro->domain_id);

            if ($dominio !== null) {
                return $dominio;
            }
        }

        if (!empty($registro->base_domain)) {
            return DnsDomain::query()->where('domain', $registro->base_domain)->first();
        }

        return null;
    }

    /**
     * Deja constancia en la actividad del propio panel, que es donde el
     * cliente y el administrador ya miran. Si el panel cambiara esa API en
     * una version futura, la extension sigue funcionando igual.
     */
    private function registrarActividadDelPanel(string $evento, Server $server, array $propiedades): void
    {
        try {
            if (!class_exists(\Pterodactyl\Facades\Activity::class)) {
                return;
            }

            \Pterodactyl\Facades\Activity::event($evento)
                ->subject($server)
                ->property($propiedades)
                ->log();
        } catch (\Throwable) {
            // La actividad del panel es un extra, no puede tumbar la operacion.
        }
    }

    /**
     * Cuenta cuantos DNS hay en cada estado. Para el resumen del admin.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $porTipo = ProxyRecord::query()
            ->select('proxy_type', DB::raw('COUNT(*) as total'))
            ->groupBy('proxy_type')
            ->pluck('total', 'proxy_type')
            ->all();

        return [
            'total' => (int) array_sum($porTipo),
            'domains' => (int) ($porTipo[ProxyRecord::TYPE_DOMAIN] ?? 0),
            'subdomains' => (int) ($porTipo[ProxyRecord::TYPE_SUBDOMAIN] ?? 0),
            'srv' => (int) ($porTipo[ProxyRecord::TYPE_SRV] ?? 0),
            'orphans' => $this->orphanQuery()->count(),
            'base_domains' => DnsDomain::count(),
            'ssl' => ProxyRecord::where('ssl_enabled', true)->count(),
        ];
    }

    /**
     * DNS cuyo servidor ya no existe en el panel.
     */
    public function orphanQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return ProxyRecord::query()->whereNotExists(function ($consulta) {
            $consulta->select(DB::raw(1))
                ->from('servers')
                ->whereColumn('servers.id', 'server_proxy.server_id');
        });
    }
}
