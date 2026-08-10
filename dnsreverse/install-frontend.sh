#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - modo nativo (yarn build)
#
#  Deja el boton "DNS Reverse" del area de cliente COMPILADO dentro del panel,
#  igual que Consola, Archivos o Copias. Es como lo hacia la extension antigua.
#
#  Diferencia con el modo normal:
#
#    modo inyectado (por defecto)   el boton se anade desde el servidor, sin
#                                   compilar nada. Se instala en 10 segundos y
#                                   sobrevive a las actualizaciones del panel.
#
#    modo nativo (este script)      el boton forma parte del panel. Hay que
#                                   compilar (tarda unos minutos y pide RAM),
#                                   y hay que volver a lanzarlo despues de
#                                   CADA actualizacion del panel, porque la
#                                   actualizacion reemplaza los archivos
#                                   compilados.
#
#  Uso:
#      sudo bash install-frontend.sh [/ruta/del/panel]
#      sudo bash install-frontend.sh [/ruta/del/panel] --remove
#
#  Antes de compilar se guarda una copia de public/assets y de routes.ts. Si la
#  compilacion falla, se deja el panel EXACTAMENTE como estaba. Nunca se queda
#  el panel en blanco.
# =============================================================================
set -uo pipefail

PANEL="/var/www/pterodactyl"
QUITAR=0

for argumento in "$@"; do
    case "$argumento" in
        --remove|--quitar) QUITAR=1 ;;
        -h|--help)
            sed -n '2,28p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) PANEL="${argumento%/}" ;;
    esac
done

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORIGEN="$AQUI/panel/frontend"
PARCHE="$PANEL/app/Extensions/DnsReverse/tools/patch-frontend.php"

# Donde se deja la pantalla.
#
# Si el tema trae el hueco para extensiones (Arix preparado), se usa ese: con
# dejar ahi la carpeta salen la pagina Y el boton, sin tocar routes.ts ni la
# base de datos.
#
# Si no lo trae (panel normal o Arix sin preparar), se hace como la extension
# original: componente + entrada en routes.ts.
HUECO="$PANEL/resources/scripts/components/server/extensions"

if [ -d "$HUECO" ]; then
    MODO_HUECO=1
    DESTINO="$HUECO/dnsreverse"
else
    MODO_HUECO=0
    DESTINO="$PANEL/resources/scripts/components/server/dnsreverse"
fi

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  DNS Reverse - modo nativo (yarn build)%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

# --- Comprobaciones previas -------------------------------------------------

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n      sudo bash install-frontend.sh /ruta/del/panel\n\n'
    exit 1
fi

if [ ! -f "$PANEL/package.json" ]; then
    err "El panel no trae package.json: no se puede compilar el frontend."
    printf '  Usa el modo inyectado, que no necesita compilar nada.\n\n'
    exit 1
fi

if [ ! -f "$PARCHE" ]; then
    err "La extension no esta instalada todavia en $PANEL."
    printf '  Lanza primero:  sudo bash %s/install.sh %s\n\n' "$AQUI" "$PANEL"
    exit 1
fi

command -v php >/dev/null 2>&1 || { err "No se encontro el comando php."; exit 1; }

ok "Panel encontrado en $PANEL"

# El tema Arix cambia las reglas: el menu del cliente no sale de routes.ts sino
# de una lista de enlaces suya guardada en la base de datos. Se detecta aqui
# para tratarlo como es debido mas abajo.
ARIX=0

if [ -f "$PANEL/config/arixTheme.php" ] \
   || [ -f "$PANEL/app/Http/ViewComposers/ArixConfiguration.php" ] \
   || [ -d "$PANEL/app/Http/Controllers/Admin/Arix" ]; then
    ARIX=1
    ok "Tema Arix detectado (se anadira tambien a su menu de enlaces)"
fi

SELLO="$(date +%Y%m%d-%H%M%S)"
RESPALDO="$PANEL/storage/dnsreverse-frontend-$SELLO"

# --- Herramientas -----------------------------------------------------------
#
# Se comprueban antes de tocar nada: si falta node o yarn, el panel se queda
# exactamente como estaba.

title "1. Comprobando las herramientas"

if ! command -v node >/dev/null 2>&1; then
    err "No esta instalado node."
    printf '  Instalalo con:\n'
    printf '      curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -\n'
    printf '      sudo apt install -y nodejs\n\n'
    exit 1
fi

NODE_MAYOR="$(node -v | sed 's/^v//' | cut -d. -f1)"
ok "node $(node -v)"

if [ "$NODE_MAYOR" -lt 14 ]; then
    err "node $(node -v) es demasiado antiguo. El panel necesita node 14 o mas."
    exit 1
fi

