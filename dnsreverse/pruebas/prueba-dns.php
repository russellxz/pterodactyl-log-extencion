<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\CloudflareClient;

$fallos = 0;
function comprobar(bool $c, string $t)
{
    global $fallos;
    if (!$c) {
        $fallos++;
    }
    printf("  [%s] %s\n", $c ? 'OK ' : 'MAL', $t);
}

function reiniciarHttp(): void
{
    // Http::fake() SUMA reglas a las que ya hubiera. Entre bloque y bloque hay
    // que empezar de cero o siguen contestando las respuestas del anterior.
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
}

function dominio(array $extra = []): DnsDomain
{
    DB::table('dnsreverse_domains')->truncate();
    $d = new DnsDomain();
    $d->fill(array_merge([
        'domain' => 'ultraplus.click',
        'proxied_mode' => 'auto',
        'active' => true,
    ], $extra));
    $d->setToken('token-de-prueba');
    $d->save();

    return $d->fresh();
}

$ZONA = 'ffffffffffffffffffffffffffffffff';

echo "\n=== 1. ZONA SIN ACTIVAR: LA CAUSA DEL \"NO SE PUEDE ACCEDER A ESTE SITIO\" ===\n";
echo "    (los nameservers del dominio todavia no apuntan a Cloudflare)\n\n";

reiniciarHttp();
Http::fake([
    'api.cloudflare.com/client/v4/zones?name=*' => Http::response(['success' => true, 'result' => [[
        'id' => $ZONA, 'name' => 'ultraplus.click', 'status' => 'pending',
        'name_servers' => ['ana.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
    ]]], 200),
    'api.cloudflare.com/client/v4/zones/*/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec1']], 200),
]);

$d = dominio(['cf_zone_id' => null]);
$estado = CloudflareClient::for($d)->zonaLista();

comprobar($estado['ok'] === false, 'la extension se da cuenta de que la zona no esta activa');
comprobar(str_contains($estado['message'], 'DNS_PROBE_FINISHED_NXDOMAIN'), 'explica el error que ve el cliente en el navegador');
comprobar(str_contains($estado['message'], 'ana.ns.cloudflare.com'), 'dice a que nameservers hay que cambiar el dominio');
echo "\n    Mensaje: " . $estado['message'] . "\n";

echo "\n=== 2. ZONA ACTIVA: SE CREA EL REGISTRO Y SE VUELVE A LEER ===\n\n";

$llamadas = [];
reiniciarHttp();
Http::fake(function ($peticion) use (&$llamadas, $ZONA) {
    $url = (string) $peticion->url();
    $llamadas[] = $peticion->method() . ' ' . $url;

    if (str_contains($url, '/zones?name=') || str_contains($url, '/zones?')) {
        return Http::response(['success' => true, 'result' => [[
            'id' => $ZONA, 'name' => 'ultraplus.click', 'status' => 'active',
            'name_servers' => ['ana.ns.cloudflare.com'],
        ]]], 200);
    }

    // Listado de registros: no hay ninguno todavia.
    if (str_contains($url, '/dns_records?') && $peticion->method() === 'GET') {
        return Http::response(['success' => true, 'result' => []], 200);
    }

    // Alta del registro.
    if (str_ends_with($url, '/dns_records') && $peticion->method() === 'POST') {
        return Http::response(['success' => true, 'result' => ['id' => 'rec-nuevo']], 200);
    }

    // Relectura del registro recien creado.
    if (str_contains($url, '/dns_records/rec-nuevo')) {
        return Http::response(['success' => true, 'result' => [
            'id' => 'rec-nuevo', 'name' => 'shop-sky.ultraplus.click', 'content' => '203.0.113.10', 'proxied' => false,
        ]], 200);
    }

    return Http::response(['success' => false, 'errors' => [['code' => 0, 'message' => 'ruta no esperada: ' . $url]]], 400);
});

$d = dominio(['cf_zone_id' => null]);
$cliente = CloudflareClient::for($d);

comprobar($cliente->zonaLista()['ok'] === true, 'con la zona activa deja seguir');

$resultado = $cliente->ensureAddressRecord('shop-sky.ultraplus.click', '203.0.113.10', false);
comprobar($resultado['creado'] === true && $resultado['id'] === 'rec-nuevo', 'crea el registro A');

