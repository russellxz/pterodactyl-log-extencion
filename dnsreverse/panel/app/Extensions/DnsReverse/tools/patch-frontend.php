<?php

/**
 * Anade (o quita) la pantalla de DNS Reverse en el menu del area de cliente.
 *
 *   php patch-frontend.php /var/www/pterodactyl
 *   php patch-frontend.php /var/www/pterodactyl --remove
 *
 * Este es el "modo nativo": el boton se compila DENTRO del panel con
 * yarn build, igual que Consola, Archivos o Copias. Es lo que hacia la
 * extension antigua y es lo que menos molesta al navegador, porque el panel no
 * lleva ni una etiqueta anadida a posteriori.
 *
 * Toca un unico archivo del panel: resources/scripts/routers/routes.ts. Lo que
 * anade va entre marcas (// dnsreverse:inicio ... // dnsreverse:fin), asi que
 * quitarlo despues es exacto y no deja restos.
 *
 * Se puede ejecutar mil veces: si ya esta puesto, no hace nada.
 *
 * Devuelve 0 si todo bien, 1 si algo fallo, y 2 si no habia nada que hacer.
 */

$panel = rtrim($argv[1] ?? '/var/www/pterodactyl', '/');
$quitar = in_array('--remove', $argv, true) || in_array('--quitar', $argv, true);

$MARCA_INICIO = '// dnsreverse:inicio';
$MARCA_FIN = '// dnsreverse:fin';

function fallo(string $mensaje): never
{
    fwrite(STDERR, $mensaje . PHP_EOL);
    exit(1);
}

// --- 1. Localizar routes.ts -------------------------------------------------

$candidatos = [
    $panel . '/resources/scripts/routers/routes.ts',
    $panel . '/resources/scripts/routers/routes.tsx',
];

$rutas = null;

foreach ($candidatos as $candidato) {
    if (is_file($candidato)) {
        $rutas = $candidato;
        break;
    }
}

if ($rutas === null) {
    fallo('No se encontro resources/scripts/routers/routes.ts en ' . $panel . '.' . PHP_EOL
        . 'Si tu panel es una version muy distinta, usa el modo inyectado (no hace falta compilar nada).');
}

$original = file_get_contents($rutas);

if ($original === false) {
    fallo('No se pudo leer ' . $rutas);
}

// --- 2. Quitar --------------------------------------------------------------

if ($quitar) {
    if (!str_contains($original, $MARCA_INICIO)) {
        echo 'No estaba puesto: no hay nada que quitar.' . PHP_EOL;
        exit(2);
    }

    // Se borra cada bloque marcado, con su salto de linea final.
    $limpio = preg_replace(
        '/[ \t]*' . preg_quote($MARCA_INICIO, '/') . '.*?' . preg_quote($MARCA_FIN, '/') . '[ \t]*\R?/s',
        '',
        $original
    );

    if ($limpio === null || $limpio === $original) {
        fallo('No se pudo quitar el bloque de DNS Reverse de routes.ts. Reviselo a mano.');
    }

    copiaDeSeguridad($rutas, $original);

    if (file_put_contents($rutas, $limpio) === false) {
        fallo('No se pudo escribir ' . $rutas . ' (¿permisos?).');
    }

    echo 'Quitado de routes.ts.' . PHP_EOL;
    exit(0);
}

// --- 3. Poner ---------------------------------------------------------------

if (str_contains($original, $MARCA_INICIO)) {
    echo 'Ya estaba puesto en routes.ts: no se toca nada.' . PHP_EOL;
    exit(2);
}

// Por si alguien lo anadio a mano alguna vez sin las marcas.
if (str_contains($original, 'DnsReverseContainer')) {
    fallo('routes.ts ya menciona DnsReverseContainer pero sin las marcas de la extension.' . PHP_EOL
        . 'Quitalo a mano de ' . $rutas . ' y vuelve a lanzar el instalador.');
}

$contenido = $original;

// 3.1 El import. Va detras del ultimo import de la cabecera, para no meterse
//     entre las declaraciones lazy() que hay mas abajo.
if (!preg_match_all('/^import .*$/m', $contenido, $coincidencias, PREG_OFFSET_CAPTURE)) {
    fallo('routes.ts no tiene ningun import: no parece el archivo correcto.');
}

$ultimoImport = end($coincidencias[0]);
$finImports = $ultimoImport[1] + strlen($ultimoImport[0]);

