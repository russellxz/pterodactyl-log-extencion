<?php

/**
 * Registra (o quita) el proveedor de LogsPterodactyl en config/app.php.
 *
 * Es un script suelto, sin nada de Laravel, porque tiene que poder ejecutarse
 * justo despues de una actualizacion del panel o del tema Arix, momento en el
 * que el proveedor todavia no esta registrado y por tanto los comandos artisan
 * de la extension no existen.
 *
 *   php register-provider.php /var/www/pterodactyl
 *   php register-provider.php /var/www/pterodactyl --remove
 *   php register-provider.php /var/www/pterodactyl --check
 */

const PROVIDER = 'Pterodactyl\\Extensions\\LogsPterodactyl\\LogsPterodactylServiceProvider::class';
const MARKER_START = '// LogsPterodactyl START (gestionado por la extension, no editar a mano)';
const MARKER_END = '// LogsPterodactyl END';

$panel = rtrim($argv[1] ?? '/var/www/pterodactyl', '/');
$mode = 'register';

foreach (array_slice($argv, 2) as $argument) {
    if ($argument === '--remove') {
        $mode = 'remove';
    } elseif ($argument === '--check') {
        $mode = 'check';
    }
}

$config = $panel . '/config/app.php';

if (!is_file($config)) {
    fwrite(STDERR, "ERROR: no se encontro $config\n");
    exit(1);
}

$contents = file_get_contents($config);

if ($contents === false) {
    fwrite(STDERR, "ERROR: no se pudo leer $config\n");
    exit(1);
}

$registered = str_contains($contents, 'LogsPterodactylServiceProvider');

if ($mode === 'check') {
    echo $registered ? "registrado\n" : "no registrado\n";
    exit($registered ? 0 : 2);
}

if ($mode === 'remove') {
    if (!$registered) {
        echo "El proveedor no estaba registrado, no hay nada que quitar.\n";
        exit(0);
    }

    $lines = preg_split('/\R/', $contents) ?: [];
    $output = [];
    $skipping = false;

    foreach ($lines as $line) {
        if (str_contains($line, MARKER_START)) {
            $skipping = true;

            continue;
        }

        if ($skipping) {
            if (str_contains($line, MARKER_END)) {
                $skipping = false;
            }

            continue;
        }

        // Por si alguien la anadio a mano sin las marcas.
        if (str_contains($line, 'LogsPterodactylServiceProvider')) {
            continue;
        }

        $output[] = $line;
    }

    write($config, implode("\n", $output), $panel);
    echo "Proveedor de LogsPterodactyl quitado de config/app.php\n";
    exit(0);
}

if ($registered) {
    echo "El proveedor ya estaba registrado.\n";
    clearCache($panel);
    exit(0);
}

$position = findProvidersClosingBracket($contents);

if ($position === null) {
    fwrite(STDERR, "ERROR: no se encontro el array 'providers' en config/app.php.\n");
    fwrite(STDERR, "       Anade esta linea a mano dentro de ese array:\n");
    fwrite(STDERR, '           ' . PROVIDER . ",\n");
    exit(1);
}

// El espaciado original de la linea anterior al corchete se conserva, asi que
// la insercion no necesita anadir su propio salto al final.
$insertion = "\n        " . MARKER_START . "\n        " . PROVIDER . ",\n        " . MARKER_END;
$contents = substr($contents, 0, $position) . $insertion . substr($contents, $position);

write($config, $contents, $panel);
echo "Proveedor de LogsPterodactyl registrado en config/app.php\n";
exit(0);

/**
 * Devuelve la posicion del corchete que cierra el array 'providers',
 * contando la anidacion para no confundirse con los arrays de dentro.
 */
function findProvidersClosingBracket(string $contents): ?int
{
    if (preg_match("/['\"]providers['\"]\s*=>\s*\[/", $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $start = $match[0][1] + strlen($match[0][0]);
    $depth = 1;
    $length = strlen($contents);

    for ($i = $start; $i < $length; $i++) {
        $char = $contents[$i];

        if ($char === '[') {
            $depth++;
        } elseif ($char === ']') {
            $depth--;

            if ($depth === 0) {
                // Se retrocede sobre los espacios previos para que la linea
                // insertada quede pegada al ultimo elemento.
                $end = $i;

                while ($end > $start && ($contents[$end - 1] === ' ' || $contents[$end - 1] === "\n" || $contents[$end - 1] === "\r")) {
                    $end--;
                }

                return $end;
            }
        }
    }

    return null;
}

function write(string $path, string $contents, string $panel): void
{
    // Se comprueba que el resultado sigue siendo PHP valido antes de pisar el
    // archivo. Un config/app.php roto deja el panel completamente muerto.
    $temporary = tempnam(sys_get_temp_dir(), 'logspterodactyl');

    if ($temporary === false || file_put_contents($temporary, $contents) === false) {
        fwrite(STDERR, "ERROR: no se pudo escribir el archivo temporal.\n");
        exit(1);
    }

    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        @unlink($temporary);
        fwrite(STDERR, "ERROR: el config/app.php resultante no es valido, no se ha tocado nada.\n");
        fwrite(STDERR, implode("\n", $output) . "\n");
        exit(1);
    }

    // Copia de seguridad del original la primera vez.
    if (!is_file($path . '.logspterodactyl-backup')) {
        @copy($path, $path . '.logspterodactyl-backup');
    }

    $permissions = fileperms($path);
    $owner = fileowner($path);
    $group = filegroup($path);

    if (file_put_contents($path, $contents) === false) {
        @unlink($temporary);
        fwrite(STDERR, "ERROR: no se pudo escribir $path (¿permisos?).\n");
        exit(1);
    }

    @unlink($temporary);

    if ($permissions !== false) {
        @chmod($path, $permissions & 0777);
    }

    if ($owner !== false && $group !== false) {
        @chown($path, $owner);
        @chgrp($path, $group);
    }

    clearCache($panel);
}

/**
 * La configuracion cacheada tiene prioridad sobre config/app.php, asi que hay
 * que tirarla o el cambio no surte efecto.
 */
function clearCache(string $panel): void
{
    foreach (['config.php', 'packages.php', 'services.php', 'routes-v7.php'] as $file) {
        $path = $panel . '/bootstrap/cache/' . $file;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
