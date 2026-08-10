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

# El panel compila con webpack 4. A partir de node 17 hay que decirle a node que
# use el openssl de siempre o la compilacion muere con ERR_OSSL_EVP_UNSUPPORTED.
if [ "$NODE_MAYOR" -ge 17 ]; then
    export NODE_OPTIONS="--openssl-legacy-provider ${NODE_OPTIONS:-}"
    ok "node 17 o superior: se activa --openssl-legacy-provider"
fi

# Compilar el panel pide memoria. Con menos de 2 GB libres suele morir por OOM.
LIBRE_MB="$(free -m 2>/dev/null | awk '/^Mem:/ {print ($7 != "" ? $7 : $4)}')"

if [ -n "${LIBRE_MB:-}" ] && [ "$LIBRE_MB" -lt 1800 ]; then
    warn "Solo hay ${LIBRE_MB} MB de RAM libre. Compilar el panel necesita unos 2 GB."
    warn "Si la compilacion muere sola, anade memoria de intercambio (swap) y reintenta."
fi

export NODE_OPTIONS="--max-old-space-size=${DNSREVERSE_NODE_MEMORY:-2048} ${NODE_OPTIONS:-}"

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

compilar() {
    warn "Compilando... tarda varios minutos, no cierres la terminal."

    if [ "$GESTOR" = "yarn" ]; then
        yarn build:production
    else
        npm run build:production
    fi
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

if ! compilar; then
    err "La compilacion fallo."
    printf '\n  El panel NO se queda roto: se deja todo como estaba.\n'
    devolver_assets
    php "$PARCHE" "$PANEL" --remove >/dev/null 2>&1
    rm -rf "$DESTINO"
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

# --- El boton del area de administracion ------------------------------------
#
# Esta parte del panel es Blade, no React: sale al momento y no hay que
# compilar nada. Si el tema trae el hueco para extensiones, se deja ahi el
# archivo; si no, se mete el <li> en la plantilla, como hacia la extension
# original.
HUECO_ADMIN="$PANEL/resources/views/admin/extensions"

if [ -d "$HUECO_ADMIN" ]; then
    cat > "$HUECO_ADMIN/dnsreverse.blade.php" <<'BLADE'
<li class="header">DNS REVERSE</li>
<li class="{{ Route::currentRouteNamed('admin.dnsreverse.*') ? 'active' : '' }}">
    <a href="{{ route('admin.dnsreverse.index') }}">
        <i data-lucide="globe"></i> <i class="fa fa-globe"></i> <span>DNS Reverse</span>
    </a>
</li>
BLADE
    ok "Entrada anadida al menu del admin (hueco del tema)"
elif [ -f "$PANEL/resources/views/layouts/admin.blade.php" ]; then
    if grep -q 'DnsReverse NAV' "$PANEL/resources/views/layouts/admin.blade.php"; then
        ok "La entrada del admin ya estaba en la plantilla"
    else
        NAV_ADMIN=$(cat <<'BLADE'
                        {{-- DnsReverse NAV START --}}
                        <li class="{{ Route::currentRouteNamed('admin.dnsreverse.*') ? 'active' : '' }}">
                            <a href="{{ route('admin.dnsreverse.index') }}">
                                <i data-lucide="globe"></i> <i class="fa fa-globe"></i> <span>DNS Reverse</span>
                            </a>
                        </li>
                        {{-- DnsReverse NAV END --}}
BLADE
)
        awk -v block="$NAV_ADMIN" '
            { print }
            /route\(.admin\.nodes.\)/ { encontrado = 1 }
            encontrado && /<\/li>/ { print block; encontrado = 0 }
        ' "$PANEL/resources/views/layouts/admin.blade.php" > /tmp/dnsrev-admin.tmp \
            && mv /tmp/dnsrev-admin.tmp "$PANEL/resources/views/layouts/admin.blade.php"

        if grep -q 'DnsReverse NAV' "$PANEL/resources/views/layouts/admin.blade.php"; then
            ok "Entrada anadida al menu del admin"
        else
            warn "No se pudo anadir la entrada al menu del admin."
            warn "Entra directamente a /admin/dnsreverse"
        fi
    fi
fi

ajustar_permisos
php "$PANEL/artisan" view:clear >/dev/null 2>&1

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
