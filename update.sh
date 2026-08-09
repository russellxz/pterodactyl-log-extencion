#!/usr/bin/env bash
# =============================================================================
#  LogsPterodactyl - actualizar la extension
#
#  Baja los ultimos cambios, los aplica y vuelve a dejar los permisos bien.
#  NO desinstala nada: la configuracion, el historial de instalaciones, los
#  correos y las muestras de consumo se quedan como estan.
#
#      sudo bash update.sh [/ruta/del/panel]
#
#  Por defecto: /var/www/pterodactyl
# =============================================================================
set -uo pipefail

PANEL="${1:-/var/www/pterodactyl}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  LogsPterodactyl - actualizacion%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n  Uso: sudo bash update.sh /ruta/del/panel\n\n'
    exit 1
fi

ok "Panel encontrado en $PANEL"

# --- 1. Traerse los ultimos cambios ----------------------------------------

title "1. Descargando los ultimos cambios"

if [ -d "$HERE/.git" ]; then
    ANTES="$(git -C "$HERE" rev-parse --short HEAD 2>/dev/null || echo '?')"

    if git -C "$HERE" pull --ff-only 2>&1 | sed 's/^/        /'; then
        DESPUES="$(git -C "$HERE" rev-parse --short HEAD 2>/dev/null || echo '?')"

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
# falta y aplica las migraciones nuevas sin tocar los datos existentes.

title "2. Aplicando la actualizacion"

if bash "$HERE/install.sh" "$PANEL"; then
    :
else
    err "La actualizacion no termino bien. Revisa los mensajes de arriba."
    exit 1
fi

# --- 3. Resumen --------------------------------------------------------------

printf '\n%s  Extension actualizada%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  Los permisos se han vuelto a aplicar y las migraciones nuevas\n'
printf '  ya estan puestas. No se ha perdido ningun dato.\n'
printf '\n'
printf '  Si algo no cuadra:\n'
printf '    cd %s && php artisan logspterodactyl:doctor\n' "$PANEL"
printf '\n'
