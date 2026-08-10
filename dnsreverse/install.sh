#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - instalador (parte del panel)
#
#  Extension de Pterodactyl para que tus clientes pongan un dominio propio o un
#  subdominio tuyo a su servidor.
#
#  LO IMPORTANTE:
#    - NO inyecta nada en las paginas del panel. El boton del cliente es una
#      pantalla mas de React, compilada con el panel (como la original).
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

# Solo puede quedar UNA version.
#
# Si conviven, salen apartados repetidos en el menu y es un lio. La antigua se
# quita entera (sus archivos, sus rutas y su entrada del menu), pero NO sus
# datos: la tabla server_proxy es la misma que usa esta, ahi viven los DNS de
# tus clientes y se quedan donde estan.
VIEJA=0

if [ -f "$PANEL/app/Http/Controllers/Admin/ProxySettingsController.php" ] \
   || [ -f "$PANEL/app/Models/ServerProxy.php" ] \
   || grep -q 'admin.proxy.' "$PANEL/resources/views/layouts/admin.blade.php" 2>/dev/null; then

    VIEJA=1
    warn "Detectada la version antigua (la que se instalaba tocando archivos del panel)."
    warn "Se quita para que no salgan apartados repetidos. Tus DNS no se tocan."

    LIMPIADOR="$ORIGEN/app/Extensions/DnsReverse/tools/limpiar-viejo.php"

    if [ -f "$LIMPIADOR" ]; then
        php "$LIMPIADOR" "$PANEL" --limpiar 2>&1 | sed 's/^/  /'
    else
        warn "No se encontro el limpiador. Quitala a mano o usa reinstalar-limpio.sh"
    fi
fi

# Restos de una instalacion anterior de ESTA extension, por si se quedo a
# medias. Se quitan siempre antes de copiar, para no acabar con dos entradas.
rm -f "$PANEL/resources/views/admin/extensions/dnsreverse.blade.php"

if grep -q 'DnsReverse NAV' "$PANEL/resources/views/layouts/admin.blade.php" 2>/dev/null; then
    php -r '
        $f = $argv[1];
        $s = file_get_contents($f);
        $s = preg_replace("/[ \t]*\{\{--\s*DnsReverse NAV START.*?DnsReverse NAV END\s*--\}\}\R?/s", "", $s);
        file_put_contents($f, $s);
    ' "$PANEL/resources/views/layouts/admin.blade.php" 2>/dev/null
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

# --- 6. El boton del area de administracion ---------------------------------

title "6. Boton del area de administracion"
#
# Esta parte del panel es Blade, no React: sale al momento y no hay que
# compilar nada. Si el tema trae el hueco para extensiones, se deja ahi el
# archivo; si no, se mete el <li> en la plantilla, como hacia la extension
# original.
HUECO_ADMIN="$PANEL/resources/views/admin/extensions"

if [ -d "$HUECO_ADMIN" ]; then
    cat > "$HUECO_ADMIN/dnsreverse.blade.php" <<'BLADE'
{{--
    Entrada del menu de DNS Reverse.

    El @if NO sobra: si algun dia se desinstala la extension y este archivo se
    queda aqui, route() lanzaria una excepcion y el area de administracion
    entera daria error 500. Comprobando primero que la ruta existe, lo peor que
    puede pasar es que no salga el boton.
--}}
@if (Route::has('admin.dnsreverse.index'))
    <li class="header">DNS REVERSE</li>
    <li class="{{ Route::currentRouteNamed('admin.dnsreverse.*') ? 'active' : '' }}">
        <a href="{{ route('admin.dnsreverse.index') }}">
            <i data-lucide="globe"></i> <i class="fa fa-globe"></i> <span>DNS Reverse</span>
        </a>
    </li>
@endif
BLADE
    ok "Entrada anadida al menu del admin (hueco del tema)"
elif [ -f "$PANEL/resources/views/layouts/admin.blade.php" ]; then
    if grep -q 'DnsReverse NAV' "$PANEL/resources/views/layouts/admin.blade.php"; then
        ok "La entrada del admin ya estaba en la plantilla"
    else
        NAV_ADMIN=$(cat <<'BLADE'
                        {{-- DnsReverse NAV START --}}
                        @if (Route::has('admin.dnsreverse.index'))
                        <li class="{{ Route::currentRouteNamed('admin.dnsreverse.*') ? 'active' : '' }}">
                            <a href="{{ route('admin.dnsreverse.index') }}">
                                <i data-lucide="globe"></i> <i class="fa fa-globe"></i> <span>DNS Reverse</span>
                            </a>
                        </li>
                        @endif
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


# --- 7. La pantalla del cliente ---------------------------------------------
#
# Aqui no se inyecta nada en las paginas del panel. El boton del cliente es una
# pantalla mas de React que se compila con el panel, igual que hacia la
# extension original, y el del admin es un <li> de Blade.

title "7. Pantalla del cliente"

if [ "${DNSREVERSE_SIN_FRONTEND:-0}" = "1" ]; then
    warn "Te saltas la compilacion (DNSREVERSE_SIN_FRONTEND=1)."
    warn "Tus clientes no veran el boton hasta que lances:"
    printf '      sudo bash %s/install-frontend.sh %s\n' "$AQUI" "$PANEL"
elif ! command -v yarn >/dev/null 2>&1 && ! command -v npm >/dev/null 2>&1; then
    warn "No hay yarn ni npm: no se puede compilar la pantalla del cliente."
    warn "Instala yarn y lanza:  sudo bash $AQUI/install-frontend.sh $PANEL"
else
    if bash "$AQUI/install-frontend.sh" "$PANEL"; then
        ok "Pantalla del cliente compilada y botones puestos"
    else
        warn "No se pudo compilar la pantalla del cliente."
        warn "El area de administracion funciona igual. Para reintentarlo:"
        printf '      sudo bash %s/install-frontend.sh %s\n' "$AQUI" "$PANEL"
    fi
fi

# --- 8. Comprobacion final --------------------------------------------------

title "8. Comprobacion final"

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
