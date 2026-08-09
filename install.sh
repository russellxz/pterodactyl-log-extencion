#!/usr/bin/env bash
# =============================================================================
#  LogsPterodactyl - instalador
#
#  Extension para el panel de Pterodactyl compatible con el tema Arix.
#
#  NO recompila el frontend (nada de yarn ni webpack) y NO reemplaza ningun
#  archivo del panel ni del tema. Por eso no deja el panel en blanco, que es
#  lo que suele pasar con las extensiones que se meten en resources/scripts.
#
#  Uso:
#      sudo bash install.sh [/ruta/del/panel]
#
#  Por defecto: /var/www/pterodactyl
# =============================================================================
set -uo pipefail

PANEL="${1:-/var/www/pterodactyl}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE="$HERE/extension/panel"

# --- Colores (se desactivan si la salida no es un terminal) ------------------
if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  LogsPterodactyl - instalador%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

# --- Comprobaciones previas -------------------------------------------------

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n  Indica la ruta correcta:\n'
    printf '      sudo bash install.sh /ruta/del/panel\n\n'
    exit 1
fi

if [ ! -d "$SOURCE" ]; then
    err "No se encontro la carpeta extension/panel junto a este script."
    printf '  ¿Has clonado el repositorio entero?\n\n'
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    err "No se encontro el comando php."
    exit 1
fi

ok "Panel encontrado en $PANEL"

# La version se lee con una expresion regular y no incluyendo el archivo:
# config/app.php usa helpers de Laravel (env(), etc.) que fuera del panel no
# existen, asi que un include suelto siempre falla.
PANEL_VERSION="$(grep -oE "'version'[[:space:]]*=>[[:space:]]*'[^']*'" "$PANEL/config/app.php" 2>/dev/null | head -1 | sed "s/.*'\\([^']*\\)'$/\\1/")"
ok "Version del panel: ${PANEL_VERSION:-desconocida}"

if [ -d "$PANEL/arix" ] || [ -f "$PANEL/config/arix.php" ]; then
    ok "Tema Arix detectado (la extension se integra con el)"
else
    warn "Tema Arix no detectado (la extension funciona igual)"
fi

# --- 1. Copiar los archivos -------------------------------------------------

title "1. Copiando los archivos"

# --- Traspaso desde la version anterior (se llamaba ArixLog) ---------------
# Se quita lo viejo ANTES de copiar lo nuevo. Las tablas no se tocan aqui: las
# renombra una migracion de la propia extension conservando el contenido.

if [ -d "$PANEL/app/Extensions/ArixLog" ] || grep -q 'ArixLogServiceProvider' "$PANEL/config/app.php" 2>/dev/null; then
    warn "Detectada la version anterior (ArixLog). Traspasando..."

    if [ -f "$PANEL/app/Extensions/ArixLog/tools/register-provider.php" ]; then
        php "$PANEL/app/Extensions/ArixLog/tools/register-provider.php" "$PANEL" --remove >/dev/null 2>&1
    fi

    # Por si el script anterior ya no estuviera: se limpia igualmente.
    if grep -q 'ArixLogServiceProvider' "$PANEL/config/app.php" 2>/dev/null; then
        cp "$PANEL/config/app.php" "$PANEL/config/app.php.antes-del-traspaso"
        sed -i '/ArixLog START/,/ArixLog END/d; /ArixLogServiceProvider/d' "$PANEL/config/app.php"

        if php -l "$PANEL/config/app.php" >/dev/null 2>&1; then
            rm -f "$PANEL/config/app.php.antes-del-traspaso"
        else
            mv "$PANEL/config/app.php.antes-del-traspaso" "$PANEL/config/app.php"
            err "No se pudo quitar la linea vieja de config/app.php. Quitala a mano."
        fi
    fi

    rm -rf "$PANEL/app/Extensions/ArixLog" "$PANEL/public/extensions/arixlog"
    rm -f "$PANEL/config/app.php.arixlog-backup"
    ok "Version anterior retirada (los datos se conservan y se traspasan solos)"
fi

mkdir -p "$PANEL/app/Extensions" "$PANEL/public/extensions"

# Se borra la version anterior de la carpeta de la extension para que no
# queden archivos sueltos de una version antigua. Solo se toca esa carpeta.
rm -rf "$PANEL/app/Extensions/LogsPterodactyl"

if cp -r "$SOURCE/app/Extensions/LogsPterodactyl" "$PANEL/app/Extensions/LogsPterodactyl"; then
    ok "Codigo copiado a app/Extensions/LogsPterodactyl"
else
    err "No se pudo copiar el codigo. ¿Estas ejecutando con sudo?"
    exit 1
fi

if cp -r "$SOURCE/public/extensions/logspterodactyl" "$PANEL/public/extensions/"; then
    ok "Recursos copiados a public/extensions/logspterodactyl"
else
    err "No se pudieron copiar los recursos publicos."
    exit 1
fi

# Carpeta donde el actualizador escribe su progreso. Tiene que existir y ser
# escribible por el usuario del servidor web, porque durante la actualizacion
# el panel esta en mantenimiento y esa carpeta se lee como archivo estatico.
mkdir -p "$PANEL/public/extensions/logspterodactyl/runs"

# --- 2. Registrar el proveedor ---------------------------------------------

title "2. Registrando la extension en el panel"

if php "$PANEL/app/Extensions/LogsPterodactyl/tools/register-provider.php" "$PANEL"; then
    ok "Proveedor registrado en config/app.php"
else
    err "No se pudo registrar el proveedor en config/app.php"
    printf '  Anade esta linea a mano dentro del array providers:\n'
    printf '      Pterodactyl\\Extensions\\LogsPterodactyl\\LogsPterodactylServiceProvider::class,\n\n'
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

title "4. Creando las tablas"

if php artisan logspterodactyl:install; then
    ok "Base de datos lista"
else
    err "Fallo al preparar la base de datos."
    printf '  Prueba a ejecutarlo a mano para ver el error:\n'
    printf '      cd %s && php artisan logspterodactyl:install\n\n' "$PANEL"
    exit 1
fi

# --- 5. Permisos ------------------------------------------------------------

title "5. Ajustando permisos"

bash "$HERE/permissions.sh" "$PANEL" || warn "Algunos permisos no se pudieron ajustar (vuelve a lanzarlo con sudo)"

# --- 6. Comprobacion final --------------------------------------------------

title "6. Comprobacion final"

php artisan logspterodactyl:doctor || true

# --- Resumen ----------------------------------------------------------------

# APP_URL sale del .env, que si es un archivo plano facil de leer.
PANEL_URL="$(grep -oE '^APP_URL=.*' "$PANEL/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'"'"' ' | sed 's#/$##')"

printf '\n%s  Instalacion terminada%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  Panel de la extension:  %s/admin/logspterodactyl\n' "${PANEL_URL:-https://TU-PANEL}"
printf '\n'
printf '  Siguiente paso importante:\n'
printf '    Entra en Configuracion y activa el sistema automatico de\n'
printf '    instalaciones, eligiendo cuantos minutos esperar (5, 10, 15...).\n'
printf '\n'
printf '  Comprueba que el cron del panel esta activo (si no, no funcionara\n'
printf '  ni el corte automatico ni el consumo de recursos):\n'
printf '    * * * * * php %s/artisan schedule:run >> /dev/null 2>&1\n' "$PANEL"
printf '\n'
printf '  Para desinstalar:\n'
printf '    sudo bash %s/uninstall.sh %s\n' "$HERE" "$PANEL"
printf '\n'