if command -v yarn >/dev/null 2>&1; then
    GESTOR="yarn"
    ok "yarn $(yarn -v)"
elif command -v npm >/dev/null 2>&1; then
    GESTOR="npm"
    warn "yarn no esta instalado, se usara npm (tarda mas)"
else
    err "No hay ni yarn ni npm."
    printf '      sudo npm install -g yarn\n\n'
    exit 1
fi

# Los paneles 1.14 y 1.15 compilan con webpack 5, que NO necesita
# --openssl-legacy-provider. Solo se pone si el panel trae webpack 4.
if [ "$NODE_MAYOR" -ge 17 ] && grep -q '"webpack": *"\^\?4' "$PANEL/package.json" 2>/dev/null; then
    export NODE_OPTIONS="--openssl-legacy-provider ${NODE_OPTIONS:-}"
    ok "webpack 4 con node moderno: se activa --openssl-legacy-provider"
fi

# --- Memoria ----------------------------------------------------------------
#
# Compilar el panel entero pide unos 2 GB. Si se le dice a node que puede usar
# mas de la que hay, el sistema lo mata a mitad y la compilacion falla. Se mira
# lo que hay de verdad y, si hace falta, se anade swap temporal.
LIBRE_MB="$(free -m 2>/dev/null | awk '/^Mem:/ {print ($7 != "" ? $7 : $4)}')"
SWAP_MB="$(free -m 2>/dev/null | awk '/^Swap:/ {print $2}')"
SWAP_NUESTRA=""

if [ -n "${LIBRE_MB:-}" ] && [ "$LIBRE_MB" -lt 2200 ] \
   && [ "${SWAP_MB:-0}" -lt 2000 ] \
   && [ "${DNSREVERSE_SIN_SWAP:-0}" != "1" ] \
   && command -v mkswap >/dev/null 2>&1; then

    SWAP_NUESTRA="/swapfile-dnsreverse"

    if [ ! -e "$SWAP_NUESTRA" ]; then
        warn "Solo hay ${LIBRE_MB} MB de RAM libre. Se anade swap temporal de 4 GB."

        if (fallocate -l 4G "$SWAP_NUESTRA" 2>/dev/null || dd if=/dev/zero of="$SWAP_NUESTRA" bs=1M count=4096 status=none 2>/dev/null) \
           && chmod 600 "$SWAP_NUESTRA" && mkswap "$SWAP_NUESTRA" >/dev/null 2>&1 && swapon "$SWAP_NUESTRA" 2>/dev/null; then
            ok "Swap temporal activada (se quita sola al terminar)"
            LIBRE_MB=$((LIBRE_MB + 4096))
        else
            rm -f "$SWAP_NUESTRA"; SWAP_NUESTRA=""
            warn "No se pudo crear la swap (¿contenedor?). Se compilara con lo que hay."
        fi
    else
        SWAP_NUESTRA=""
    fi
fi

quitar_swap() {
    if [ -n "${SWAP_NUESTRA:-}" ] && [ -e "$SWAP_NUESTRA" ]; then
        swapoff "$SWAP_NUESTRA" 2>/dev/null && rm -f "$SWAP_NUESTRA" && ok "Swap temporal quitada" \
            || warn "La swap sigue en uso; quitala luego:  sudo swapoff $SWAP_NUESTRA && sudo rm $SWAP_NUESTRA"
    fi
}
trap 'quitar_swap' EXIT

# Se le da a node como mucho el 75% de lo que hay, y nunca menos de 1536 MB.
if [ -n "${LIBRE_MB:-}" ]; then
    MEMORIA_NODE=$(( LIBRE_MB * 75 / 100 ))
    [ "$MEMORIA_NODE" -lt 1536 ] && MEMORIA_NODE=1536
    [ "$MEMORIA_NODE" -gt 4096 ] && MEMORIA_NODE=4096
else
    MEMORIA_NODE=2048
fi

export NODE_OPTIONS="--max-old-space-size=${DNSREVERSE_NODE_MEMORY:-$MEMORIA_NODE} ${NODE_OPTIONS:-}"
ok "node compilara con ${DNSREVERSE_NODE_MEMORY:-$MEMORIA_NODE} MB de memoria"

# --- Funciones de trabajo ---------------------------------------------------

guardar_assets() {
    mkdir -p "$RESPALDO"

    if cp -r "$PANEL/public/assets" "$RESPALDO/assets" 2>/dev/null; then
        ok "Copia de seguridad del frontend actual en $RESPALDO/assets"
    else
        warn "No habia public/assets que copiar (panel recien clonado)"
    fi
}

