#!/usr/bin/env bash
# =============================================================================
#  ArixLog - actualizacion del panel de Pterodactyl conservando el tema Arix
#
#  Este archivo lo genera la extension. No se edita a mano: cada actualizacion
#  crea el suyo con sus rutas y su respaldo.
#
#  Se puede lanzar tambien a mano por SSH (es lo recomendable si PHP no puede
#  ejecutar procesos o si el usuario del servidor web no tiene permisos):
#
#      sudo bash {!! $runDir !!}/update.sh
# =============================================================================
set -uo pipefail

PANEL="{!! $panel !!}"
RUN_DIR="{!! $runDir !!}"
BACKUP_DIR="{!! $backupDir !!}"
LOG="$RUN_DIR/update.log"
STATUS="$RUN_DIR/status.json"
VERSION="{!! $version !!}"
DOWNLOAD_URL="{!! $url !!}"
DB_NAME="{!! $database !!}"
DB_CREDENTIALS="{!! $credentials !!}"
REGISTER_TOOL="{!! $registerTool !!}"
VERSION_TOOL="{!! $versionTool !!}"
WEB_USER="{!! $webUser !!}"
@if($themePath)
THEME_PATH="{!! $themePath !!}"
ARIX_PACKAGES="{!! $arixPackages !!}"
@else
THEME_PATH=""
ARIX_PACKAGES=""
@endif

STEPS_JSON=""
CURRENT="preparando"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$RUN_DIR" "$BACKUP_DIR"
touch "$LOG"

log() {
    printf '[%s] %s\n' "$(date '+%H:%M:%S')" "$*" >> "$LOG"
}

sanitize() {
    printf '%s' "$1" | tr -d '"\\' | tr '\n' ' ' | cut -c1-400
}

write_status() {
    local state="$1" step="$2" error="$3"
    printf '{"state":"%s","step":"%s","steps":[%s],"error":"%s","version":"%s","updated":"%s"}\n' \
        "$(sanitize "$state")" "$(sanitize "$step")" "$STEPS_JSON" "$(sanitize "$error")" \
        "$(sanitize "$VERSION")" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$STATUS"
}

add_step() {
    [ -n "$STEPS_JSON" ] && STEPS_JSON="$STEPS_JSON,"
    STEPS_JSON="$STEPS_JSON{\"name\":\"$(sanitize "$1")\",\"state\":\"$2\"}"
}

fail() {
    local message="$1"
    log "FALLO: $message"
    add_step "$CURRENT" "failed"
    write_status "failed" "$CURRENT" "$message"

    # Se intenta dejar el panel accesible pase lo que pase: un panel caido es
    # peor que un panel sin actualizar.
    log "Levantando el panel de nuevo..."
    (cd "$PANEL" && php artisan up >> "$LOG" 2>&1) || true

    log ""
    log "La actualizacion no termino. El respaldo sigue disponible en:"
    log "  $BACKUP_DIR"
    log "Para deshacer los cambios: sudo bash $RUN_DIR/rollback.sh"
    exit 1
}

step() {
    CURRENT="$1"
    shift
    log ""
    log "==> $CURRENT"
    write_status "running" "$CURRENT" ""

    if bash -c "$*" >> "$LOG" 2>&1; then
        add_step "$CURRENT" "done"
        write_status "running" "$CURRENT" ""
        return 0
    fi

    fail "El paso \"$CURRENT\" devolvio un error. Revisa el registro para ver el detalle."
}

# Igual que step(), pero un fallo solo genera un aviso y no aborta.
soft_step() {
    CURRENT="$1"
    shift
    log ""
    log "==> $CURRENT"
    write_status "running" "$CURRENT" ""

    if bash -c "$*" >> "$LOG" 2>&1; then
        add_step "$CURRENT" "done"
    else
        log "AVISO: \"$CURRENT\" fallo, se continua igualmente."
        add_step "$CURRENT" "warning"
    fi

    write_status "running" "$CURRENT" ""
}

log "============================================================"
log " ArixLog - actualizacion del panel"
log " Panel:    $PANEL"
log " Version:  $VERSION"
log " Respaldo: $BACKUP_DIR"
log " Usuario:  $(id -un)"
log "============================================================"

cd "$PANEL" || fail "No se pudo entrar en $PANEL"

# --- 1. Respaldo -------------------------------------------------------------
# Va primero y a proposito: sin respaldo no se toca nada.

step "Respaldando los archivos del panel" \
    "tar -czf '$BACKUP_DIR/panel-files-$STAMP.tar.gz' \
        --exclude='./node_modules' \
        --exclude='./.git' \
        --exclude='./storage/logs' \
        --exclude='./storage/framework/cache' \
        -C '$PANEL' ."

