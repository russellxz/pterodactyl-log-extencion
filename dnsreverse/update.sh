#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - actualizar la extension
#
#  Baja los ultimos cambios del repositorio y los aplica. NO desinstala nada:
#  los dominios de tus clientes, los tokens de Cloudflare, los certificados y
#  la configuracion se quedan exactamente como estan.
#
#      sudo bash update.sh [/ruta/del/panel]
#
#  Por defecto: /var/www/pterodactyl
# =============================================================================
set -uo pipefail

PANEL="${1:-/var/www/pterodactyl}"
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$AQUI/.." && pwd)"

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  DNS Reverse - actualizacion%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n  Uso: sudo bash update.sh /ruta/del/panel\n\n'
    exit 1
fi

ok "Panel encontrado en $PANEL"

# --- 1. Traerse los ultimos cambios ----------------------------------------

title "1. Descargando los ultimos cambios"

if [ -d "$REPO/.git" ]; then
    ANTES="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo '?')"

    if git -C "$REPO" pull --ff-only 2>&1 | sed 's/^/        /'; then
        DESPUES="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo '?')"

        if [ "$ANTES" = "$DESPUES" ]; then
            ok "Ya estabas en la ultima version ($DESPUES)"
        else
            ok "Actualizado: $ANTES -> $DESPUES"
        fi
    else
        warn "git pull no funciono. Se aplicara lo que haya ahora mismo en la carpeta."
        warn "Si tienes cambios propios: git stash && sudo bash update.sh"
    fi
else
    warn "Esta carpeta no es un repositorio git, se aplica su contenido tal cual."
fi

# --- 2. Aplicar --------------------------------------------------------------
# install.sh es idempotente: copia archivos, registra el proveedor si hiciera
# falta y aplica las migraciones nuevas sin tocar los datos que ya existen.

title "2. Aplicando la actualizacion"

if ! bash "$AQUI/install.sh" "$PANEL"; then
    err "La actualizacion no termino bien. Revisa los mensajes de arriba."
    exit 1
fi

# --- 3. Modo nativo ---------------------------------------------------------
#
# Si el boton del cliente estaba compilado dentro del panel, la actualizacion
# trae una version nueva del componente y hay que volver a compilar. Se hace
# solo para no dejar al cliente con la pantalla vieja.

if grep -q 'dnsreverse:inicio' "$PANEL/resources/scripts/routers/routes.ts" 2>/dev/null; then
    title "3. Recompilando el panel (tenias el modo nativo)"

    if ! bash "$AQUI/install-frontend.sh" "$PANEL"; then
        err "No se pudo recompilar el frontend."
        printf '  La extension SI esta actualizada. Mientras tanto, para que tus\n'
        printf '  clientes no se queden sin pantalla:\n'
        printf '      cd %s && php artisan dnsreverse:ui inject\n\n' "$PANEL"
        exit 1
    fi
fi

# --- 4. Resumen --------------------------------------------------------------

printf '\n%s  Extension actualizada%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  No se ha perdido ningun dato: dominios, tokens, certificados y\n'
printf '  DNS de clientes siguen igual.\n'
printf '\n'
printf '  Si esta version trae cambios en el complemento de wings, el panel te\n'
printf '  lo dira en Admin -> DNS Reverse -> Nodos. Para actualizarlo, en cada\n'
printf '  NODO:\n'
printf '      sudo bash %s/wings/install-wings.sh\n' "$AQUI"
printf '\n'
printf '  Si algo no cuadra:\n'
printf '      cd %s && php artisan dnsreverse:doctor\n' "$PANEL"
printf '\n'
