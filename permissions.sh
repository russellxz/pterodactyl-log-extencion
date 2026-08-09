#!/usr/bin/env bash
# =============================================================================
#  LogsPterodactyl - permisos
#
#  Deja el panel con los permisos que necesita para funcionar. Lo llaman
#  install.sh y update.sh, pero se puede ejecutar suelto cuando algo huele a
#  permisos (errores de "Permission denied" en el log, la carpeta de respaldos
#  en rojo en el actualizador, el visor de logs vacio...).
#
#      sudo bash permissions.sh [/ruta/del/panel]
#
#  Por defecto: /var/www/pterodactyl
# =============================================================================
set -uo pipefail

PANEL="${1:-/var/www/pterodactyl}"
BACKUP_DIR="${2:-/var/backups/logspterodactyl}"

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()   { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn() { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()  { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    exit 1
fi

# --- Usuario del servidor web -----------------------------------------------
# Se busca cual de los tres habituales existe en el sistema. Si el panel ya
# tiene archivos, se prefiere el dueno real de public/index.php, que es el dato
# mas fiable de todos.

WEB_USER=""

if [ -f "$PANEL/public/index.php" ]; then
    DETECTED="$(stat -c '%U' "$PANEL/public/index.php" 2>/dev/null || echo '')"
    if [ -n "$DETECTED" ] && [ "$DETECTED" != "root" ] && id "$DETECTED" >/dev/null 2>&1; then
        WEB_USER="$DETECTED"
    fi
fi

if [ -z "$WEB_USER" ]; then
    for candidate in www-data nginx apache http; do
        if id "$candidate" >/dev/null 2>&1; then
            WEB_USER="$candidate"
            break
        fi
    done
fi

if [ -z "$WEB_USER" ]; then
    err "No se pudo identificar el usuario del servidor web."
    printf '        Ajusta los permisos a mano con el usuario que uses.\n'
    exit 1
fi

WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || echo "$WEB_USER")"
ok "Usuario del servidor web: $WEB_USER:$WEB_GROUP"

if [ "$(id -u)" != "0" ]; then
    warn "No estas ejecutando como root: es probable que varios cambios fallen."
    warn "Vuelve a lanzarlo con sudo si ves avisos debajo."
fi

# --- 1. Carpetas de la extension --------------------------------------------

for dir in "$PANEL/app/Extensions/LogsPterodactyl" "$PANEL/public/extensions/logspterodactyl"; do
    if [ -d "$dir" ]; then
        chown -R "$WEB_USER:$WEB_GROUP" "$dir" 2>/dev/null \
            && chmod -R u=rwX,g=rX,o=rX "$dir" 2>/dev/null \
            && ok "Permisos de $(basename "$dir")" \
            || warn "No se pudieron ajustar los permisos de $dir"
    fi
done

# El actualizador escribe aqui el progreso desde el navegador, asi que esta
# carpeta si tiene que ser escribible por el servidor web.
RUNS="$PANEL/public/extensions/logspterodactyl/runs"
mkdir -p "$RUNS" 2>/dev/null
chown -R "$WEB_USER:$WEB_GROUP" "$RUNS" 2>/dev/null && chmod 755 "$RUNS" 2>/dev/null \
    && ok "Carpeta de progreso del actualizador" \
    || warn "No se pudo preparar $RUNS"

# Y aqui se guardan los logos que se suban para los correos.
LOGOS="$PANEL/public/extensions/logspterodactyl/logos"
mkdir -p "$LOGOS" 2>/dev/null
chown -R "$WEB_USER:$WEB_GROUP" "$LOGOS" 2>/dev/null && chmod 755 "$LOGOS" 2>/dev/null \
    && ok "Carpeta de logos para los correos" \
    || warn "No se pudo preparar $LOGOS"

# --- 2. Carpetas del propio panel -------------------------------------------
# Esto no es cosa de la extension, es la receta oficial de Pterodactyl. Se
# incluye porque un panel con storage/framework/cache sin permisos suelta
# errores de "Permission denied" por todos lados, y como la extension es la
# que los ENSENA, parece que los causa ella.

for dir in "$PANEL/storage" "$PANEL/bootstrap/cache"; do
    if [ -d "$dir" ]; then
        chown -R "$WEB_USER:$WEB_GROUP" "$dir" 2>/dev/null \
            && chmod -R 755 "$dir" 2>/dev/null \
            && ok "Permisos de ${dir#"$PANEL"/}" \
            || warn "No se pudieron ajustar los permisos de $dir"
    fi
done

# Comprobacion real: se intenta escribir de verdad como el usuario web.
check_writable() {
    local dir="$1"
    if [ ! -d "$dir" ]; then
        return 0
    fi
    if sudo -u "$WEB_USER" test -w "$dir" 2>/dev/null; then
        ok "$WEB_USER puede escribir en ${dir#"$PANEL"/}"
    else
        err "$WEB_USER NO puede escribir en $dir"
    fi
}

check_writable "$PANEL/storage/logs"
check_writable "$PANEL/storage/framework/cache"
check_writable "$PANEL/storage/framework/sessions"
check_writable "$PANEL/storage/framework/views"
check_writable "$PANEL/bootstrap/cache"

# --- 3. Carpeta de respaldos ------------------------------------------------
# Guarda un volcado de la base de datos y un archivo con la contrasena de
# MySQL, asi que va con permisos 700 y en propiedad del usuario web: solo el
# (y root) pueden entrar.

mkdir -p "$BACKUP_DIR" 2>/dev/null
if [ -d "$BACKUP_DIR" ]; then
    chown -R "$WEB_USER:$WEB_GROUP" "$BACKUP_DIR" 2>/dev/null
    chmod 700 "$BACKUP_DIR" 2>/dev/null
    if sudo -u "$WEB_USER" test -w "$BACKUP_DIR" 2>/dev/null; then
        ok "Carpeta de respaldos escribible: $BACKUP_DIR"
    else
        err "$WEB_USER no puede escribir en $BACKUP_DIR"
        printf '        Prueba: sudo chown -R %s:%s %s\n' "$WEB_USER" "$WEB_GROUP" "$BACKUP_DIR"
    fi
else
    err "No se pudo crear $BACKUP_DIR"
    printf '        Creala a mano o cambia la ruta en la configuracion de la extension.\n'
fi

# --- 4. El cron -------------------------------------------------------------

if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    ok "El cron del panel esta puesto (para root)"
elif sudo -u "$WEB_USER" crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    ok "El cron del panel esta puesto (para $WEB_USER)"
else
    warn "No se encontro el cron del panel. Sin el no funciona ni el corte"
    warn "automatico de instalaciones ni el consumo de recursos. Ponlo con:"
    printf '\n        (crontab -l 2>/dev/null; echo "* * * * * php %s/artisan schedule:run >> /dev/null 2>&1") | crontab -\n\n' "$PANEL"
fi

printf '\n  Permisos revisados.\n'