if command -v mysqldump >/dev/null 2>&1; then
    step "Respaldando la base de datos" \
        "mysqldump --defaults-extra-file='$DB_CREDENTIALS' --single-transaction --quick --routines --events '$DB_NAME' | gzip > '$BACKUP_DIR/panel-db-$STAMP.sql.gz'"
else
    log "AVISO: mysqldump no esta disponible, no se respalda la base de datos."
    add_step "Respaldando la base de datos" "warning"
fi

# --- 2. Descarga -------------------------------------------------------------

step "Descargando Pterodactyl $VERSION" \
    "curl -fsSL -o '$RUN_DIR/panel.tar.gz' '$DOWNLOAD_URL'"

step "Comprobando el paquete descargado" \
    "tar -tzf '$RUN_DIR/panel.tar.gz' > /dev/null"

# --- 3. Actualizacion del panel ---------------------------------------------

step "Poniendo el panel en mantenimiento" \
    "cd '$PANEL' && php artisan down || true"

step "Descomprimiendo la nueva version" \
    "cd '$PANEL' && tar -xzf '$RUN_DIR/panel.tar.gz'"

step "Ajustando permisos de storage y cache" \
    "cd '$PANEL' && chmod -R 755 storage/* bootstrap/cache"

if command -v composer >/dev/null 2>&1; then
    step "Instalando dependencias de PHP" \
        "cd '$PANEL' && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction"
else
    fail "composer no esta instalado y es imprescindible para actualizar."
fi

step "Limpiando cache de vistas y configuracion" \
    "cd '$PANEL' && php artisan view:clear && php artisan config:clear && php artisan route:clear || true"

step "Aplicando migraciones del panel" \
    "cd '$PANEL' && php artisan migrate --seed --force"

# --- 4. Tema Arix ------------------------------------------------------------
# El paquete del panel ha pisado los archivos que Arix reemplaza. Se vuelven a
# poner desde la copia del tema que quedo en arix/<version>.

if [ -n "$THEME_PATH" ] && [ -d "$THEME_PATH" ]; then
    if command -v rsync >/dev/null 2>&1; then
        step "Restaurando los archivos del tema Arix" \
            "rsync -a '$THEME_PATH/' '$PANEL/'"
    else
        step "Restaurando los archivos del tema Arix" \
            "cp -a '$THEME_PATH/.' '$PANEL/'"
    fi

    if command -v yarn >/dev/null 2>&1; then
        soft_step "Instalando las dependencias del tema" \
            "cd '$PANEL' && yarn add $ARIX_PACKAGES --non-interactive"

        # Este es el paso largo (varios minutos) y el que mas memoria pide.
        step "Recompilando los assets del tema Arix" \
            "cd '$PANEL' && yarn build:production"
    else
        log "AVISO: yarn no esta instalado. Los archivos del tema se han restaurado,"
        log "       pero hay que recompilar los assets a mano:"
        log "         cd $PANEL && yarn install && yarn build:production"
        log "       Hasta entonces el panel usara los assets del tema por defecto."
        add_step "Recompilando los assets del tema Arix" "warning"
    fi

    step "Aplicando las migraciones del tema" \
        "cd '$PANEL' && php artisan migrate --force"

    # El tema trae su propio config/app.php con la version del panel escrita a
    # mano (la de la version para la que se compilo). Al restaurarlo, el panel
    # volveria a decir que tiene la version vieja, asi que se corrige.
    soft_step "Corrigiendo la version en config/app.php" \
        "php '$VERSION_TOOL' '$PANEL' '$VERSION'"
else
    log "No hay tema Arix que restaurar (o no se encontro su carpeta arix/<version>)."
fi

# --- 5. La extension ---------------------------------------------------------
# Tanto el paquete del panel como el tema reescriben config/app.php, asi que
# hay que volver a apuntar el proveedor de la extension.

soft_step "Volviendo a registrar la extension ArixLog" \
    "php '$REGISTER_TOOL' '$PANEL'"

soft_step "Aplicando las migraciones de la extension" \
    "cd '$PANEL' && php artisan migrate --force"

# --- 6. Cierre ---------------------------------------------------------------

soft_step "Optimizando la aplicacion" \
    "cd '$PANEL' && php artisan optimize:clear && php artisan optimize"

soft_step "Devolviendo los permisos al servidor web" \
    "chown -R $WEB_USER:$WEB_USER '$PANEL' || true"

soft_step "Reiniciando los procesos en cola" \
    "cd '$PANEL' && php artisan queue:restart"

step "Sacando el panel del modo mantenimiento" \
    "cd '$PANEL' && php artisan up"

rm -f "$RUN_DIR/panel.tar.gz"

write_status "success" "Actualizacion terminada" ""

log ""
log "============================================================"
log " Panel actualizado a la version $VERSION"
log " Respaldo guardado en: $BACKUP_DIR"
log " Para deshacer: sudo bash $RUN_DIR/rollback.sh"
log "============================================================"