$bloqueImport = PHP_EOL
    . $MARCA_INICIO . PHP_EOL
    . "import DnsReverseContainer from '@/components/server/dnsreverse/DnsReverseContainer';" . PHP_EOL
    . $MARCA_FIN;

$contenido = substr($contenido, 0, $finImports) . $bloqueImport . substr($contenido, $finImports);

// 3.2 La entrada del menu, al final del array server: [ ... ].
$posServer = strpos($contenido, 'server: [');

if ($posServer === false) {
    fallo('routes.ts no tiene el array "server: [". No parece el archivo correcto.');
}

$cierre = cierreDelArray($contenido, $posServer + strlen('server: [') - 1);

if ($cierre === null) {
    fallo('No se pudo encontrar el final del array "server" en routes.ts.');
}

// Se respeta la sangria que ya use el archivo.
$sangria = sangriaDeLaLinea($contenido, $cierre) . '    ';

$bloqueRuta = $sangria . $MARCA_INICIO . PHP_EOL
    . $sangria . '{' . PHP_EOL
    . $sangria . "    path: '/dnsreverse'," . PHP_EOL
    . $sangria . '    permission: null,' . PHP_EOL
    . $sangria . "    name: 'DNS Reverse'," . PHP_EOL
    . $sangria . '    component: DnsReverseContainer,' . PHP_EOL
    . $sangria . '},' . PHP_EOL
    . $sangria . $MARCA_FIN . PHP_EOL;

// Se inserta al principio de la linea donde esta el ] que cierra.
$inicioLinea = (int) strrpos(substr($contenido, 0, $cierre), PHP_EOL) + 1;
$contenido = substr($contenido, 0, $inicioLinea) . $bloqueRuta . substr($contenido, $inicioLinea);

copiaDeSeguridad($rutas, $original);

if (file_put_contents($rutas, $contenido) === false) {
    fallo('No se pudo escribir ' . $rutas . ' (¿permisos?).');
}

echo 'Anadido a routes.ts.' . PHP_EOL;
exit(0);

// ---------------------------------------------------------------------------

/**
 * Guarda una copia del archivo tal y como estaba, una sola vez.
 *
 * La copia .dnsreverse-original es la que usa el desinstalador para dejar
 * routes.ts exactamente como el panel lo trae.
 */
function copiaDeSeguridad(string $archivo, string $contenido): void
{
    $copia = $archivo . '.dnsreverse-original';

    if (!file_exists($copia)) {
        @file_put_contents($copia, $contenido);
    }
}

/**
 * Devuelve la posicion del ] que cierra el [ que hay en $inicio.
 *
 * Cuenta corchetes saltandose lo que haya dentro de comillas o de comentarios,
 * que en routes.ts los hay ('/files/:action(edit|new)', los comentarios de
 * cabecera, etc.).
 */
function cierreDelArray(string $texto, int $inicio): ?int
{
    $profundidad = 0;
    $largo = strlen($texto);

    for ($i = $inicio; $i < $largo; $i++) {
        $c = $texto[$i];
        $siguiente = $texto[$i + 1] ?? '';

        // Comentario de una linea.
        if ($c === '/' && $siguiente === '/') {
            $salto = strpos($texto, "\n", $i);
            $i = $salto === false ? $largo : $salto;
            continue;
        }

        // Comentario de bloque.
        if ($c === '/' && $siguiente === '*') {
            $fin = strpos($texto, '*/', $i);
            $i = $fin === false ? $largo : $fin + 1;
            continue;
        }

        // Cadenas: ' " y `
        if ($c === "'" || $c === '"' || $c === '`') {
            $i = finDeCadena($texto, $i);
            continue;
        }

        if ($c === '[') {
            $profundidad++;
        } elseif ($c === ']') {
            $profundidad--;

            if ($profundidad === 0) {
                return $i;
            }
        }
    }

    return null;
}

function finDeCadena(string $texto, int $inicio): int
{
    $comilla = $texto[$inicio];
    $largo = strlen($texto);

    for ($i = $inicio + 1; $i < $largo; $i++) {
        if ($texto[$i] === '\\') {
            $i++;
            continue;
        }

        if ($texto[$i] === $comilla) {
            return $i;
        }
    }

    return $largo;
}

function sangriaDeLaLinea(string $texto, int $posicion): string
{
    $inicio = strrpos(substr($texto, 0, $posicion), PHP_EOL);
    $inicio = $inicio === false ? 0 : $inicio + 1;

    preg_match('/^[ \t]*/', substr($texto, $inicio, $posicion - $inicio), $coincidencia);

    return $coincidencia[0] ?? '        ';
}
