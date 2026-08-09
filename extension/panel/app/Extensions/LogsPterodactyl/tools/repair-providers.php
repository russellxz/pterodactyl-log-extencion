#!/usr/bin/env php
<?php

/**
 * Deja el panel arrancable comentando los proveedores rotos de config/app.php.
 *
 * Para que sirve: al actualizar Pterodactyl se descomprime el paquete oficial
 * encima del panel. Eso reemplaza composer.json y vuelve a instalar solo las
 * dependencias oficiales, asi que si tenias otra extension instalada puede
 * quedarse sin sus paquetes o sin parte de sus archivos, mientras su linea
 * sigue apuntandola en config/app.php.
 *
 * Cuando eso pasa, Laravel no arranca. Y como no arranca, NINGUN comando de
 * artisan funciona: ni migrate, ni optimize, ni "artisan up". Resultado: la
 * actualizacion se queda a medias y el panel en mantenimiento, con un error de
 * "Class ... not found" que no dice de quien es la culpa.
 *
 * Este script se ejecuta cuando algo de eso ha pasado: mira uno a uno los
 * proveedores registrados, y los que apuntan a una clase que ya no existe los
 * comenta en lugar de borrarlos, con una nota al lado. El panel vuelve a
 * arrancar, la actualizacion termina, y luego se puede reinstalar esa
 * extension y descomentar su linea.
 *
 * Uso:
 *     php repair-providers.php /var/www/pterodactyl [--dry-run]
 *
 * Codigos de salida: 0 todo bien o arreglado, 1 no se pudo.
 */

$panel = rtrim($argv[1] ?? '/var/www/pterodactyl', '/');
$simular = in_array('--dry-run', $argv, true);

$config = $panel . '/config/app.php';
$autoload = $panel . '/vendor/autoload.php';

if (!is_file($config)) {
    fwrite(STDERR, "No se encontro $config\n");
    exit(1);
}

if (!is_file($autoload)) {
    fwrite(STDERR, "No se encontro $autoload. Ejecuta composer install primero.\n");
    exit(1);
}

require $autoload;

$contenido = file_get_contents($config);

if (!is_string($contenido)) {
    fwrite(STDERR, "No se pudo leer $config\n");
    exit(1);
}

$lineas = preg_split('/\R/', $contenido);
$rotos = [];
$salida = [];

foreach ($lineas as $linea) {
    $limpia = trim($linea);

    // Solo interesan las lineas de proveedor activas.
    if (!str_contains($limpia, 'ServiceProvider::class') || str_starts_with($limpia, '//')) {
        $salida[] = $linea;

        continue;
    }

    if (preg_match('/([A-Za-z0-9_\\\\]+ServiceProvider)::class/', $limpia, $m) !== 1) {
        $salida[] = $linea;

        continue;
    }

    $clase = ltrim($m[1], '\\');

    // Los del propio framework y los del panel se dan por buenos: si esos
    // faltan, el problema es otro y comentarlos no arreglaria nada.
    if (str_starts_with($clase, 'Illuminate\\')
        || str_starts_with($clase, 'Laravel\\')
        || str_starts_with($clase, 'Pterodactyl\\Providers\\')) {
        $salida[] = $linea;

        continue;
    }

    if (class_exists($clase)) {
        $salida[] = $linea;

        continue;
    }

    $rotos[] = $clase;

    // Se comenta, nunca se borra: asi queda a la vista para volver a activarla
    // en cuanto se reinstale la extension.
    $sangria = substr($linea, 0, strlen($linea) - strlen(ltrim($linea)));
    $salida[] = $sangria . '// ' . $limpia
        . ' // LogsPterodactyl: desactivado al actualizar, la clase no existe';
}

if ($rotos === []) {
    echo "Todos los proveedores de config/app.php apuntan a clases que existen.\n";
    exit(0);
}

echo "Proveedores que apuntan a clases que ya no existen:\n";

foreach ($rotos as $clase) {
    echo '  - ' . $clase . "\n";
}

if ($simular) {
    echo "\n(simulacion: no se ha tocado nada)\n";
    exit(0);
}

// Copia de seguridad antes de escribir, con marca de tiempo.
$respaldo = $config . '.antes-de-reparar-' . date('Ymd-His');

if (!copy($config, $respaldo)) {
    fwrite(STDERR, "No se pudo hacer copia de $config\n");
    exit(1);
}

if (file_put_contents($config, implode("\n", $salida)) === false) {
    fwrite(STDERR, "No se pudo escribir $config\n");
    exit(1);
}

echo "\nSe han comentado " . count($rotos) . " linea(s) para que el panel pueda arrancar.\n";
echo "Copia del archivo original en: " . $respaldo . "\n";
echo "Cuando vuelvas a instalar esas extensiones, quita el // de su linea en config/app.php.\n";

exit(0);
