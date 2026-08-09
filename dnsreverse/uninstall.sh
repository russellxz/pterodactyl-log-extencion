#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - desinstalador
#
#  Quita la extension del panel dejandolo como estaba.
#
#  POR DEFECTO NO BORRA NINGUN DATO.
#
#  Ni los DNS de tus clientes, ni tus dominios, ni tus tokens de Cloudflare, ni
#  tus certificados. Solo se quitan los archivos de la extension y su linea de
#  config/app.php. Asi:
#
#    - los dominios siguen funcionando en los nodos mientras la extension no
#      esta puesta (nginx no depende del panel para servirlos);
#    - cuando vuelvas a instalar, TODO reaparece tal cual estaba y ningun
#      cliente tiene que volver a crear nada.
#
#  Uso:
#      sudo bash uninstall.sh [/ruta/del/panel] [--borrar-config] [--borrar-dns] [--force]
#
#      --borrar-config  Borra ademas dominios, tokens, certificados y ajustes.
#      --borrar-dns     Borra ademas los DNS de los clientes. Piensalo dos veces.
#      --force          No pregunta nada.
# =============================================================================
set -uo pipefail

PANEL="/var/www/pterodactyl"
BORRAR_CONFIG=0
BORRAR_DNS=0
FORCE=0
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for argumento in "$@"; do
    case "$argumento" in
        --borrar-config) BORRAR_CONFIG=1 ;;
        --borrar-dns) BORRAR_DNS=1 ;;
        --force) FORCE=1 ;;
        -*) printf 'Opcion desconocida: %s\n' "$argumento"; exit 1 ;;
        *) PANEL="${argumento%/}" ;;
    esac
done

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()   { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn() { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()  { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }

printf '\n%s  DNS Reverse - desinstalador%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n  Uso: sudo bash uninstall.sh /ruta/del/panel\n\n'
    exit 1
fi

ok "Panel encontrado en $PANEL"

printf '\n  Se va a quitar:\n'
printf '    - %s/app/Extensions/DnsReverse\n' "$PANEL"
printf '    - %s/public/extensions/dnsreverse\n' "$PANEL"
printf '    - la linea de DnsReverse en config/app.php\n'

printf '\n  Lo que se CONSERVA:\n'

if [ "$BORRAR_DNS" -eq 1 ]; then
    printf '    %s- los DNS de los clientes NO se conservan (--borrar-dns)%s\n' "$R" "$N"
else
    printf '    - los DNS de tus clientes (tabla server_proxy)\n'
fi

if [ "$BORRAR_CONFIG" -eq 1 ]; then
    printf '    %s- los dominios, tokens y certificados NO se conservan (--borrar-config)%s\n' "$R" "$N"
else
    printf '    - tus dominios, tokens de Cloudflare y certificados\n'
    printf '    - la configuracion de la extension\n'
fi

printf '    - la configuracion de nginx y los certificados de los nodos\n'
printf '    - los servidores, sus archivos y sus puertos\n'
printf '\n'

if [ "$BORRAR_CONFIG" -eq 0 ] && [ "$BORRAR_DNS" -eq 0 ]; then
    printf '  Al reinstalar volvera a aparecer todo tal cual esta ahora.\n\n'
fi

if [ "$BORRAR_DNS" -eq 1 ]; then
    printf '  %sATENCION:%s con --borrar-dns el panel se olvidara de todos los dominios.\n' "$R$B" "$N"
    printf '  Seguiran montados en los nodos pero ya no se podran gestionar desde aqui,\n'
    printf '  y tus clientes tendran que crearlo todo de nuevo.\n\n'
fi

if [ "$FORCE" -ne 1 ]; then
    printf '  ¿Continuar? [s/N] '
    read -r respuesta
    case "$respuesta" in
        s|S|si|SI|Si|y|Y|yes) ;;
        *) printf '\n  Cancelado. No se ha tocado nada.\n\n'; exit 0 ;;
    esac
fi

cd "$PANEL" || exit 1

# --- 1. Base de datos (antes de borrar los archivos: el comando vive en ellos)

ARGUMENTOS="--force"

if [ "$BORRAR_CONFIG" -eq 1 ]; then
    ARGUMENTOS="$ARGUMENTOS --borrar-config"
fi

if [ "$BORRAR_DNS" -eq 1 ]; then
    ARGUMENTOS="$ARGUMENTOS --borrar-dns"
fi

# shellcheck disable=SC2086
if php artisan dnsreverse:uninstall $ARGUMENTOS; then
    ok "Base de datos revisada"
else
    warn "El comando de base de datos no termino bien (los datos siguen ahi)"
fi

# --- 2. Quitar el registro del proveedor ------------------------------------

if [ -f "$PANEL/app/Extensions/DnsReverse/tools/register-provider.php" ]; then
    if php "$PANEL/app/Extensions/DnsReverse/tools/register-provider.php" "$PANEL" --remove >/dev/null 2>&1; then
        ok "Linea de DnsReverse quitada de config/app.php"
    else
        err "No se pudo editar config/app.php"
        printf '        Quita a mano la linea que contiene DnsReverseServiceProvider.\n'
    fi
