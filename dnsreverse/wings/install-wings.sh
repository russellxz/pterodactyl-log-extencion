#!/usr/bin/env bash
# =============================================================================
#  DNS Reverse - complemento de wings
#
#  Se ejecuta EN EL NODO (la maquina donde corre wings), no en el panel.
#
#  Que hace:
#    1. Mira que version de wings tienes puesta y se baja ESA misma version.
#    2. Le anade el complemento de DNS Reverse.
#    3. Lo compila y sustituye el binario, guardando antes una copia del actual.
#    4. Reinicia wings y comprueba que ha arrancado bien.
#
#  Que NO hace:
#    - No toca ni un solo servidor, ni sus archivos, ni sus volumenes.
#    - No borra configuraciones de nginx que ya existan.
#    - No borra certificados: los de /srv/server_certs se quedan donde estan,
#      asi que los dominios que ya funcionaban siguen funcionando.
#
#  Uso:
#      sudo bash install-wings.sh [--version v1.11.13] [--go 1.22.5]
# =============================================================================
set -uo pipefail

VERSION_WINGS=""
VERSION_GO="1.22.5"
USAR_ULTIMA=0
TRABAJO="/usr/local/src/dnsreverse-wings"
DESTINO="/usr/local/bin/wings"
CERTS="/srv/server_certs"
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION_WINGS="${2:-}"; shift 2 ;;
        --go) VERSION_GO="${2:-}"; shift 2 ;;
        --latest) USAR_ULTIMA=1; shift ;;
        --help|-h)
            printf 'Uso: sudo bash install-wings.sh [--version v1.11.13] [--latest] [--go 1.22.5]\n\n'
            printf '  --version   Compilar contra esa version exacta de wings (lo recomendado).\n'
            printf '  --latest    Usar la ultima publicada. Solo si sabes que tu panel la admite.\n'
            printf '  --go        Version de Go a instalar si hace falta.\n\n'
            exit 0
            ;;
        *) printf 'Opcion desconocida: %s\n' "$1"; exit 1 ;;
    esac
done

# wings expone su version con el subcomando "version", NO con "--version"
# (con el flag suelta "unknown flag" y sale con error).
#
# Ademas, un wings compilado desde codigo fuente no lleva el numero grabado y
# responde "wings vdevelop": es justo lo que pasa cuando ya tienes el
# complemento antiguo puesto, porque tambien se compilo a mano.
version_de_wings() {
    local binario="$1"
    local salida

    salida="$("$binario" version 2>/dev/null | head -1)"

    if [ -z "$salida" ]; then
        salida="$("$binario" --version 2>/dev/null | head -1)"
    fi

    printf '%s' "$salida" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1
}

if [ -t 1 ]; then
    B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'
else
    B=''; G=''; Y=''; R=''; N=''
fi

ok()    { printf '  %s[ok]%s   %s\n' "$G" "$N" "$1"; }
warn()  { printf '  %s[..]%s   %s\n' "$Y" "$N" "$1"; }
err()   { printf '  %s[!!]%s   %s\n' "$R" "$N" "$1"; }
title() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

printf '\n%s  DNS Reverse - complemento de wings%s\n' "$B" "$N"
printf '  ---------------------------------------------------------------\n'

# --- Comprobaciones previas -------------------------------------------------

if [ "$(id -u)" != "0" ]; then
    err "Hay que ejecutarlo como root (usa sudo)."
    exit 1
fi

if [ ! -f "$AQUI/router_dns_reverse.go" ]; then
    err "No se encontro router_dns_reverse.go junto a este script."
    printf '  ¿Has clonado el repositorio entero?\n\n'
    exit 1
fi

if [ ! -x "$DESTINO" ]; then
    err "No se encontro wings en $DESTINO."
    printf '  Este script se ejecuta en el NODO, no en el panel.\n\n'
    exit 1
fi