$verificado = $cliente->verificarRegistro('rec-nuevo', 'shop-sky.ultraplus.click', '203.0.113.10');
comprobar($verificado['ok'] === true, 'lo vuelve a leer de Cloudflare para confirmarlo');
comprobar($d->fresh()->cf_zone_id === $ZONA, 'se guarda la zona para no volver a buscarla');

echo "\n=== 3. EL REGISTRO YA EXISTIA APUNTANDO A OTRO SITIO ===\n";
echo "    (antes Cloudflare devolvia error 81057 y el cliente no podia crear su dominio)\n\n";

$puesto = null;
reiniciarHttp();
Http::fake(function ($peticion) use (&$puesto, $ZONA) {
    $url = (string) $peticion->url();

    if (str_contains($url, '/zones?')) {
        return Http::response(['success' => true, 'result' => [[
            'id' => $ZONA, 'name' => 'ultraplus.click', 'status' => 'active', 'name_servers' => [],
        ]]], 200);
    }

    if (str_contains($url, '/dns_records?') && $peticion->method() === 'GET') {
        return Http::response(['success' => true, 'result' => [[
            'id' => 'rec-viejo', 'type' => 'A', 'name' => 'shop-sky.ultraplus.click',
            'content' => '198.51.100.5', 'proxied' => false,
        ]]], 200);
    }

    if (str_contains($url, '/dns_records/rec-viejo') && $peticion->method() === 'PUT') {
        $puesto = $peticion->data();

        return Http::response(['success' => true, 'result' => ['id' => 'rec-viejo']], 200);
    }

    if (str_ends_with($url, '/dns_records') && $peticion->method() === 'POST') {
        return Http::response(['success' => false, 'errors' => [['code' => 81057, 'message' => 'Record already exists.']]], 400);
    }

    return Http::response(['success' => false, 'errors' => [['code' => 0, 'message' => 'ruta no esperada']]], 400);
});

$d = dominio(['cf_zone_id' => $ZONA]);
$resultado = CloudflareClient::for($d)->ensureAddressRecord('shop-sky.ultraplus.click', '203.0.113.10', false);

comprobar($resultado['creado'] === false, 'reconoce que el registro ya estaba');
comprobar(is_array($puesto) && ($puesto['content'] ?? '') === '203.0.113.10', 'lo corrige para que apunte al servidor de verdad');
comprobar(is_string($resultado['aviso']) && str_contains((string) $resultado['aviso'], '198.51.100.5'), 'avisa de a donde apuntaba antes');
echo "\n    Aviso: " . $resultado['aviso'] . "\n";

echo "\n=== 4. LA ZONA GUARDADA YA NO ES LA DE ESTE DOMINIO ===\n";
echo "    (el dominio se movio de cuenta de Cloudflare: los registros se creaban en la zona equivocada)\n\n";

$zonasPedidas = [];
reiniciarHttp();
Http::fake(function ($peticion) use (&$zonasPedidas) {
    $url = (string) $peticion->url();

    // Zona guardada: existe, pero es de OTRO dominio.
    if (str_contains($url, '/zones/zona-vieja')) {
        return Http::response(['success' => true, 'result' => [
            'id' => 'zona-vieja', 'name' => 'otrodominio.com', 'status' => 'active', 'name_servers' => [],
        ]], 200);
    }

    if (str_contains($url, '/zones?')) {
        return Http::response(['success' => true, 'result' => [[
            'id' => 'zona-buena', 'name' => 'ultraplus.click', 'status' => 'active', 'name_servers' => [],
        ]]], 200);
    }

    if (str_contains($url, '/dns_records')) {
        foreach (['zona-vieja', 'zona-buena'] as $z) {
            if (str_contains($url, '/zones/' . $z . '/')) {
                $zonasPedidas[] = $z;
            }
        }

        return Http::response(['success' => true, 'result' => []], 200);
    }

    return Http::response(['success' => false, 'errors' => []], 400);
});

$d = dominio(['cf_zone_id' => 'zona-vieja']);
$zona = CloudflareClient::for($d)->zone();