else
    # Respaldo por si los archivos ya no estan.
    if grep -q 'DnsReverseServiceProvider' "$PANEL/config/app.php" 2>/dev/null; then
        cp "$PANEL/config/app.php" "$PANEL/config/app.php.dnsreverse-antes-de-desinstalar"
        sed -i '/DnsReverse START/,/DnsReverse END/d; /DnsReverseServiceProvider/d' "$PANEL/config/app.php"

        if php -l "$PANEL/config/app.php" >/dev/null 2>&1; then
            ok "Linea de DnsReverse quitada de config/app.php"
        else
            mv "$PANEL/config/app.php.dnsreverse-antes-de-desinstalar" "$PANEL/config/app.php"
            err "El archivo quedaba invalido, se ha restaurado. Quita la linea a mano."
        fi
    else
        ok "config/app.php ya estaba limpio"
    fi
fi

# --- 3. Deshacer el modo nativo (si estaba) ---------------------------------
#
# Si el boton se habia compilado dentro del panel, hay que quitar el import de
# routes.ts ANTES de borrar los archivos. Si no, el siguiente "yarn build" del
# panel fallaria buscando un componente que ya no existe.

RECOMPILAR=0

if grep -q 'dnsreverse:inicio' "$PANEL/resources/scripts/routers/routes.ts" 2>/dev/null \
   || grep -q 'dnsreverse:inicio' "$PANEL/resources/scripts/routers/routes.tsx" 2>/dev/null; then

    RECOMPILAR=1

    if [ -f "$PANEL/app/Extensions/DnsReverse/tools/patch-frontend.php" ] \
       && php "$PANEL/app/Extensions/DnsReverse/tools/patch-frontend.php" "$PANEL" --remove >/dev/null 2>&1; then
        ok "Modo nativo deshecho en routes.ts"
    else
        # Respaldo: la copia que dejo el parche es el original exacto.
        for archivo in "$PANEL/resources/scripts/routers/routes.ts" "$PANEL/resources/scripts/routers/routes.tsx"; do
            if [ -f "$archivo.dnsreverse-original" ]; then
                cp "$archivo.dnsreverse-original" "$archivo" && ok "routes.ts restaurado desde la copia original"
            fi
        done
    fi

    rm -rf "$PANEL/resources/scripts/components/server/dnsreverse"
    rm -f "$PANEL/resources/scripts/routers/routes.ts.dnsreverse-original" \
          "$PANEL/resources/scripts/routers/routes.tsx.dnsreverse-original"
fi

# --- 4. Borrar los archivos -------------------------------------------------

rm -rf "$PANEL/app/Extensions/DnsReverse" && ok "Codigo borrado" || err "No se pudo borrar app/Extensions/DnsReverse"
rm -rf "$PANEL/public/extensions/dnsreverse" && ok "Recursos publicos borrados" || err "No se pudo borrar public/extensions/dnsreverse"

# Si las carpetas se quedan vacias se quitan; si hay otras extensiones se
# respetan.
rmdir "$PANEL/app/Extensions" 2>/dev/null || true
rmdir "$PANEL/public/extensions" 2>/dev/null || true

rm -f "$PANEL/config/app.php.dnsreverse-backup" "$PANEL/config/app.php.dnsreverse-antes-de-desinstalar"

# --- 5. Limpiar caches ------------------------------------------------------

rm -f "$PANEL"/bootstrap/cache/config.php "$PANEL"/bootstrap/cache/services.php "$PANEL"/bootstrap/cache/packages.php 2>/dev/null

php artisan config:clear >/dev/null 2>&1 && ok "Cache de configuracion limpiada" || warn "No se pudo limpiar la cache de configuracion"
php artisan view:clear   >/dev/null 2>&1 && ok "Cache de vistas limpiada"        || warn "No se pudo limpiar la cache de vistas"
php artisan route:clear  >/dev/null 2>&1 && ok "Cache de rutas limpiada"         || warn "No se pudo limpiar la cache de rutas"

if command -v composer >/dev/null 2>&1; then
    COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload -o --no-interaction >/dev/null 2>&1 \
        && ok "Autocarga regenerada" || warn "composer dump-autoload fallo (no es grave)"
fi

# --- Resumen ----------------------------------------------------------------

printf '\n%s  DNS Reverse desinstalado%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'

if [ "$BORRAR_DNS" -eq 1 ]; then
    printf '  Se han borrado tambien los DNS de los clientes, tal y como pediste.\n'
elif [ "$BORRAR_CONFIG" -eq 1 ]; then
    printf '  Los DNS de tus clientes se han conservado; la configuracion no.\n'
else
    printf '  No se ha borrado ningun dato. Para volver a tenerlo todo:\n'
    printf '      sudo bash %s/install.sh %s\n' "$AQUI" "$PANEL"
fi

if [ "$RECOMPILAR" -eq 1 ]; then
    printf '\n'
    printf '  %sTenias el modo nativo (compilado).%s Se ha quitado de routes.ts, pero\n' "$Y$B" "$N"
    printf '  el boton seguira saliendo hasta que recompiles el panel:\n'
    printf '      cd %s && yarn build:production\n' "$PANEL"
    printf '  Si no lo recompilas no pasa nada grave: el boton dara una pantalla\n'
    printf '  vacia porque la extension ya no responde.\n'
fi

printf '\n'
printf '  El complemento de wings de los nodos NO se ha tocado. Si tambien\n'
printf '  quieres quitarlo, en cada nodo restaura la copia que dejo el\n'
printf '  instalador:\n'
printf '      ls /usr/local/bin/wings.antes-de-dnsreverse.*\n'
printf '      systemctl stop wings\n'
printf '      install -m 0755 /usr/local/bin/wings.antes-de-dnsreverse.XXXX /usr/local/bin/wings\n'
printf '      systemctl start wings\n'
printf '\n'