# --- 1. Version de wings ----------------------------------------------------

title "1. Averiguando la version de wings"

ULTIMA_PUBLICADA=""

obtener_ultima() {
    ULTIMA_PUBLICADA="$(curl -fsSL https://api.github.com/repos/pterodactyl/wings/releases/latest 2>/dev/null \
        | grep '"tag_name"' | head -1 | cut -d'"' -f4)"
}

if [ -n "$VERSION_WINGS" ]; then
    case "$VERSION_WINGS" in
        v*) ;;
        *) VERSION_WINGS="v$VERSION_WINGS" ;;
    esac
    ok "Version indicada a mano: $VERSION_WINGS"
elif [ "$USAR_ULTIMA" -eq 1 ]; then
    obtener_ultima

    if [ -z "$ULTIMA_PUBLICADA" ]; then
        err "No se pudo consultar la ultima version publicada. Indicala a mano con --version."
        exit 1
    fi

    VERSION_WINGS="$ULTIMA_PUBLICADA"
    warn "Se usara la ultima publicada por peticion tuya: $VERSION_WINGS"
else
    DETECTADA="$(version_de_wings "$DESTINO")"

    if [ -n "$DETECTADA" ]; then
        VERSION_WINGS="v$DETECTADA"
        ok "wings instalado: $VERSION_WINGS"
    else
        # Aqui NO se coge la ultima version por las buenas. Compilar contra una
        # version distinta de la que tienes puesta te cambiaria wings de version
        # sin querer, y una wings mas nueva que tu panel puede dar problemas.
        obtener_ultima

        err "No se ha podido averiguar que version de wings tienes."
        printf '\n'
        printf '  Es lo normal si tu wings ya esta compilado a mano (por ejemplo si ya\n'
        printf '  tenias puesto el complemento antiguo): esos binarios responden\n'
        printf '  "wings vdevelop" y no dicen el numero.\n'
        printf '\n'
        printf '  %sNo se sigue adelante a proposito%s: compilar contra otra version te\n' "$B" "$N"
        printf '  cambiaria wings sin avisar, y una wings mas nueva que tu panel puede\n'
        printf '  romper la conexion con el.\n'
        printf '\n'
        printf '  Como saber tu version:\n'
        printf '    - En el panel: Admin -> Nodes -> tu nodo. Arriba sale la version\n'
        printf '      del demonio que el nodo le esta reportando.\n'
        printf '    - O en este nodo:  wings version\n'
        printf '    - Si las dos dicen "develop", usa la misma serie que tu panel:\n'
        printf '      panel 1.11.x  ->  wings v1.11.13\n'
        printf '\n'
        printf '  Y vuelve a lanzarlo indicandola:\n'
        printf '    %ssudo bash install-wings.sh --version v1.11.13%s\n' "$B" "$N"
        printf '\n'

        if [ -n "$ULTIMA_PUBLICADA" ]; then
            printf '  Si de verdad quieres la ultima publicada (%s), y sabes que tu\n' "$ULTIMA_PUBLICADA"
            printf '  panel la admite:\n'
            printf '    sudo bash install-wings.sh --latest\n\n'
        fi

        exit 1
    fi
fi

# Aviso si se va a compilar contra una version distinta de la instalada.
INSTALADA="$(version_de_wings "$DESTINO")"

if [ -n "$INSTALADA" ] && [ "v$INSTALADA" != "$VERSION_WINGS" ]; then
    warn "Tienes wings v$INSTALADA y se va a compilar $VERSION_WINGS: te cambiara de version."
    warn "Si no era lo que querias, corta con Ctrl+C y usa --version v$INSTALADA"
    sleep 5
fi

# --- 2. Go ------------------------------------------------------------------

title "2. Preparando Go"

export PATH="$PATH:/usr/local/go/bin"

INSTALAR_GO=1

