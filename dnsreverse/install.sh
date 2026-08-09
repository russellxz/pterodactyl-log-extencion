#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - instalador (parte del panel)
#
#  Extension de Pterodactyl para que tus clientes pongan un dominio propio o un
#  subdominio tuyo a su servidor.
#
#  LO IMPORTANTE:
#    - NO recompila el frontend (nada de yarn ni webpack).
#    - NO reemplaza ningun archivo del panel ni del tema.
#    - NO borra ningun DNS. Si ya tenias la version antigua instalada, los
#      dominios de tus clientes siguen donde estaban y aparecen solos.
#    - Se puede ejecutar las veces que haga falta: es el mismo comando para
#      instalar y para actualizar.
#
#  Uso:
#      sudo bash install.sh [/ruta/del/panel]
#
#  Por defecto: /var/www/pterodactyl
# =============================================================================
set -uo pipefail

PANEL="${1:-/var/www/pterodactyl}"
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORIGEN="$AQUI/panel"

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  DNS Reverse - instalador%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

# --- Comprobaciones previas -------------------------------------------------

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n  Indica la ruta correcta:\n'
    printf '      sudo bash install.sh /ruta/del/panel\n\n'
    exit 1
fi

if [ ! -d "$ORIGEN" ]; then
    err "No se encontro la carpeta panel/ junto a este script."
    printf '  ¿Has clonado el repositorio entero?\n\n'
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    err "No se encontro el comando php."
    exit 1
fi

ok "Panel encontrado en $PANEL"

PANEL_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']*'" "$PANEL/config/app.php" 2>/dev/null | head -1 | sed "s/.*'\\([^']*\\)'$/\\1/")"
ok "Version del panel: ${PANEL_VERSION:-desconocida}"

if [ -d "$PANEL/arix" ] || [ -f "$PANEL/config/arix.php" ]; then
    ok "Tema Arix detectado (la extension se integra con el)"
fi

# --- Aviso si estaba la version antigua -------------------------------------

VIEJA=0

if [ -f "$PANEL/app/Http/Controllers/Admin/ProxySettingsController.php" ] \
   || [ -f "$PANEL/app/Models/ServerProxy.php" ]; then
    VIEJA=1
    warn "Detectada la version antigua (la que se instalaba tocando archivos del panel)."
    warn "No se va a borrar nada suyo: las dos pueden convivir sin problema."
    warn "Tus dominios ya creados apareceran en la extension nueva tal cual."
fi

# --- 1. Copiar los archivos -------------------------------------------------

title "1. Copiando los archivos"

mkdir -p "$PANEL/app/Extensions" "$PANEL/public/extensions"

# Se borra solo NUESTRA carpeta, para que no queden archivos sueltos de una
# version anterior de la extension. Nada mas se toca.
rm -rf "$PANEL/app/Extensions/DnsReverse"

if cp -r "$ORIGEN/app/Extensions/DnsReverse" "$PANEL/app/Extensions/DnsReverse"; then
    ok "Codigo copiado a app/Extensions/DnsReverse"
else
    err "No se pudo copiar el codigo. ¿Estas ejecutando con sudo?"
    exit 1
fi

rm -rf "$PANEL/public/extensions/dnsreverse"

if cp -r "$ORIGEN/public/extensions/dnsreverse" "$PANEL/public/extensions/"; then
    ok "Recursos copiados a public/extensions/dnsreverse"
else
    err "No se pudieron copiar los recursos publicos."
    exit 1
fi

# --- 2. Registrar el proveedor ---------------------------------------------

title "2. Registrando la extension en el panel"

if php "$PANEL/app/Extensions/DnsReverse/tools/register-provider.php" "$PANEL"; then
    ok "Proveedor registrado en config/app.php"
else
    err "No se pudo registrar el proveedor en config/app.php"
    printf '  Anade esta linea a mano dentro del array providers:\n'
    printf '      Pterodactyl\\Extensions\\DnsReverse\\DnsReverseServiceProvider::class,\n\n'
    exit 1
fi

# --- 3. Autocarga y cache ---------------------------------------------------

title "3. Preparando el panel"

cd "$PANEL" || exit 1

if command -v composer >/dev/null 2>&1; then
    if COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload -o --no-interaction >/dev/null 2>&1; then
        ok "Autocarga de clases regenerada"
    else
        warn "composer dump-autoload fallo (no suele hacer falta, se continua)"
    fi
else
    warn "composer no esta instalado (no suele hacer falta, se continua)"
fi

php artisan config:clear >/dev/null 2>&1 && ok "Cache de configuracion limpiada" || warn "No se pudo limpiar la cache de configuracion"
php artisan view:clear   >/dev/null 2>&1 && ok "Cache de vistas limpiada"        || warn "No se pudo limpiar la cache de vistas"
php artisan route:clear  >/dev/null 2>&1 && ok "Cache de rutas limpiada"         || warn "No se pudo limpiar la cache de rutas"

# --- 4. Base de datos -------------------------------------------------------

title "4. Preparando la base de datos"

if php artisan dnsreverse:install; then
    ok "Base de datos lista"
else
    err "Fallo al preparar la base de datos."
    printf '  Pruebalo a mano para ver el error:\n'
    printf '      cd %s && php artisan dnsreverse:install\n\n' "$PANEL"
    exit 1
fi

# --- 5. Permisos ------------------------------------------------------------

title "5. Ajustando permisos"

bash "$AQUI/permissions.sh" "$PANEL" || warn "Algunos permisos no se pudieron ajustar (vuelve a lanzarlo con sudo)"

# --- 6. Comprobacion final --------------------------------------------------

title "6. Comprobacion final"

php artisan dnsreverse:doctor || true

# --- Resumen ----------------------------------------------------------------

PANEL_URL="$(grep -oE '^APP_URL=.*' "$PANEL/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'"' ' | sed 's#/$##')"

printf '\n%s  Instalacion terminada%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  Panel de la extension:  %s/admin/dnsreverse\n' "${PANEL_URL:-https://TU-PANEL}"
printf '\n'
printf '  Siguientes pasos:\n'
printf '    1. Dominios -> Anadir dominio, con su token de Cloudflare\n'
printf '       (cada dominio lleva SU token y SU certificado: puedes mezclar\n'
printf '        dominios de cuentas de Cloudflare distintas).\n'
printf '\n'
printf '    2. Instala el complemento en CADA nodo (por SSH, en el nodo):\n'
printf '         sudo bash %s/wings/install-wings.sh\n' "$AQUI"
printf '\n'
printf '    3. Comprueba que el cron del panel esta puesto (renueva los\n'
printf '       certificados automaticos antes de que caduquen):\n'
printf '         * * * * * php %s/artisan schedule:run >> /dev/null 2>&1\n' "$PANEL"
printf '\n'

if [ "$VIEJA" -eq 1 ]; then
    printf '  Venias de la version antigua: tus DNS estan intactos y ya se ven en\n'
    printf '  la pestana "DNS de clientes". Para asegurarte de que los nodos tienen\n'
    printf '  la configuracion al dia:\n'
    printf '      cd %s && php artisan dnsreverse:sync\n' "$PANEL"
    printf '\n'
fi

printf '  Para desinstalar (sin perder los DNS de los clientes):\n'
printf '    sudo bash %s/uninstall.sh %s\n' "$AQUI" "$PANEL"
printf '\n'