comprobar($zona['id'] === 'zona-buena', 'se da cuenta y busca la zona correcta');
comprobar($d->fresh()->cf_zone_id === 'zona-buena', 'y la guarda corregida');
comprobar(!in_array('zona-vieja', $zonasPedidas, true), 'no se crea ni un solo registro en la zona equivocada');

echo "\n=== 5. EL CERTIFICADO DEL CLIENTE SE GUARDA CIFRADO Y SE PUEDE RECUPERAR ===\n";
echo "    (antes solo vivia en el nodo: al reinstalar el nodo se perdia para siempre)\n\n";

DB::table('server_proxy')->truncate();

$registro = new ProxyRecord();
$registro->fill([
    'server_id' => 1,
    'domain' => 'mipagina.com',
    'proxy_type' => ProxyRecord::TYPE_DOMAIN,
    'allocation_id' => 1,
    'ssl_enabled' => true,
    'ssl_mode' => ProxyRecord::SSL_ORIGIN,
]);
$registro->guardarCertificado("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----", "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----");
$registro->save();

$guardadoEnBruto = (string) DB::table('server_proxy')->where('id', $registro->id)->value('ssl_key');
[$cert, $clave] = $registro->fresh()->certificadoGuardado();

comprobar(!str_contains($guardadoEnBruto, 'PRIVATE KEY'), 'la clave privada NO queda legible en la base de datos');
comprobar(str_contains($cert, 'BEGIN CERTIFICATE'), 'el certificado se recupera entero');
comprobar(str_contains($clave, 'BEGIN PRIVATE KEY'), 'la clave se recupera entera');
comprobar($registro->fresh()->tieneCertificadoPropio(), 'el DNS sabe que tiene certificado propio');

echo "\n=== 6. CREAR UN DNS CON LA ZONA SIN ACTIVAR: SE PARA Y SE EXPLICA ===\n";
echo "    (esto es EXACTAMENTE lo que fallaba: antes decia \"creado\" y el cliente veia NXDOMAIN)\n\n";

DB::table('server_proxy')->truncate();
DB::table('servers')->truncate();
DB::table('users')->truncate();
DB::table('nodes')->truncate();
DB::table('allocations')->truncate();

$nodo = \Pterodactyl\Models\Node::create(['name' => 'NODO1', 'fqdn' => 'nodo1.ejemplo.com']);
$usuario = \Pterodactyl\Models\User::create(['username' => 'cliente', 'email' => 'cliente@ejemplo.com']);
$servidor = \Pterodactyl\Models\Server::create([
    'uuid' => '11111111-2222-3333-4444-555555555555', 'uuidShort' => '1111aaaa',
    'name' => 'Servidor', 'owner_id' => $usuario->id, 'node_id' => $nodo->id, 'proxy_limit' => 5,
]);
$asignacion = \Pterodactyl\Models\Allocation::create([
    'node_id' => $nodo->id, 'server_id' => $servidor->id, 'ip' => '203.0.113.10', 'port' => 25565,
]);
$servidor->allocation_id = $asignacion->id;
$servidor->save();