if command -v go >/dev/null 2>&1; then
    ACTUAL="$(go version 2>/dev/null | grep -oE 'go[0-9]+\.[0-9]+' | head -1 | tr -d 'go')"
    MAYOR="${ACTUAL%%.*}"
    MENOR="${ACTUAL##*.}"

    # wings 1.11 pide Go 1.20 o mas nuevo.
    if [ "${MAYOR:-0}" -gt 1 ] || { [ "${MAYOR:-0}" -eq 1 ] && [ "${MENOR:-0}" -ge 20 ]; }; then
        ok "Go $ACTUAL ya instalado"
        INSTALAR_GO=0
    else
        warn "Go $ACTUAL es demasiado antiguo, se instalara $VERSION_GO"
    fi
fi

if [ "$INSTALAR_GO" -eq 1 ]; then
    ARQ="$(uname -m)"
    case "$ARQ" in
        x86_64) ARQ="amd64" ;;
        aarch64|arm64) ARQ="arm64" ;;
        *) err "Arquitectura no soportada por este script: $ARQ"; exit 1 ;;
    esac

    PAQUETE="go${VERSION_GO}.linux-${ARQ}.tar.gz"
    warn "Descargando Go $VERSION_GO ($ARQ)..."

    if ! curl -fsSL -o "/tmp/$PAQUETE" "https://go.dev/dl/$PAQUETE"; then
        err "No se pudo descargar Go."
        exit 1
    fi

    rm -rf /usr/local/go
    tar -C /usr/local -xzf "/tmp/$PAQUETE"
    rm -f "/tmp/$PAQUETE"

    if ! command -v go >/dev/null 2>&1; then
        err "Go no quedo disponible en el PATH."
        exit 1
    fi

    ok "Go $VERSION_GO instalado en /usr/local/go"
fi

# --- 3. Codigo de wings -----------------------------------------------------

title "3. Descargando el codigo de wings $VERSION_WINGS"

rm -rf "$TRABAJO"
mkdir -p "$TRABAJO"

if ! curl -fsSL -o "$TRABAJO/wings.tar.gz" "https://github.com/pterodactyl/wings/archive/refs/tags/${VERSION_WINGS}.tar.gz"; then
    err "No se pudo descargar el codigo de wings $VERSION_WINGS."
    printf '  Comprueba que esa version existe en GitHub.\n\n'
    exit 1
fi

tar -xzf "$TRABAJO/wings.tar.gz" -C "$TRABAJO"
FUENTES="$(find "$TRABAJO" -maxdepth 1 -type d -name 'wings-*' | head -1)"

if [ -z "$FUENTES" ] || [ ! -f "$FUENTES/router/router.go" ]; then
    err "El paquete descargado no tiene la pinta esperada."
    exit 1
fi

ok "Codigo listo en $FUENTES"

# --- 4. Anadir el complemento -----------------------------------------------

title "4. Anadiendo el complemento"

cp "$AQUI/router_dns_reverse.go" "$FUENTES/router/router_dns_reverse.go"
ok "router_dns_reverse.go copiado"

if grep -q 'DNS Reverse (inicio)' "$FUENTES/router/router.go"; then
    ok "Las rutas ya estaban puestas"