devolver_assets() {
    if [ -d "$RESPALDO/assets" ]; then
        rm -rf "$PANEL/public/assets"

        if cp -r "$RESPALDO/assets" "$PANEL/public/assets" 2>/dev/null; then
            ok "Frontend anterior restaurado: el panel sigue funcionando como antes"
        else
            err "No se pudo restaurar public/assets. La copia esta en $RESPALDO/assets"
        fi
    fi
}

dependencias() {
    if [ -d "$PANEL/node_modules" ] && [ -d "$PANEL/node_modules/webpack" ]; then
        ok "node_modules ya estaba (se salta la descarga)"

        return 0
    fi

    warn "Descargando dependencias, esto tarda unos minutos..."

    if [ "$GESTOR" = "yarn" ]; then
        yarn install --network-timeout 600000 || return 1
    else
        npm install --no-audit --no-fund || return 1
    fi

    ok "Dependencias listas"
}

REGISTRO_BUILD="$PANEL/storage/logs/dnsreverse-build-$SELLO.log"

compilar() {
    warn "Compilando... tarda varios minutos, no cierres la terminal."

    local resultado=0

    if [ "$GESTOR" = "yarn" ]; then
        yarn build:production > "$REGISTRO_BUILD" 2>&1 || resultado=1
    else
        npm run build:production > "$REGISTRO_BUILD" 2>&1 || resultado=1
    fi

    if [ "$resultado" -eq 0 ]; then
        return 0
    fi

    # Ensenar el motivo de verdad, no solo "exit code 1".
    printf '\n'
    err "La compilacion fallo. Esto es lo que dijo:"
    printf '\n'

    if grep -qE "heap out of memory|Allocation failed|Killed" "$REGISTRO_BUILD"; then
        printf '      %sSe quedo sin memoria.%s\n\n' "$Y$B" "$N"
        printf '      Anade swap y reintenta:\n'
        printf '          sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile\n'
        printf '          sudo mkswap /swapfile && sudo swapon /swapfile\n'
    elif grep -q "ERROR in" "$REGISTRO_BUILD"; then
        grep -A 3 "ERROR in" "$REGISTRO_BUILD" | head -25 | sed 's/^/          /'
    else
        grep -vE "^\s*$|warning |Browserslist|DeprecationWarning|^warn -" "$REGISTRO_BUILD" | tail -20 | sed 's/^/          /'
    fi

    printf '\n      Registro entero:  cat %s\n' "$REGISTRO_BUILD"

    return 1
}

ajustar_permisos() {
    for duenyo in www-data nginx apache; do
        if id "$duenyo" >/dev/null 2>&1; then
            chown -R "$duenyo:$duenyo" "$PANEL/public/assets" 2>/dev/null && return 0
        fi
    done

    warn "No se pudieron ajustar los permisos de public/assets (lanzalo con sudo)"
}

cd "$PANEL" || exit 1

# --- Quitar -----------------------------------------------------------------

if [ "$QUITAR" -eq 1 ]; then
    title "2. Quitando el modo nativo"

    guardar_assets

    [ "$MODO_HUECO" -eq 1 ] || php "$PARCHE" "$PANEL" --remove
    rm -rf "$DESTINO"
    rm -f "$PANEL/resources/views/admin/extensions/dnsreverse.blade.php"
    ok "Componente y ruta quitados"

    if [ "$ARIX" -eq 1 ]; then
        php "$PANEL/artisan" dnsreverse:arix remove >/dev/null 2>&1 \
            && ok "Enlace quitado del menu del tema Arix" \
            || warn "No se pudo quitar el enlace de Arix (quitalo en Admin -> Arix -> Links)"
    fi

    title "3. Recompilando el panel sin DNS Reverse"

    if ! dependencias; then
        err "Fallo la descarga de dependencias. El panel queda como estaba."
        exit 1
    fi

    if ! compilar; then
        err "La compilacion fallo. Se restaura lo que habia."
        devolver_assets
        exit 1
    fi

    ajustar_permisos


    php "$PANEL/artisan" view:clear >/dev/null 2>&1

    printf '\n%s  Listo: el panel vuelve a estar como lo trae Pterodactyl.%s\n' "$G$B" "$N"
    exit 0
fi

# --- 2. Copiar el componente ------------------------------------------------

title "2. Copiando la pantalla de DNS Reverse"

if [ ! -f "$ORIGEN/components/DnsReverseContainer.tsx" ]; then
    err "No se encontro $ORIGEN/components/DnsReverseContainer.tsx"
    printf '  ¿Has clonado el repositorio entero?\n\n'
    exit 1
fi

mkdir -p "$DESTINO"

if ! cp "$ORIGEN/components/DnsReverseContainer.tsx" "$DESTINO/DnsReverseContainer.tsx"; then
    err "No se pudo copiar el componente. ¿Estas ejecutando con sudo?"
    exit 1
