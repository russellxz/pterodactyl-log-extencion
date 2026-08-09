#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - permisos
#
#  Deja la extension con los permisos que necesita. Lo llaman install.sh y
#  update.sh, pero se puede ejecutar suelto cuando algo huele a permisos
#  (pantallas en blanco, errores de "Permission denied" en el log del panel).
#
#      sudo bash permissions.sh [/ruta/del/panel]
#
#  Por defecto: /var/www/pterodactyl
# =============================================================================
set -uo pipefail

PANEL="${1:-/var/www/pterodactyl}"

if [ -t 1 ]; then
    G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    G=''; Y=''; R=''; N=''
fi

ok()   { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn() { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()  { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    exit 1
fi

# --- Usuario del servidor web -----------------------------------------------
# Se prefiere el dueno real de public/index.php, que es el dato mas fiable.

WEB_USER=""

if [ -f "$PANEL/public/index.php" ]; then
    DETECTADO="$(stat -c '%U' "$PANEL/public/index.php" 2>/dev/null || echo '')"
    if [ -n "$DETECTADO" ] && [ "$DETECTADO" != "root" ] && id "$DETECTADO" >/dev/null 2>&1; then
        WEB_USER="$DETECTADO"
    fi
fi

if [ -z "$WEB_USER" ]; then
    for candidato in www-data nginx apache http; do
        if id "$candidato" >/dev/null 2>&1; then
            WEB_USER="$candidato"
            break
        fi
    done
fi

if [ -z "$WEB_USER" ]; then
    err "No se pudo identificar el usuario del servidor web."
    exit 1
fi

WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || echo "$WEB_USER")"
ok "Usuario del servidor web: $WEB_USER:$WEB_GROUP"

if [ "$(id -u)" != "0" ]; then
    warn "No estas ejecutando como root: es probable que varios cambios fallen."
fi

# --- Carpetas de la extension -----------------------------------------------

for carpeta in "$PANEL/app/Extensions/DnsReverse" "$PANEL/public/extensions/dnsreverse"; do
    if [ -d "$carpeta" ]; then
        chown -R "$WEB_USER:$WEB_GROUP" "$carpeta" 2>/dev/null \
            && chmod -R u=rwX,g=rX,o=rX "$carpeta" 2>/dev/null \
            && ok "Permisos de $(basename "$carpeta")" \
            || warn "No se pudieron ajustar los permisos de $carpeta"
    fi
done

# --- Carpetas del propio panel ----------------------------------------------
# No es cosa de la extension, es la receta oficial de Pterodactyl. Se incluye
# porque un panel con storage sin permisos falla por todos lados.

for carpeta in "$PANEL/storage" "$PANEL/bootstrap/cache"; do
    if [ -d "$carpeta" ]; then
        chown -R "$WEB_USER:$WEB_GROUP" "$carpeta" 2>/dev/null \
            && chmod -R 755 "$carpeta" 2>/dev/null \
            && ok "Permisos de ${carpeta#"$PANEL"/}" \
            || warn "No se pudieron ajustar los permisos de $carpeta"
    fi
done

comprobar_escritura() {
    local carpeta="$1"
    if [ ! -d "$carpeta" ]; then
        return 0
    fi
    if sudo -u "$WEB_USER" test -w "$carpeta" 2>/dev/null; then
        ok "$WEB_USER puede escribir en ${carpeta#"$PANEL"/}"
    else
        err "$WEB_USER NO puede escribir en $carpeta"
    fi
}

comprobar_escritura "$PANEL/storage/logs"
comprobar_escritura "$PANEL/storage/framework/cache"
comprobar_escritura "$PANEL/bootstrap/cache"

# --- El cron ----------------------------------------------------------------

if crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    ok "El cron del panel esta puesto (para root)"
elif sudo -u "$WEB_USER" crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    ok "El cron del panel esta puesto (para $WEB_USER)"
else
    warn "No se encontro el cron del panel. Sin el, los certificados automaticos"
    warn "no se renovaran y las paginas de tus clientes caducaran a los 90 dias:"
    printf '\n        (crontab -l 2>/dev/null; echo "* * * * * php %s/artisan schedule:run >> /dev/null 2>&1") | crontab -\n\n' "$PANEL"
fi

printf '\n  Permisos revisados.\n'