else
    # Se insertan justo detras de la linea de autorizacion, que es la mas
    # estable de router.go: existe igual en todas las versiones de wings 1.7 en
    # adelante. Las rutas antiguas por servidor se registran aqui tambien, para
    # que un panel que todavia use la version anterior siga funcionando.
    awk '
    {
      print
      if (!hecho && $0 ~ /protected[[:space:]]*:=[[:space:]]*router\.Use\(middleware\.RequireAuthorization\(\)\)/) {
        print ""
        print "\t// --- DNS Reverse (inicio) - anadido por install-wings.sh ---"
        print "\tprotected.GET(\"/api/dns-reverse/status\", getDnsReverseStatus)"
        print "\tprotected.POST(\"/api/dns-reverse/create\", postDnsReverseCreate)"
        print "\tprotected.POST(\"/api/dns-reverse/delete\", postDnsReverseDelete)"
        print "\tprotected.POST(\"/api/dns-reverse/renew\", postDnsReverseRenew)"
        print "\tprotected.POST(\"/api/servers/:server/proxy/create\", postServerProxyCreate)"
        print "\tprotected.POST(\"/api/servers/:server/proxy/delete\", postServerProxyDelete)"
        print "\t// --- DNS Reverse (fin) ---"
        hecho = 1
      }
    }
    END { if (!hecho) exit 3 }
    ' "$FUENTES/router/router.go" > "$FUENTES/router/router.go.nuevo"

    if [ $? -ne 0 ] || ! grep -q 'DNS Reverse (inicio)' "$FUENTES/router/router.go.nuevo"; then
        rm -f "$FUENTES/router/router.go.nuevo"
        err "No se encontro el punto donde insertar las rutas en router/router.go."
        printf '  Anade estas lineas a mano justo debajo de\n'
        printf '  "protected := router.Use(middleware.RequireAuthorization())":\n\n'
        printf '      protected.GET("/api/dns-reverse/status", getDnsReverseStatus)\n'
        printf '      protected.POST("/api/dns-reverse/create", postDnsReverseCreate)\n'
        printf '      protected.POST("/api/dns-reverse/delete", postDnsReverseDelete)\n'
        printf '      protected.POST("/api/dns-reverse/renew", postDnsReverseRenew)\n'
        printf '      protected.POST("/api/servers/:server/proxy/create", postServerProxyCreate)\n'
        printf '      protected.POST("/api/servers/:server/proxy/delete", postServerProxyDelete)\n\n'
        exit 1
    fi

    mv "$FUENTES/router/router.go.nuevo" "$FUENTES/router/router.go"
    ok "Rutas anadidas a router/router.go"
fi

# --- 5. Compilar ------------------------------------------------------------

title "5. Compilando (esto tarda unos minutos)"

cd "$FUENTES" || exit 1

export GOFLAGS="-mod=mod"
export GOPATH="${GOPATH:-/root/go}"
export GOCACHE="${GOCACHE:-/root/.cache/go-build}"

if ! go get github.com/go-acme/lego/v4 >/dev/null 2>&1; then
    err "No se pudo descargar la libreria de certificados (lego)."
    printf '  ¿Tiene el nodo salida a internet?\n\n'
    exit 1
fi

ok "Libreria de certificados descargada"

if ! go mod tidy >/dev/null 2>&1; then
    warn "go mod tidy dio avisos, se intenta compilar igualmente"
fi

# El numero de version se graba en el binario, igual que hace el proyecto
# oficial. Si no se hace, wings responde "vdevelop" y entonces ni el panel sabe
# que version corre en el nodo ni este script puede detectarla la proxima vez.
# (Ese es justo el motivo de que un wings compilado a mano no se pueda
# identificar.)
NUMERO_VERSION="${VERSION_WINGS#v}"

if ! go build -ldflags "-X github.com/pterodactyl/wings/system.Version=$NUMERO_VERSION" -o "$TRABAJO/wings.nuevo" . ; then
    warn "La compilacion con el numero de version fallo, se reintenta sin el"

    if ! go build -o "$TRABAJO/wings.nuevo" . ; then
        err "La compilacion fallo. NO se ha tocado tu wings actual."
        exit 1
    fi
fi

ok "Compilado correctamente"

# --- Comprobacion de que el binario nuevo arranca y responde ---------------
#
# Aqui se usa "version" (subcomando). Con "--version" wings responde
# "unknown flag" y sale con error, que era lo que hacia fallar esta
# comprobacion aunque la compilacion hubiera ido perfecta.

SALIDA_VERSION="$("$TRABAJO/wings.nuevo" version 2>&1 | head -1)"