reiniciarHttp();
Http::fake(function ($peticion) {
    $url = (string) $peticion->url();

    if (str_contains($url, '/zones?')) {
        return Http::response(['success' => true, 'result' => [[
            'id' => 'zona-pendiente', 'name' => 'ultraplus.click', 'status' => 'pending',
            'name_servers' => ['ana.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ]]], 200);
    }

    return Http::response(['success' => true, 'result' => ['id' => 'no-deberia-llegar-aqui']], 200);
});

$d = dominio(['cf_zone_id' => null]);
$gestor = app(\Pterodactyl\Extensions\DnsReverse\Services\ProxyManager::class);

$mensaje = '';

try {
    $gestor->create($servidor, $usuario, [
        'type' => ProxyRecord::TYPE_SUBDOMAIN, 'name' => 'shop-sky', 'domain_id' => $d->id,
        'allocation_id' => $asignacion->id, 'ssl_mode' => ProxyRecord::SSL_LETSENCRYPT,
        'ssl_cert' => '', 'ssl_key' => '',
    ]);
} catch (\Throwable $e) {
    $mensaje = $e->getMessage();
}

comprobar($mensaje !== '', 'no deja crear el DNS si la zona no esta activa');
comprobar(str_contains($mensaje, 'nameservers'), 'y dice que hay que cambiar los nameservers');
comprobar(ProxyRecord::count() === 0, 'no se guarda un DNS que no iba a funcionar');
echo "\n    Mensaje al cliente: " . $mensaje . "\n";

echo "\n=== 7. CREAR UN DNS CON TODO BIEN ===\n\n";

$peticionesAlNodo = [];
reiniciarHttp();
Http::fake(function ($peticion) use (&$peticionesAlNodo) {
    $url = (string) $peticion->url();

    if (str_contains($url, '/zones?')) {
        return Http::response(['success' => true, 'result' => [[
            'id' => 'zona-buena', 'name' => 'ultraplus.click', 'status' => 'active', 'name_servers' => [],
        ]]], 200);
    }

    if (str_contains($url, '/dns_records?') && $peticion->method() === 'GET') {
        return Http::response(['success' => true, 'result' => []], 200);
    }

    if (str_ends_with($url, '/dns_records') && $peticion->method() === 'POST') {
        return Http::response(['success' => true, 'result' => ['id' => 'rec-ok']], 200);
    }

    if (str_contains($url, '/dns_records/rec-ok')) {
        return Http::response(['success' => true, 'result' => [
            'id' => 'rec-ok', 'name' => 'shop-sky.ultraplus.click', 'content' => '203.0.113.10', 'proxied' => false,
        ]], 200);
    }

    // El resolutor publico: el nombre ya se ve desde fuera.
    if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
        return Http::response(['Answer' => [['type' => 1, 'data' => '203.0.113.10']]], 200);
    }

    // El nodo (wings).
    if (str_contains($url, 'node.invalid')) {
        $peticionesAlNodo[] = $peticion->method() . ' ' . $url;

        if (str_contains($url, '/api/dns-reverse/status')) {
            return Http::response(['version' => 2, 'nginx' => true], 200);
        }

        return Http::response(['ok' => true], 200);
    }

    return Http::response(['success' => false, 'errors' => [['code' => 0, 'message' => 'no esperada: ' . $url]]], 400);
});

$d = dominio(['cf_zone_id' => null]);
$registro = $gestor->create($servidor, $usuario, [
    'type' => ProxyRecord::TYPE_SUBDOMAIN, 'name' => 'shop-sky', 'domain_id' => $d->id,
    'allocation_id' => $asignacion->id, 'ssl_mode' => ProxyRecord::SSL_LETSENCRYPT,
    'ssl_cert' => '', 'ssl_key' => '',
]);

comprobar($registro->domain === 'shop-sky.ultraplus.click', 'crea el DNS con el nombre correcto');
comprobar($registro->cf_record_id === 'rec-ok', 'guarda el identificador del registro de Cloudflare');
comprobar($registro->last_error === null, 'sin avisos: el dominio resuelve');
comprobar((bool) $registro->ssl_enabled === true, 'con el certificado puesto');
comprobar($registro->cert_expires_at !== null, 'y con la fecha de caducidad de los 90 dias apuntada');

echo "\n";

echo "\n=== 8. EL CLIENTE TRAE SU PROPIO DOMINIO Y SU CERTIFICADO DE ORIGEN ===\n\n";

// Certificado de mentira, generado al vuelo para la prueba. Sirve para
// mipagina.com y *.mipagina.com, que es justo lo que pide Cloudflare al
// generar un certificado de origen.
$CERTS = sys_get_temp_dir() . '/dnsreverse-pruebas';

if (!is_dir($CERTS)) {
    mkdir($CERTS, 0700, true);
}

if (!is_file($CERTS . '/mipagina.crt')) {
    exec(sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 365 -subj "/CN=mipagina.com" -addext "subjectAltName=DNS:mipagina.com,DNS:*.mipagina.com" 2>/dev/null',
        escapeshellarg($CERTS . '/mipagina.key'),
        escapeshellarg($CERTS . '/mipagina.crt')
    ), $salida, $codigo);

    if ($codigo !== 0) {
        echo "  No se pudo generar el certificado de prueba (¿esta openssl instalado?). Se salta este bloque.\n";
        $CERTS = null;
    }
}

$certTexto = $CERTS === null ? '' : (string) file_get_contents($CERTS . '/mipagina.crt');
$claveTexto = $CERTS === null ? '' : (string) file_get_contents($CERTS . '/mipagina.key');

$mandadoAlNodo = [];
reiniciarHttp();
Http::fake(function ($peticion) use (&$mandadoAlNodo) {
    $url = (string) $peticion->url();

    // El dominio del cliente todavia apunta a otro sitio.
    if (str_contains($url, 'dns-query') || str_contains($url, 'dns.google')) {
        return Http::response(['Answer' => [['type' => 1, 'data' => '198.51.100.99']]], 200);
    }

    if (str_contains($url, 'node.invalid')) {
        if (str_contains($url, '/api/dns-reverse/status')) {
            return Http::response(['version' => 2, 'nginx' => true], 200);
        }

        $mandadoAlNodo[] = $peticion->data();

        return Http::response(['ok' => true], 200);
    }

    return Http::response(['success' => false, 'errors' => [['code' => 0, 'message' => 'no esperada: ' . $url]]], 400);
});

DB::table('server_proxy')->truncate();

$registro = $gestor->create($servidor, $usuario, [
    'type' => ProxyRecord::TYPE_DOMAIN, 'name' => 'mipagina.com', 'domain_id' => 0,
    'allocation_id' => $asignacion->id, 'ssl_mode' => ProxyRecord::SSL_ORIGIN,
    'ssl_cert' => $certTexto, 'ssl_key' => $claveTexto,
]);

comprobar($registro->domain === 'mipagina.com', 'se crea el dominio propio del cliente');
comprobar(is_string($registro->last_error) && str_contains((string) $registro->last_error, '198.51.100.99'),
    'le avisa de que su dominio apunta a otro sitio');
comprobar(is_string($registro->last_error) && str_contains((string) $registro->last_error, '203.0.113.10'),
    'y le dice a que IP tiene que apuntarlo');
echo "\n    Aviso al cliente: " . $registro->last_error . "\n\n";

$mandado = $mandadoAlNodo[0] ?? [];
comprobar(($mandado['mode'] ?? '') === 'origin', 'al nodo se le manda el modo "origin"');
comprobar(str_contains((string) ($mandado['ssl_cert'] ?? ''), 'BEGIN CERTIFICATE'), 'con el certificado del cliente');
comprobar(str_contains((string) ($mandado['ssl_key'] ?? ''), 'PRIVATE KEY'), 'y con su clave');

// Y ahora se reinstala el nodo: la resincronizacion tiene que volver a
// mandarle el certificado, que antes se perdia para siempre.
$mandadoAlNodo = [];
$resultado = $gestor->sync($registro->fresh(['allocation', 'server']));

comprobar($resultado['ok'] === true, 'la resincronizacion funciona');
$reenviado = $mandadoAlNodo[0] ?? [];
comprobar(str_contains((string) ($reenviado['ssl_cert'] ?? ''), 'BEGIN CERTIFICATE'),
    'y le vuelve a mandar el certificado del cliente al nodo reinstalado');

echo "\n=== 9. UN CERTIFICADO QUE NO ES DE ESE DOMINIO SE RECHAZA ===\n\n";

DB::table('server_proxy')->truncate();
$mensaje = '';

try {
    $gestor->create($servidor, $usuario, [
        'type' => ProxyRecord::TYPE_DOMAIN, 'name' => 'otracosa.com', 'domain_id' => 0,
        'allocation_id' => $asignacion->id, 'ssl_mode' => ProxyRecord::SSL_ORIGIN,
        'ssl_cert' => $certTexto, 'ssl_key' => $claveTexto,
    ]);
} catch (\Throwable $e) {
    $mensaje = $e->getMessage();
}

comprobar(str_contains($mensaje, 'no vale para otracosa.com'), 'no se acepta un certificado de otro dominio');
comprobar(ProxyRecord::count() === 0, 'y no se guarda nada');
echo "\n    Mensaje: " . $mensaje . "\n";

echo "\n";

echo $fallos === 0
    ? "  TODO BIEN\n\n"
    : "  " . $fallos . " FALLO(S)\n\n";

exit($fallos === 0 ? 0 : 1);
