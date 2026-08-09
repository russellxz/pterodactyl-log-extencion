<?php

/**
 * Pone la version real del panel en config/app.php.
 *
 * Hace falta porque el tema Arix trae su PROPIO config/app.php con la version
 * del panel fijada a mano (la version para la que se compilo el tema). Al
 * restaurar el tema despues de actualizar, ese archivo pisa al del panel nuevo
 * y el panel sigue diciendo que tiene la version vieja.
 *
 * Este script solo toca la clave 'version'. Todo lo demas del archivo, que es
 * lo que el tema necesita, se queda igual.
 *
 *   php sync-version.php /var/www/pterodactyl 1.15.0
 */

$panel = rtrim($argv[1] ?? '/var/www/pterodactyl', '/');
$version = $argv[2] ?? '';

if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$/', $version)) {
    fwrite(STDERR, "ERROR: version no valida: '$version'\n");
    exit(1);
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

$updated = preg_replace(
    "/(['\"]version['\"]\s*=>\s*)['\"][^'\"]*['\"]/",
    "$1'" . $version . "'",
    $contents,
    1,
    $count
);

if ($count === 0 || !is_string($updated)) {
    fwrite(STDERR, "AVISO: no se encontro la clave 'version' en config/app.php, no se ha tocado nada.\n");
    exit(0);
}

if ($updated === $contents) {
    echo "La version ya era $version.\n";
    exit(0);
}

// Nunca se escribe un archivo que no sea PHP valido: un config/app.php roto
// deja el panel completamente muerto.
$temporary = tempnam(sys_get_temp_dir(), 'arixlog');

if ($temporary === false || file_put_contents($temporary, $updated) === false) {
    fwrite(STDERR, "ERROR: no se pudo escribir el archivo temporal.\n");
    exit(1);
}

exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $output, $code);

if ($code !== 0) {
    @unlink($temporary);
    fwrite(STDERR, "ERROR: el resultado no era PHP valido, no se ha tocado nada.\n");
    exit(1);
}

@unlink($temporary);

if (file_put_contents($config, $updated) === false) {
    fwrite(STDERR, "ERROR: no se pudo escribir $config\n");
    exit(1);
}

foreach (['config.php', 'services.php', 'packages.php'] as $file) {
    $path = $panel . '/bootstrap/cache/' . $file;

    if (is_file($path)) {
        @unlink($path);
    }
}

echo "Version del panel puesta a $version en config/app.php\n";
