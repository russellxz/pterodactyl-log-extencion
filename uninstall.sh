#!/usr/bin/env bash
# =============================================================================
#  LogsPterodactyl - desinstalador
#
#  Deja el panel exactamente como estaba: quita los archivos, la linea de
#  config/app.php y las tablas de la extension. No toca nada del panel ni del
#  tema Arix.
#
#  Uso:
#      sudo bash uninstall.sh [/ruta/del/panel] [--keep-data] [--force]
#
#      --keep-data   Conserva las tablas (historial de instalaciones, correos,
#                    consumo...). Util si vas a reinstalar la extension.
#      --force       No pregunta nada.
# =============================================================================
set -uo pipefail

PANEL="/var/www/pterodactyl"
KEEP_DATA=0
FORCE=0

for argument in "$@"; do
    case "$argument" in
        --keep-data) KEEP_DATA=1 ;;
        --force) FORCE=1 ;;
        -*) printf 'Opcion desconocida: %s\n' "$argument"; exit 1 ;;
        *) PANEL="${argument%/}" ;;
    esac
done

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()   { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn() { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()  { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }

printf '\n%s  LogsPterodactyl - desinstalador%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

if [ ! -f "$PANEL/artisan" ]; then
    err "No se encontro el panel de Pterodactyl en: $PANEL"
    printf '\n  Uso: sudo bash uninstall.sh /ruta/del/panel\n\n'
    exit 1
fi

ok "Panel encontrado en $PANEL"

printf '\n  Se va a quitar:\n'
printf '    - %s/app/Extensions/LogsPterodactyl\n' "$PANEL"
printf '    - %s/public/extensions/logspterodactyl\n' "$PANEL"
printf '    - la linea de LogsPterodactyl en config/app.php\n'

if [ "$KEEP_DATA" -eq 1 ]; then
    printf '    - las tablas se CONSERVAN (--keep-data)\n'
else
    printf '    - las tablas logspterodactyl_* y todo su contenido\n'
fi

printf '\n  No se toca nada del panel, del tema Arix ni de tus servidores.\n\n'

if [ "$FORCE" -ne 1 ]; then
    printf '  ¿Continuar? [s/N] '
    read -r answer
    case "$answer" in
        s|S|si|SI|Si|y|Y|yes) ;;
        *) printf '\n  Cancelado. No se ha tocado nada.\n\n'; exit 0 ;;
    esac
fi

cd "$PANEL" || exit 1

# --- 1. Base de datos (antes de borrar los archivos: el comando vive en ellos)

if [ "$KEEP_DATA" -eq 1 ]; then
    warn "Tablas conservadas por peticion (--keep-data)"
else
    if php artisan logspterodactyl:uninstall --force >/dev/null 2>&1; then
        ok "Tablas de la extension borradas"
    else
        warn "No se pudieron borrar las tablas con artisan"
        printf '        Puedes borrarlas a mano cuando quieras:\n'
        printf '        DROP TABLE logspterodactyl_update_runs, logspterodactyl_resource_samples,\n'
        printf '          logspterodactyl_mail_logs, logspterodactyl_install_events,\n'
        printf '          logspterodactyl_events, logspterodactyl_settings;\n'
    fi
fi

# --- 2. Quitar el registro del proveedor ------------------------------------

if [ -f "$PANEL/app/Extensions/LogsPterodactyl/tools/register-provider.php" ]; then
    if php "$PANEL/app/Extensions/LogsPterodactyl/tools/register-provider.php" "$PANEL" --remove >/dev/null 2>&1; then
        ok "Linea de LogsPterodactyl quitada de config/app.php"
    else
        err "No se pudo editar config/app.php"
        printf '        Quita a mano la linea que contiene LogsPterodactylServiceProvider.\n'
    fi
else
    # Respaldo por si los archivos ya no estan: se limpia con sed.
    if grep -q 'LogsPterodactylServiceProvider' "$PANEL/config/app.php" 2>/dev/null; then
        cp "$PANEL/config/app.php" "$PANEL/config/app.php.logspterodactyl-antes-de-desinstalar"
        sed -i '/LogsPterodactyl START/,/LogsPterodactyl END/d; /LogsPterodactylServiceProvider/d' "$PANEL/config/app.php"

        if php -l "$PANEL/config/app.php" >/dev/null 2>&1; then
            ok "Linea de LogsPterodactyl quitada de config/app.php"
        else
            mv "$PANEL/config/app.php.logspterodactyl-antes-de-desinstalar" "$PANEL/config/app.php"
            err "El archivo quedaba invalido, se ha restaurado. Quita la linea a mano."
        fi
    else
        ok "config/app.php ya estaba limpio"
    fi
