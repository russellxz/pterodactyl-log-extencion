#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - limpiar todo y reinstalar de cero
#
#      sudo bash reinstalar-limpio.sh [/ruta/del/panel] [--force]
#
#  Para cuando en el panel te salen apartados repetidos porque conviven la
#  extension ANTIGUA de reverse proxy (la que se instalaba tocando archivos del
#  panel a mano) y la nueva.
#
#  Lo que hace, por orden:
#
#    1. Te dice que restos encuentra de cada version.
#    2. Guarda copia de todo lo que va a tocar.
#    3. Quita la ANTIGUA: sus controladores, su grupo de rutas, su entrada del
#       menu del admin y su pantalla del area de cliente.
#    4. Quita la NUEVA del todo.
#    5. Instala la nueva de cero.
#
#  NO SE BORRA NI UN DNS. Ni la tabla server_proxy, ni tus dominios, ni tus
#  tokens de Cloudflare, ni tus certificados. Al terminar aparece todo tal cual
#  estaba, porque los datos viven en la base de datos y esta no se toca.
# =============================================================================
set -uo pipefail

PANEL="/var/www/pterodactyl"
FORCE=0
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for argumento in "$@"; do
    case "$argumento" in
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

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  DNS Reverse - limpiar las dos versiones y reinstalar%s\n' "$B" "$N"
printf '  ===============================================================\n'

# --- Comprobaciones ---------------------------------------------------------

[ "$(id -u)" -eq 0 ] || { err "Hay que lanzarlo con sudo."; exit 1; }

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n      sudo bash reinstalar-limpio.sh /ruta/del/panel\n\n'
    exit 1
fi

[ -d "$AQUI/panel" ] || { err "Falta la carpeta panel/ junto a este script. ¿Clonaste el repositorio entero?"; exit 1; }

ok "Panel encontrado en $PANEL"

LIMPIADOR="$PANEL/app/Extensions/DnsReverse/tools/limpiar-viejo.php"

# Si la extension nueva no esta puesta, el limpiador se coge del repositorio.
[ -f "$LIMPIADOR" ] || LIMPIADOR="$AQUI/panel/app/Extensions/DnsReverse/tools/limpiar-viejo.php"

# =============================================================================
#  1. Que hay ahora mismo
# =============================================================================

title "1. Mirando que hay instalado"

printf '\n  %sVersion ANTIGUA (reverse proxy a mano)%s\n' "$B" "$N"
php "$LIMPIADOR" "$PANEL" | sed 's/^/  /'

printf '\n  %sVersion NUEVA (DNS Reverse)%s\n' "$B" "$N"

NUEVA=0
[ -d "$PANEL/app/Extensions/DnsReverse" ] && { echo "    - app/Extensions/DnsReverse"; NUEVA=1; }
[ -d "$PANEL/public/extensions/dnsreverse" ] && echo "    - public/extensions/dnsreverse"
grep -q 'DnsReverseServiceProvider' "$PANEL/config/app.php" 2>/dev/null && echo "    - registrada en config/app.php"
[ -d "$PANEL/resources/scripts/components/server/dnsreverse" ] && echo "    - pantalla en components/server/dnsreverse"
[ -d "$PANEL/resources/scripts/components/server/extensions/dnsreverse" ] && echo "    - pantalla en el hueco del tema"
[ -f "$PANEL/resources/views/admin/extensions/dnsreverse.blade.php" ] && echo "    - entrada del admin en el hueco del tema"
grep -q 'DnsReverse NAV' "$PANEL/resources/views/layouts/admin.blade.php" 2>/dev/null && echo "    - entrada del admin en la plantilla"
grep -q 'dnsreverse:inicio' "$PANEL/resources/scripts/routers/routes.ts" 2>/dev/null && echo "    - entrada en routes.ts"

[ "$NUEVA" -eq 0 ] && echo "    (no esta instalada)"

printf '\n  %sLo que NO se toca:%s\n' "$G" "$N"
printf '    - la tabla server_proxy (ahi viven los DNS de tus clientes)\n'
printf '    - tus dominios, tokens de Cloudflare y certificados\n'
printf '    - los limites por servidor\n'
printf '    - la configuracion de nginx de los nodos\n'
printf '\n'

if [ "$FORCE" -ne 1 ]; then
    printf '  ¿Limpiar las dos y reinstalar de cero? [s/N] '
    read -r respuesta
    case "$respuesta" in
        s|S|si|SI|Si|y|Y|yes) ;;
        *) printf '\n  Cancelado. No se ha tocado nada.\n\n'; exit 0 ;;
    esac
fi

