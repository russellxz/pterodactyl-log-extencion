#!/usr/bin/env bash
# =============================================================================
#  ArixLog - deshacer una actualizacion del panel
#
#  Restaura los archivos y la base de datos tal y como estaban justo antes de
#  la actualizacion, usando el respaldo que se hizo entonces.
#
#      sudo bash {!! $runDir !!}/rollback.sh
#
#  Nota: los archivos que la version nueva anadio y que no existian antes se
#  quedan en el disco. No estorban (el panel restaurado no los usa), pero si
#  quieres una carpeta identica a la del respaldo tendras que borrarlos a mano.
# =============================================================================
set -uo pipefail

PANEL="{!! $panel !!}"
RUN_DIR="{!! $runDir !!}"
BACKUP_DIR="{!! $backupDir !!}"
LOG="$RUN_DIR/rollback.log"
STATUS="$RUN_DIR/rollback-status.json"
DB_NAME="{!! $database !!}"
DB_CREDENTIALS="{!! $credentials !!}"
REGISTER_TOOL="{!! $registerTool !!}"
WEB_USER="{!! $webUser !!}"

touch "$LOG"

log() {
    printf '[%s] %s\n' "$(date '+%H:%M:%S')" "$*" >> "$LOG"
}

sanitize() {
    printf '%s' "$1" | tr -d '"\\' | tr '\n' ' ' | cut -c1-400
}

write_status() {
    printf '{"state":"%s","step":"%s","error":"%s","updated":"%s"}\n' \
        "$(sanitize "$1")" "$(sanitize "$2")" "$(sanitize "$3")" \
        "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$STATUS"
}

fail() {
    log "FALLO: $1"
    write_status "failed" "$CURRENT" "$1"
    (cd "$PANEL" && php artisan up >> "$LOG" 2>&1) || true
    exit 1
}

CURRENT="preparando"

step() {
    CURRENT="$1"
    shift
    log ""
    log "==> $CURRENT"
    write_status "running" "$CURRENT" ""
    bash -c "$*" >> "$LOG" 2>&1 || fail "El paso \"$CURRENT\" devolvio un error."
}

soft_step() {
    CURRENT="$1"
    shift
    log ""
    log "==> $CURRENT"
    write_status "running" "$CURRENT" ""
    bash -c "$*" >> "$LOG" 2>&1 || log "AVISO: \"$CURRENT\" fallo, se continua."
}

FILES_BACKUP="$(ls -1t "$BACKUP_DIR"/panel-files-*.tar.gz 2>/dev/null | head -n1)"
DB_BACKUP="$(ls -1t "$BACKUP_DIR"/panel-db-*.sql.gz 2>/dev/null | head -n1)"

log "============================================================"
log " ArixLog - vuelta atras"
log " Panel:    $PANEL"
log " Respaldo: $BACKUP_DIR"
log " Archivos: ${FILES_BACKUP:-(ninguno)}"
log " Base de datos: ${DB_BACKUP:-(ninguna)}"
log "============================================================"

if [ -z "$FILES_BACKUP" ]; then
    fail "No se encontro ningun respaldo de archivos en $BACKUP_DIR"
fi

step "Comprobando el respaldo" \
    "tar -tzf '$FILES_BACKUP' > /dev/null"

soft_step "Poniendo el panel en mantenimiento" \
    "cd '$PANEL' && php artisan down || true"

step "Restaurando los archivos del panel" \
    "tar -xzf '$FILES_BACKUP' -C '$PANEL'"

soft_step "Vaciando la cache compilada" \
    "rm -f '$PANEL'/bootstrap/cache/*.php"

if [ -n "$DB_BACKUP" ] && command -v mysql >/dev/null 2>&1; then
    step "Restaurando la base de datos" \
        "gunzip -c '$DB_BACKUP' | mysql --defaults-extra-file='$DB_CREDENTIALS' '$DB_NAME'"
else
    log "AVISO: no se restaura la base de datos (no hay respaldo o falta el comando mysql)."
fi

soft_step "Volviendo a registrar la extension ArixLog" \
    "php '$REGISTER_TOOL' '$PANEL'"

soft_step "Devolviendo los permisos al servidor web" \
    "chmod -R 755 '$PANEL'/storage/* '$PANEL'/bootstrap/cache; chown -R $WEB_USER:$WEB_USER '$PANEL' || true"

soft_step "Limpiando caches" \
    "cd '$PANEL' && php artisan view:clear && php artisan config:clear && php artisan route:clear"

soft_step "Reiniciando los procesos en cola" \
    "cd '$PANEL' && php artisan queue:restart"

step "Sacando el panel del modo mantenimiento" \
    "cd '$PANEL' && php artisan up"

write_status "success" "Vuelta atras terminada" ""

log ""
log "============================================================"
log " El panel ha vuelto al estado anterior a la actualizacion."
log "============================================================"