fi

# --- 3. Quitar la entrada del menu del admin --------------------------------
#
# ESTO VA PRIMERO, ANTES DE BORRAR NADA. La entrada llama a
# route('admin.logspterodactyl.index') y sin la extension esa ruta no existe:
# si el archivo se quedara puesto, el area de administracion ENTERA daria
# error 500. Los dos sitios donde puede estar se limpian por separado, porque
# no todos los paneles tienen la plantilla del menu.

rm -f "$PANEL/resources/views/admin/extensions/logspterodactyl.blade.php" \
    && ok "Entrada del menu del admin retirada (hueco del tema)" || true

if grep -q 'LogsPterodactyl NAV' "$PANEL/resources/views/layouts/admin.blade.php" 2>/dev/null; then
    php -r '
        $f = $argv[1];
        $s = file_get_contents($f);
        $s = preg_replace("/[ \t]*\{\{--\s*LogsPterodactyl NAV START.*?LogsPterodactyl NAV END\s*--\}\}\R?/s", "", $s);
        file_put_contents($f, $s);
    ' "$PANEL/resources/views/layouts/admin.blade.php" \
        && ok "Entrada del menu retirada de la plantilla" \
        || warn "No se pudo quitar la entrada del menu. Quitala a mano en resources/views/layouts/admin.blade.php"
fi

php artisan view:clear >/dev/null 2>&1 || true

# --- 4. Borrar los archivos -------------------------------------------------

rm -rf "$PANEL/app/Extensions/LogsPterodactyl" && ok "Codigo borrado" || err "No se pudo borrar app/Extensions/LogsPterodactyl"
rm -rf "$PANEL/public/extensions/logspterodactyl" && ok "Recursos publicos borrados" || err "No se pudo borrar public/extensions/logspterodactyl"

# Si la carpeta app/Extensions se queda vacia se quita tambien, para no dejar
# rastro; si hay otras extensiones se respeta.
rmdir "$PANEL/app/Extensions" 2>/dev/null && ok "Carpeta app/Extensions vacia, borrada" || true
rmdir "$PANEL/public/extensions" 2>/dev/null || true

# La copia de seguridad de config/app.php que hizo el instalador ya no sirve.
rm -f "$PANEL/config/app.php.logspterodactyl-backup" "$PANEL/config/app.php.logspterodactyl-antes-de-desinstalar"

# --- 5. Limpiar caches ------------------------------------------------------

rm -f "$PANEL"/bootstrap/cache/config.php "$PANEL"/bootstrap/cache/services.php "$PANEL"/bootstrap/cache/packages.php 2>/dev/null

php artisan config:clear >/dev/null 2>&1 && ok "Cache de configuracion limpiada" || warn "No se pudo limpiar la cache de configuracion"
php artisan view:clear   >/dev/null 2>&1 && ok "Cache de vistas limpiada"        || warn "No se pudo limpiar la cache de vistas"
php artisan route:clear  >/dev/null 2>&1 && ok "Cache de rutas limpiada"         || warn "No se pudo limpiar la cache de rutas"

if command -v composer >/dev/null 2>&1; then
    COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload -o --no-interaction >/dev/null 2>&1 \
        && ok "Autocarga regenerada" || warn "composer dump-autoload fallo (no es grave)"
fi

# --- Resumen ----------------------------------------------------------------

printf '\n%s  LogsPterodactyl desinstalado%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  El panel ha quedado como estaba.\n'

if [ "$KEEP_DATA" -eq 1 ]; then
    printf '  Las tablas logspterodactyl_* siguen ahi por si reinstalas.\n'
fi

printf '\n  Los respaldos del panel en /var/backups/logspterodactyl NO se han tocado.\n'
printf '  Si ya no los necesitas, borralos a mano.\n\n'
