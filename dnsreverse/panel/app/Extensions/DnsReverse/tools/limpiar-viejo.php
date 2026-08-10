<?php

/**
 * Quita los restos de la extension ANTIGUA de "reverse proxy".
 *
 *   php limpiar-viejo.php /var/www/pterodactyl            (solo mira y cuenta)
 *   php limpiar-viejo.php /var/www/pterodactyl --limpiar  (la quita)
 *
 * La antigua se instalaba a mano, tocando archivos del panel: dejaba sus
 * controladores sueltos, un grupo de rutas en routes/admin.php y en
 * routes/api-client.php, una entrada en el menu del admin y otra en routes.ts.
 * Por eso salen apartados repetidos: los suyos y los nuevos.
 *
 * NO SE BORRA NI UN DATO. La tabla server_proxy, servers.proxy_limit y las
 * migraciones se quedan como estan: ahi viven los DNS de tus clientes y la
 * extension nueva los sigue usando.
 */

$panel = rtrim($argv[1] ?? '/var/www/pterodactyl', '/');
$limpiar = in_array('--limpiar', $argv, true);

if (!is_file($panel . '/artisan')) {
    fwrite(STDERR, "Aqui no hay un panel de Pterodactyl: $panel\n");
    exit(1);
}

$encontrado = [];
$hechos = [];

// ---------------------------------------------------------------------------
// 1. Archivos sueltos que dejaba la version antigua.
//
// Ojo: las migraciones NO estan en esta lista a proposito. Ya estan aplicadas y
// son las que crearon la tabla donde viven los DNS.
// ---------------------------------------------------------------------------

$archivos = [
    'app/Http/Controllers/Admin/ProxySettingsController.php',
    'app/Http/Controllers/Admin/ProxyPurgeController.php',
    'app/Http/Controllers/Api/Client/Servers/ProxyController.php',
    'app/Http/Requests/Api/Client/Servers/Proxy',
    'app/Models/ServerProxy.php',
    'app/Services/Cloudflare',
    'resources/scripts/api/server/proxy',
    'resources/scripts/components/server/proxy',
    'resources/views/admin/proxy',
];

foreach ($archivos as $relativo) {
    $ruta = $panel . '/' . $relativo;

    if (!file_exists($ruta)) {
        continue;
    }

    $encontrado[] = $relativo;

    if ($limpiar) {
        borrarTodo($ruta);
        $hechos[] = 'borrado ' . $relativo;
    }
}

// ---------------------------------------------------------------------------
// 2. El grupo de rutas que metia en routes/admin.php y routes/api-client.php
// ---------------------------------------------------------------------------

foreach (['routes/admin.php', 'routes/api-client.php'] as $relativo) {
    $ruta = $panel . '/' . $relativo;

    if (!is_file($ruta)) {
        continue;
    }

    $texto = (string) file_get_contents($ruta);

    if (!str_contains($texto, 'ProxySettingsController')
        && !str_contains($texto, 'ProxyPurgeController')
        && !str_contains($texto, "Servers\\ProxyController")
        && !preg_match("/'prefix'\s*=>\s*'\/?proxy'/", $texto)) {
        continue;
    }

    $encontrado[] = $relativo . ' (grupo de rutas de la version antigua)';

    if (!$limpiar) {
        continue;
    }

    $nuevo = quitarGrupoProxy($texto);

    if ($nuevo === null) {
        $hechos[] = 'NO se pudo limpiar ' . $relativo . ' (revisalo a mano)';
        continue;
    }

    // Se guarda copia y se comprueba que el PHP sigue siendo valido antes de
    // dejarlo puesto. Si no, se devuelve el original.
    copy($ruta, $ruta . '.antes-de-limpiar');
    file_put_contents($ruta, $nuevo);

    if (!phpValido($ruta)) {
        copy($ruta . '.antes-de-limpiar', $ruta);
        $hechos[] = 'NO se pudo limpiar ' . $relativo . ' (quedaba invalido, se ha devuelto)';
    } else {
        $hechos[] = 'limpiado ' . $relativo;
    }
}

// ---------------------------------------------------------------------------
// 3. La entrada del menu del admin
// ---------------------------------------------------------------------------

$layout = $panel . '/resources/views/layouts/admin.blade.php';