if printf '%s' "$SALIDA_VERSION" | grep -qi 'wings'; then
    ok "El binario nuevo responde: $SALIDA_VERSION"
elif "$TRABAJO/wings.nuevo" --help >/dev/null 2>&1; then
    # Alguna version futura podria cambiar el texto de "version"; con que
    # responda a --help ya sabemos que el binario es valido.
    ok "El binario nuevo responde"
else
    err "El binario compilado no arranca. NO se ha tocado tu wings actual."
    printf '        Salida obtenida: %s\n' "${SALIDA_VERSION:-(ninguna)}"
    printf '        Puedes probarlo tu mismo:  %s/wings.nuevo version\n\n' "$TRABAJO"
    exit 1
fi

# --- 6. Instalar ------------------------------------------------------------

title "6. Instalando"

RESPALDO="/usr/local/bin/wings.antes-de-dnsreverse.$(date +%Y%m%d%H%M%S)"
cp "$DESTINO" "$RESPALDO"
ok "Copia del wings actual en $RESPALDO"

systemctl stop wings 2>/dev/null || warn "No se pudo parar el servicio wings (¿no usa systemd?)"

install -m 0755 "$TRABAJO/wings.nuevo" "$DESTINO"
ok "Binario instalado en $DESTINO"

mkdir -p "$CERTS"
chmod 755 "$CERTS"
ok "Carpeta de certificados lista: $CERTS"

if systemctl start wings 2>/dev/null; then
    sleep 3

    if systemctl is-active --quiet wings; then
        ok "wings arrancado y funcionando"
    else
        err "wings no arranco. Se vuelve a poner el binario anterior."
        install -m 0755 "$RESPALDO" "$DESTINO"
        systemctl start wings 2>/dev/null
        printf '\n  Mira que ha pasado con:  journalctl -u wings -n 50 --no-pager\n\n'
        exit 1
    fi
else
    warn "Arranca wings a mano: systemctl start wings"
fi

# --- 7. Repaso final --------------------------------------------------------

title "7. Repaso"

if command -v nginx >/dev/null 2>&1; then
    ok "nginx instalado"

    if nginx -t >/dev/null 2>&1; then
        ok "La configuracion de nginx es valida"
    else
        err "nginx tiene la configuracion rota AHORA MISMO (antes de tocar nada)."
        printf '        Arreglalo o los dominios nuevos no se podran montar:\n'
        printf '        nginx -t\n'
    fi
else
    err "nginx NO esta instalado en este nodo."
    printf '        Sin nginx no se puede servir ningun dominio. Instalalo con:\n'
    printf '        apt-get install -y nginx     (Debian/Ubuntu)\n'
    printf '        dnf install -y nginx         (Alma/Rocky/RHEL)\n'
fi

if command -v ss >/dev/null 2>&1; then
    if ss -ltn 2>/dev/null | grep -qE ':80\s'; then
        ok "El puerto 80 esta escuchando"
    else
        warn "Nadie escucha en el puerto 80. Let's Encrypt lo necesita para validar."
    fi
fi

printf '\n%s  Complemento instalado%s\n' "$G$B" "$N"
printf '  ---------------------------------------------------------------\n'
printf '  Version del complemento: 2\n'
printf '  Certificados en:         %s\n' "$CERTS"
printf '  Copia del wings anterior: %s\n' "$RESPALDO"
printf '\n'
printf '  Comprueba desde el panel:  Admin -> DNS Reverse -> Nodos\n'
printf '  Este nodo tiene que salir como "v2 al dia".\n'
printf '\n'
printf '  Si algo va mal y quieres volver atras:\n'
printf '    systemctl stop wings\n'
printf '    install -m 0755 %s /usr/local/bin/wings\n' "$RESPALDO"
printf '    systemctl start wings\n'
printf '\n'
printf '  Los dominios y certificados que ya existian NO se han tocado.\n'
printf '\n'