# =============================================================================
#  2. Copia de seguridad
# =============================================================================

title "2. Copia de seguridad"

SELLO="$(date +%Y%m%d-%H%M%S)"
COPIA="$PANEL/storage/dnsreverse-copias/$SELLO"
mkdir -p "$COPIA"

cd "$PANEL" || exit 1

tar -czf "$COPIA/archivos.tar.gz" -C "$PANEL" \
    --exclude='./storage/dnsreverse-copias' \
    routes resources/views/layouts resources/scripts/routers config/app.php \
    public/assets 2>/dev/null \
    && ok "Guardados los archivos que se van a tocar ($(du -sh "$COPIA/archivos.tar.gz" | cut -f1))" \
    || warn "No se pudieron guardar todos los archivos"

if command -v mysqldump >/dev/null 2>&1; then
    leer_env() { grep -oE "^$1=.*" "$PANEL/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'"' '; }
    DB_N="$(leer_env DB_DATABASE)"

    if [ -n "${DB_N:-}" ] && MYSQL_PWD="$(leer_env DB_PASSWORD)" mysqldump \
         -h "$(leer_env DB_HOST)" -P "$(leer_env DB_PORT)" -u "$(leer_env DB_USERNAME)" \
         --single-transaction --quick "$DB_N" 2>/dev/null | gzip > "$COPIA/base-de-datos.sql.gz"; then
        ok "Guardada la base de datos ($(du -sh "$COPIA/base-de-datos.sql.gz" | cut -f1))"
    else
        rm -f "$COPIA/base-de-datos.sql.gz"
        warn "No se pudo guardar la base de datos (no es imprescindible: no se toca)"
    fi
fi

ok "Copia completa en $COPIA"

# =============================================================================
#  3. Quitar la version ANTIGUA
# =============================================================================

title "3. Quitando la version antigua"

php "$LIMPIADOR" "$PANEL" --limpiar | sed 's/^/  /'

# =============================================================================
#  4. Quitar la version NUEVA
# =============================================================================

title "4. Quitando la version nueva"

if [ -f "$AQUI/uninstall.sh" ] && [ -d "$PANEL/app/Extensions/DnsReverse" ]; then
    # Sin --borrar-config ni --borrar-dns: los datos se quedan.
    bash "$AQUI/uninstall.sh" "$PANEL" --force 2>&1 | grep -E '^\s+\[' | sed 's/^/  /'
else
    warn "No estaba instalada (o falta uninstall.sh): se limpia a mano"
fi

# Restos que el desinstalador no cubre si venias de una version anterior.
rm -rf "$PANEL/resources/scripts/components/server/dnsreverse" \
       "$PANEL/resources/scripts/components/server/extensions/dnsreverse" \
       "$PANEL/resources/views/admin/extensions/dnsreverse.blade.php" \
       "$PANEL/public/extensions/dnsreverse"

# La entrada que la version anterior metia en la plantilla del admin.
if grep -q 'DnsReverse NAV' "$PANEL/resources/views/layouts/admin.blade.php" 2>/dev/null; then
    php -r '
        $f = $argv[1];
        $s = file_get_contents($f);
        $s = preg_replace("/[ \t]*\{\{--\s*DnsReverse NAV START.*?DnsReverse NAV END\s*--\}\}\R?/s", "", $s);
        file_put_contents($f, $s);
    ' "$PANEL/resources/views/layouts/admin.blade.php" \
        && ok "Quitada la entrada del admin de la plantilla"
fi

ok "Version nueva fuera"

# =============================================================================
#  5. Instalar de cero
# =============================================================================

title "5. Instalando de cero"

if bash "$AQUI/install.sh" "$PANEL"; then
    ok "Instalada"
else
    err "La instalacion no termino bien. Mira los mensajes de arriba."
    printf '\n  Tus datos siguen intactos. Copia por si acaso:  %s\n\n' "$COPIA"
    exit 1
fi

# =============================================================================

printf '\n%s  Listo%s\n' "$G$B" "$N"
printf '  ===============================================================\n'
printf '  Ya solo queda una version, la nueva.\n'
printf '\n'
printf '  Entra en el panel y comprueba que en el menu del admin sale\n'
printf '  %sun solo%s apartado de DNS. Recarga con Ctrl+F5 la primera vez.\n' "$B" "$N"
printf '\n'
printf '  Tus DNS, dominios, tokens y certificados estan donde estaban.\n'
printf '  Copia de seguridad:  %s\n' "$COPIA"
printf '\n'
printf '  Si algo no cuadra:\n'
printf '      cd %s && php artisan dnsreverse:doctor\n' "$PANEL"
printf '\n'