fi

if [ "$MODO_HUECO" -eq 1 ]; then
    # El tema trae el hueco: se deja tambien el route.tsx y ya esta. El tema
    # los busca solo al compilar y saca la pagina y el boton.
    cat > "$DESTINO/route.tsx" <<'TSX'
import DnsReverseContainer from './DnsReverseContainer';

export default {
    path: '/dnsreverse',
    permission: null,
    name: 'DNS Reverse',
    icon: 'HiOutlineGlobeAlt',
    component: DnsReverseContainer,
};
TSX
    ok "Pantalla puesta en el hueco de extensiones del tema"
else
    ok "Componente copiado a resources/scripts/components/server/dnsreverse/"
fi

# --- 3. Anadir la ruta ------------------------------------------------------

title "3. Anadiendo el boton al menu del cliente"

if [ "$MODO_HUECO" -eq 1 ]; then
    ok "No hace falta tocar routes.ts: el tema recoge la carpeta solo"
    RESULTADO=0
else
    php "$PARCHE" "$PANEL"
    RESULTADO=$?
fi

if [ "$RESULTADO" -eq 1 ]; then
    err "No se pudo anadir la ruta. No se compila nada: el panel queda intacto."
    rm -rf "$DESTINO"
    exit 1
fi

[ "$MODO_HUECO" -eq 1 ] || ok "routes.ts listo (copia del original al lado)"

# --- 4. Dependencias --------------------------------------------------------

title "4. Preparando las dependencias del panel"

if ! dependencias; then
    err "Fallo la descarga de dependencias. No se compila: el panel queda intacto."
    php "$PARCHE" "$PANEL" --remove >/dev/null 2>&1
    rm -rf "$DESTINO"
    exit 1
fi

# --- 5. Compilar ------------------------------------------------------------

title "5. Compilando el panel"

guardar_assets

deshacer_todo() {
    printf '\n  El panel NO se queda roto: se deja todo como estaba.\n'
    devolver_assets
    [ "$MODO_HUECO" -eq 1 ] || php "$PARCHE" "$PANEL" --remove >/dev/null 2>&1
    rm -rf "$DESTINO"
    rm -f "$PANEL/resources/views/admin/extensions/dnsreverse.blade.php"
}

if ! compilar; then
    deshacer_todo
    exit 1
fi

# Aunque diga que fue bien, se comprueba que el frontend existe de verdad.
# "yarn build:production" borra public/assets antes de empezar, asi que si algo
# raro pasa y no genera nada, el panel se quedaria en blanco.
if ! ls "$PANEL/public/assets/bundle."*.js >/dev/null 2>&1; then
    err "La compilacion dijo que si, pero no hay frontend generado."
    deshacer_todo
    exit 1
fi

ok "Panel compilado"

# --- 6. Apagar el modo inyectado -------------------------------------------

title "6. Ajustando la extension"

# Con el hueco del tema, el boton del cliente ya sale solo. Sin hueco, en Arix
# hay que anadir el enlace a su lista, que es como el tema anade sus apartados.
if [ "$ARIX" -eq 1 ] && [ "$MODO_HUECO" -eq 0 ]; then
    if php "$PANEL/artisan" dnsreverse:arix add; then
        ok "Enlace anadido al menu del tema Arix"
    else
        warn "No se pudo anadir el enlace al menu de Arix."
        warn "Ponlo a mano en Admin -> Arix -> Links, con la url /dnsreverse"
    fi
fi

# --- Resumen ----------------------------------------------------------------

printf '\n%s  Modo nativo instalado%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  El boton "DNS Reverse" ya sale en el menu de cada servidor, dentro\n'
printf '  del propio panel. No se anade nada a las paginas: el navegador y\n'
printf '  Cloudflare ven el panel tal cual.\n'
printf '\n'
printf '  IMPORTANTE - cada vez que actualices el panel:\n'
printf '    La actualizacion reemplaza los archivos compilados y el boton\n'
printf '    desaparece. Para recuperarlo:\n'
printf '        sudo bash %s/install.sh %s\n' "$AQUI" "$PANEL"
printf '        sudo bash %s/install-frontend.sh %s\n' "$AQUI" "$PANEL"
printf '\n'
printf '    Los DNS de tus clientes NO se tocan en ningun momento.\n'
printf '\n'
printf '  Copia de seguridad del frontend anterior:\n'
printf '    %s/assets\n' "$RESPALDO"
printf '    (puedes borrarla cuando compruebes que todo va bien)\n'
printf '\n'
printf '  Para volver al modo inyectado:\n'
printf '    sudo bash %s/install-frontend.sh %s --remove\n' "$AQUI" "$PANEL"
printf '\n'