if (is_file($layout)) {
    $texto = (string) file_get_contents($layout);

    if (str_contains($texto, 'admin.proxy.')) {
        $encontrado[] = 'resources/views/layouts/admin.blade.php (entrada del menu antigua)';

        if ($limpiar) {
            $nuevo = quitarLiDelMenu($texto, 'admin.proxy.');

            if ($nuevo !== null && $nuevo !== $texto) {
                copy($layout, $layout . '.antes-de-limpiar');
                file_put_contents($layout, $nuevo);
                $hechos[] = 'quitada la entrada antigua del menu del admin';
            } else {
                $hechos[] = 'NO se pudo quitar la entrada del menu (quitala a mano)';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 4. La entrada del area de cliente en routes.ts
// ---------------------------------------------------------------------------

foreach (['resources/scripts/routers/routes.ts', 'resources/scripts/routers/routes.tsx'] as $relativo) {
    $ruta = $panel . '/' . $relativo;

    if (!is_file($ruta)) {
        continue;
    }

    $texto = (string) file_get_contents($ruta);

    if (!str_contains($texto, 'ProxyContainer')) {
        continue;
    }

    $encontrado[] = $relativo . ' (pantalla de la version antigua)';

    if (!$limpiar) {
        continue;
    }

    $nuevo = preg_replace(
        [
            "/^import ProxyContainer .*\R/m",
            "/\s*\{[^{}]*ProxyContainer[^{}]*\},\R/",
        ],
        ['', "\n"],
        $texto
    );

    if ($nuevo !== null && $nuevo !== $texto) {
        copy($ruta, $ruta . '.antes-de-limpiar');
        file_put_contents($ruta, $nuevo);
        $hechos[] = 'quitada la pantalla antigua de ' . $relativo;
    } else {
        $hechos[] = 'NO se pudo quitar ProxyContainer de ' . $relativo . ' (quitalo a mano)';
    }
}

// ---------------------------------------------------------------------------

if ($encontrado === []) {
    echo "  No queda nada de la version antigua.\n";
    exit(2);
}

if (!$limpiar) {
    echo "  Restos de la version antigua:\n";

    foreach ($encontrado as $cosa) {
        echo "    - $cosa\n";
    }

    exit(0);
}

foreach ($hechos as $hecho) {
    echo "    $hecho\n";
}

echo "  Tus DNS no se han tocado: siguen en la tabla server_proxy.\n";
exit(0);

// ---------------------------------------------------------------------------

function borrarTodo(string $ruta): void
{
    if (is_file($ruta) || is_link($ruta)) {
        @unlink($ruta);

        return;
    }

    foreach (scandir($ruta) ?: [] as $hijo) {
        if ($hijo !== '.' && $hijo !== '..') {
            borrarTodo($ruta . '/' . $hijo);
        }
    }

    @rmdir($ruta);
}

function phpValido(string $ruta): bool
{
    $salida = [];
    $codigo = 0;
    @exec('php -l ' . escapeshellarg($ruta) . ' 2>&1', $salida, $codigo);

    return $codigo === 0;
}

/**
 * Quita el Route::group(['prefix' => 'proxy'], function () { ... }); entero,
 * contando llaves para no cortar por donde no es.
 */
function quitarGrupoProxy(string $texto): ?string
{
    if (!preg_match("/Route::group\(\s*\[\s*'prefix'\s*=>\s*'\/?proxy'\s*\]/", $texto, $m, PREG_OFFSET_CAPTURE)) {
        return $texto;
    }

    $inicio = $m[0][1];
    $llaves = 0;
    $largo = strlen($texto);
    $fin = null;

    for ($i = $inicio; $i < $largo; $i++) {
        if ($texto[$i] === '{') {
            $llaves++;
        } elseif ($texto[$i] === '}') {
            $llaves--;

            if ($llaves === 0) {
                // Se lleva tambien el ");" y el salto de linea que van detras.
                $resto = substr($texto, $i + 1, 4);
                $extra = str_starts_with($resto, ');') ? 2 : 0;
                $fin = $i + 1 + $extra;
                break;
            }
        }
    }

    if ($fin === null) {
        return null;
    }

    // Y la sangria que quedaba delante.
    $antes = substr($texto, 0, $inicio);
    $antes = rtrim($antes, " \t");

    $despues = substr($texto, $fin);
    $despues = preg_replace('/^\R+/', "\n", $despues);

    return rtrim($antes, "\n") . "\n" . ltrim($despues, "\n");
}

/**
 * Quita el <li>...</li> del menu que contenga el texto dado.
 */
function quitarLiDelMenu(string $texto, string $marca): ?string
{
    $pos = strpos($texto, $marca);

    if ($pos === false) {
        return $texto;
    }

    $inicio = strrpos(substr($texto, 0, $pos), '<li');

    if ($inicio === false) {
        return null;
    }

    $fin = strpos($texto, '</li>', $pos);

    if ($fin === false) {
        return null;
    }

    $fin += strlen('</li>');

    // Se lleva la sangria de delante y el salto de detras.
    while ($inicio > 0 && ($texto[$inicio - 1] === ' ' || $texto[$inicio - 1] === "\t")) {
        $inicio--;
    }

    $despues = substr($texto, $fin);
    $despues = preg_replace('/^\R/', '', $despues);

    return substr($texto, 0, $inicio) . $despues;
}
