package router

// DNS Reverse - complemento para wings
//
// Este archivo es lo que hace que un dominio de un cliente acabe llegando a su
// servidor: escribe la configuracion de nginx del nodo, guarda el certificado
// y, cuando toca, pide y renueva los certificados automaticos de Let's
// Encrypt.
//
// Se puede poner encima de un wings limpio; no reemplaza nada del original.
// Solo hay que anadir las rutas en router.go (el instalador lo hace solo).
//
// QUE SE ARREGLO RESPECTO A LA VERSION ANTERIOR
// ---------------------------------------------
//  1. Un fallo generando la clave del certificado llamaba a log.Fatal, que
//     MATA el proceso de wings entero. Ahora devuelve un error normal.
//  2. Las carpetas de certificados se creaban con os.ModeDir como permisos,
//     que en realidad deja el directorio sin permisos utiles. Ahora 0755, y la
//     clave privada con 0600 en vez de 0644 (antes la podia leer cualquiera).
//  3. El dominio se metia tal cual en una ruta de archivo y en la
//     configuracion de nginx sin comprobar nada. Ahora se valida con una
//     expresion regular estricta.
//  4. Se recargaba nginx sin comprobar la configuracion: un dominio mal puesto
//     tumbaba TODAS las webs del nodo. Ahora se ejecuta "nginx -t" y, si falla,
//     se deja como estaba y se devuelve el error.
//  5. La cuenta de Let's Encrypt se generaba nueva en cada peticion, asi que
//     se acababa chocando con el limite de registros por IP. Ahora se guarda y
//     se reutiliza.
//  6. No habia forma de renovar: los certificados caducaban a los 90 dias y la
//     pagina del cliente dejaba de cargar. Ahora hay endpoint de renovacion y
//     el panel lo llama todas las madrugadas.
//  7. La configuracion de nginx no pasaba WebSockets ni las cabeceras
//     X-Real-IP / X-Forwarded-Proto.

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/apex/log"
	"github.com/gin-gonic/gin"
	"github.com/go-acme/lego/v4/certcrypto"
	"github.com/go-acme/lego/v4/certificate"
	"github.com/go-acme/lego/v4/challenge/http01"
	"github.com/go-acme/lego/v4/lego"
	"github.com/go-acme/lego/v4/registration"
)

// Version del complemento. El panel la lee para saber si el nodo esta al dia.
const dnsReverseVersion = 2

const (
	dnsReverseCertRoot      = "/srv/server_certs"
	dnsReverseAcmeDir       = "/srv/server_certs/.acme"
	dnsReverseChallenge     = "127.0.0.1"
	dnsReverseChallengePort = "81"
	dnsReverseUpgradeMap    = "/etc/nginx/conf.d/dnsreverse-upgrade.conf"
)

// Un nombre de dominio y nada mas: minusculas, numeros, guiones y puntos. Se
// aplica antes de tocar el disco porque el dominio acaba formando parte del
// nombre de un archivo y del contenido de la configuracion de nginx.
var dnsReverseDomainRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// Solo digitos, hasta 5. El puerto tambien va en el nombre del archivo.
var dnsReversePortRe = regexp.MustCompile(`^[0-9]{1,5}$`)

// Solo se puede pedir un certificado a la vez: el desafio HTTP-01 ocupa el
// puerto 81 y dos peticiones simultaneas se pisarian.
var dnsReverseAcmeLock sync.Mutex

// Y una sola escritura de nginx a la vez, para que "nginx -t" no vea la
// configuracion a medias de otra peticion.
var dnsReverseNginxLock sync.Mutex

// ---------------------------------------------------------------------------
//  Estructuras de entrada
// ---------------------------------------------------------------------------

type dnsReverseCreateRequest struct {
	Domain string `json:"domain"`
	IP     string `json:"ip"`
	Port   string `json:"port"`
	Ssl    bool   `json:"ssl"`

	// none | origin | letsencrypt
	Mode string `json:"mode"`

	// Compatibilidad con la version anterior del panel, que solo mandaba un
	// interruptor en vez de un modo.
	UseLetsEncrypt bool `json:"use_lets_encrypt"`

	ClientEmail string `json:"client_email"`
	SslCert     string `json:"ssl_cert"`
	SslKey      string `json:"ssl_key"`

	Websockets bool `json:"websockets"`
	ForceRenew bool `json:"force_renew"`
}

func (r *dnsReverseCreateRequest) mode() string {
	if r.Mode != "" {
		return r.Mode
	}

	if !r.Ssl {
		return "none"
	}

	if r.UseLetsEncrypt {
		return "letsencrypt"
	}

	return "origin"
}

type dnsReverseDeleteRequest struct {
	Domain string `json:"domain"`
	Port   string `json:"port"`
}

type dnsReverseRenewRequest struct {
	Days int `json:"days"`
}

// Lo que se guarda junto al certificado para saber como se genero.
type dnsReverseCertMeta struct {
	Domain   string    `json:"domain"`
	Mode     string    `json:"mode"`
	Email    string    `json:"email"`
	IssuedAt time.Time `json:"issued_at"`
}

// ---------------------------------------------------------------------------
//  Rutas de nodo (version 2)
// ---------------------------------------------------------------------------

// getDnsReverseStatus responde que version del complemento tiene el nodo, si
// nginx esta disponible y que certificados hay guardados. El panel lo usa para
// avisar cuando un nodo se queda atras.
func getDnsReverseStatus(c *gin.Context) {
	nginxOk := true
	mensaje := ""

	if err := dnsReverseNginxTest(); err != nil {
		nginxOk = false
		mensaje = "nginx no acepta su configuracion actual: " + err.Error()
	}

	c.JSON(http.StatusOK, gin.H{
		"version": dnsReverseVersion,
		"nginx":   nginxOk,
		"message": mensaje,
		"certs":   dnsReverseListCertificates(),
	})
}

// postDnsReverseCreate monta (o rehace) un dominio en el nodo.
func postDnsReverseCreate(c *gin.Context) {
	var datos dnsReverseCreateRequest

	if err := c.BindJSON(&datos); err != nil {
		return
	}

	if err := dnsReverseApply(&datos); err != nil {
		log.WithField("error", err).WithField("domain", datos.Domain).Error("dns-reverse: no se pudo montar el dominio")
		c.AbortWithStatusJSON(http.StatusBadRequest, gin.H{"error": err.Error()})

		return
	}

	c.Status(http.StatusAccepted)
}

// postDnsReverseDelete quita la configuracion de nginx de un dominio.
//
// El certificado NO se borra a proposito: si el cliente vuelve a crear el
// mismo dominio se reutiliza en vez de pedir otro, y asi no se agota el cupo
// semanal de Let's Encrypt.
func postDnsReverseDelete(c *gin.Context) {
	var datos dnsReverseDeleteRequest

	if err := c.BindJSON(&datos); err != nil {
		return
	}

	if err := dnsReverseRemove(datos.Domain, datos.Port); err != nil {
		log.WithField("error", err).WithField("domain", datos.Domain).Warn("dns-reverse: no se pudo quitar el dominio")
		c.AbortWithStatusJSON(http.StatusBadRequest, gin.H{"error": err.Error()})

		return
	}

	c.Status(http.StatusAccepted)
}

// postDnsReverseRenew renueva los certificados automaticos que caducan pronto.
func postDnsReverseRenew(c *gin.Context) {
	var datos dnsReverseRenewRequest

	if err := c.BindJSON(&datos); err != nil {
		datos.Days = 21
	}

	if datos.Days <= 0 || datos.Days > 89 {
		datos.Days = 21
	}

	renovados, fallidos := dnsReverseRenewAll(datos.Days)

	c.JSON(http.StatusOK, gin.H{
		"renewed": renovados,
		"failed":  fallidos,
		"message": fmt.Sprintf("Revisados los certificados que caducan en menos de %d dias: %d renovados, %d con problemas.",
			datos.Days, len(renovados), len(fallidos)),
	})
}

// ---------------------------------------------------------------------------
//  Rutas por servidor (version 1, compatibilidad)
// ---------------------------------------------------------------------------
//
// Son las que instalaba la version anterior. Se mantienen para que un panel
// antiguo siga funcionando contra un wings ya actualizado.

func postServerProxyCreate(c *gin.Context) {
	postDnsReverseCreate(c)
}

func postServerProxyDelete(c *gin.Context) {
	postDnsReverseDelete(c)
}

// ---------------------------------------------------------------------------
//  Nucleo
// ---------------------------------------------------------------------------

func dnsReverseApply(datos *dnsReverseCreateRequest) error {
	dominio := strings.ToLower(strings.TrimSpace(datos.Domain))
	puerto := strings.TrimSpace(datos.Port)
	ip := strings.TrimSpace(datos.IP)

	if !dnsReverseDomainRe.MatchString(dominio) || len(dominio) > 253 {
		return errors.New("el dominio no es valido")
	}

	if !dnsReversePortRe.MatchString(puerto) {
		return errors.New("el puerto no es valido")
	}

	if !dnsReverseValidHost(ip) {
		return errors.New("la direccion de destino no es valida")
	}

	modo := datos.mode()

	// --- 1. Configuracion sin SSL primero -------------------------------
	//
	// Hace falta para que el desafio de Let's Encrypt pueda llegar por el
	// puerto 80. Con certificado de origen tambien se escribe: si algo falla
	// despues, el dominio al menos responde por http en vez de dar un error
	// de nginx.
	if err := dnsReverseWriteSite(dominio, puerto, dnsReverseHTTPConfig(dominio, ip, puerto, datos.Websockets)); err != nil {
		return err
	}

	if modo == "none" {
		return nil
	}

	var cert, clave []byte
	var err error

	switch modo {
	case "letsencrypt":
		cert, clave, err = dnsReverseEnsureLetsEncrypt(dominio, datos.ClientEmail, datos.ForceRenew)
	case "origin":
		cert = []byte(strings.TrimSpace(datos.SslCert))
		clave = []byte(strings.TrimSpace(datos.SslKey))

		if len(cert) == 0 || len(clave) == 0 {
			// Reutiliza el que ya hubiera en disco (resincronizaciones).
			cert, clave, err = dnsReverseReadCertificate(dominio)

			if err != nil {
				return errors.New("no se recibio el certificado de origen y no hay ninguno guardado para este dominio")
			}
		}
	default:
		return errors.New("modo de certificado desconocido: " + modo)
	}

	if err != nil {
		return err
	}

	if err := dnsReverseStoreCertificate(dominio, cert, clave, modo, datos.ClientEmail); err != nil {
		return err
	}

	// --- 2. Ahora si, la configuracion con SSL --------------------------
	return dnsReverseWriteSite(dominio, puerto, dnsReverseHTTPSConfig(dominio, ip, puerto, datos.Websockets))
}

func dnsReverseRemove(domain, port string) error {
	dominio := strings.ToLower(strings.TrimSpace(domain))
	puerto := strings.TrimSpace(port)

	if !dnsReverseDomainRe.MatchString(dominio) {
		return errors.New("el dominio no es valido")
	}

	if !dnsReversePortRe.MatchString(puerto) {
		return errors.New("el puerto no es valido")
	}

	dnsReverseNginxLock.Lock()
	defer dnsReverseNginxLock.Unlock()

	nombre := dnsReverseSiteName(dominio, puerto)

	for _, ruta := range dnsReverseSitePaths(nombre) {
		if err := os.Remove(ruta); err != nil && !os.IsNotExist(err) {
			log.WithField("error", err).WithField("path", ruta).Warn("dns-reverse: no se pudo borrar el archivo de nginx")
		}
	}

	// Si la configuracion que queda no es valida se avisa, pero no se recarga
	// para no tirar el nginx del nodo entero.
	if err := dnsReverseNginxTest(); err != nil {
		return fmt.Errorf("el dominio se quito pero nginx tiene otra configuracion rota: %w", err)
	}

	return dnsReverseNginxReload()
}

// ---------------------------------------------------------------------------
//  nginx
// ---------------------------------------------------------------------------

func dnsReverseSiteName(domain, port string) string {
	return domain + "_" + port + ".conf"
}

// Debian y Ubuntu usan sites-available/sites-enabled; otras distribuciones
// (RHEL, Alma, Rocky) solo tienen conf.d. Se detecta en caliente.
func dnsReverseUsesSitesDirs() bool {
	info, err := os.Stat("/etc/nginx/sites-available")

	return err == nil && info.IsDir()
}

// Devuelve todas las rutas que ocupa un sitio, para poder borrarlas.
func dnsReverseSitePaths(nombre string) []string {
	if dnsReverseUsesSitesDirs() {
		return []string{
			filepath.Join("/etc/nginx/sites-available", nombre),
			filepath.Join("/etc/nginx/sites-enabled", nombre),
		}
	}

	return []string{filepath.Join("/etc/nginx/conf.d", nombre)}
}

// dnsReverseWriteSite escribe la configuracion, comprueba que nginx la acepta
// y solo entonces la deja puesta. Si nginx la rechaza se restaura lo que
// hubiera antes, asi que una peticion mala nunca deja el nodo sin webs.
func dnsReverseWriteSite(domain, port string, contenido []byte) error {
	dnsReverseNginxLock.Lock()
	defer dnsReverseNginxLock.Unlock()

	if err := dnsReverseEnsureUpgradeMap(); err != nil {
		return err
	}

	nombre := dnsReverseSiteName(domain, port)
	rutas := dnsReverseSitePaths(nombre)
	destino := rutas[0]

	if err := os.MkdirAll(filepath.Dir(destino), 0o755); err != nil {
		return fmt.Errorf("no se pudo preparar la carpeta de nginx: %w", err)
	}

	// Copia de lo que hubiera antes, para poder volver atras si nginx rechaza
	// la nueva. errAnterior == nil significa que si habia algo.
	anterior, errAnterior := os.ReadFile(destino)

	if err := os.WriteFile(destino, contenido, 0o644); err != nil {
		return fmt.Errorf("no se pudo escribir la configuracion de nginx: %w", err)
	}

	// En Debian hace falta el enlace en sites-enabled.
	if len(rutas) > 1 {
		if err := os.MkdirAll(filepath.Dir(rutas[1]), 0o755); err != nil {
			return fmt.Errorf("no se pudo preparar sites-enabled: %w", err)
		}

		if _, err := os.Lstat(rutas[1]); os.IsNotExist(err) {
			if err := os.Symlink(destino, rutas[1]); err != nil {
				return fmt.Errorf("no se pudo enlazar la configuracion en sites-enabled: %w", err)
			}
		}
	}

	if err := dnsReverseNginxTest(); err != nil {
		// Marcha atras: se deja exactamente como estaba.
		if errAnterior == nil {
			_ = os.WriteFile(destino, anterior, 0o644)
		} else {
			for _, ruta := range rutas {
				_ = os.Remove(ruta)
			}
		}

		return fmt.Errorf("nginx rechazo la configuracion, no se ha cambiado nada: %w", err)
	}

	return dnsReverseNginxReload()
}

// El paso de WebSockets necesita un "map" en el contexto http. Se declara con
// un nombre de variable propio (dnsreverse_connection_upgrade) para que no
// pueda chocar con el que ya tuviera puesto el administrador del nodo.
func dnsReverseEnsureUpgradeMap() error {
	if _, err := os.Stat(dnsReverseUpgradeMap); err == nil {
		return nil
	}

	if _, err := os.Stat("/etc/nginx/conf.d"); err != nil {
		// Sin conf.d no se puede declarar el map; se sigue sin WebSockets.
		return nil
	}

	contenido := []byte("# Generado por DNS Reverse. Permite pasar WebSockets a los servidores.\n" +
		"map $http_upgrade $dnsreverse_connection_upgrade {\n" +
		"    default upgrade;\n" +
		"    ''      close;\n" +
		"}\n")

	if err := os.WriteFile(dnsReverseUpgradeMap, contenido, 0o644); err != nil {
		return fmt.Errorf("no se pudo escribir %s: %w", dnsReverseUpgradeMap, err)
	}

	return nil
}

func dnsReverseNginxTest() error {
	salida, err := exec.Command("nginx", "-t").CombinedOutput()

	if err != nil {
		return errors.New(strings.TrimSpace(string(salida)))
	}

	return nil
}

func dnsReverseNginxReload() error {
	if err := exec.Command("systemctl", "reload", "nginx").Run(); err == nil {
		return nil
	}

	// Sin systemd (contenedores, algunas distribuciones) se recarga directo.
	salida, err := exec.Command("nginx", "-s", "reload").CombinedOutput()

	if err != nil {
		return fmt.Errorf("no se pudo recargar nginx: %s", strings.TrimSpace(string(salida)))
	}

	return nil
}

func dnsReverseProxyBlock(ip, port string, websockets bool) string {
	bloque := "    location / {\n"

	if websockets {
		bloque += "" +
			"        proxy_http_version 1.1;\n" +
			"        proxy_set_header Upgrade $http_upgrade;\n" +
			"        proxy_set_header Connection $dnsreverse_connection_upgrade;\n"
	}

	bloque += "" +
		"        proxy_set_header Host $host;\n" +
		"        proxy_set_header X-Real-IP $remote_addr;\n" +
		"        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n" +
		"        proxy_set_header X-Forwarded-Proto $scheme;\n" +
		"        proxy_read_timeout 300s;\n" +
		"        proxy_send_timeout 300s;\n" +
		"        proxy_pass http://" + ip + ":" + port + ";\n" +
		"    }\n"

	return bloque
}

const dnsReverseAcmeLocation = "" +
	"    location /.well-known/acme-challenge/ {\n" +
	"        proxy_set_header Host $host;\n" +
	"        proxy_pass http://127.0.0.1:" + dnsReverseChallengePort + ";\n" +
	"    }\n"

func dnsReverseHTTPConfig(domain, ip, port string, websockets bool) []byte {
	return []byte("" +
		"# Generado por DNS Reverse. No editar a mano: se reescribe solo.\n" +
		"server {\n" +
		"    listen 80;\n" +
		"    server_name " + domain + ";\n\n" +
		"    client_max_body_size 512m;\n\n" +
		dnsReverseAcmeLocation + "\n" +
		dnsReverseProxyBlock(ip, port, websockets) +
		"}\n")
}

func dnsReverseHTTPSConfig(domain, ip, port string, websockets bool) []byte {
	cert, clave := dnsReverseCertPaths(domain)

	return []byte("" +
		"# Generado por DNS Reverse. No editar a mano: se reescribe solo.\n" +
		"server {\n" +
		"    listen 80;\n" +
		"    server_name " + domain + ";\n\n" +
		// El desafio de Let's Encrypt tiene que seguir llegando por el puerto
		// 80 aunque haya redireccion, o las renovaciones fallarian.
		dnsReverseAcmeLocation + "\n" +
		"    location / {\n" +
		"        return 301 https://$host$request_uri;\n" +
		"    }\n" +
		"}\n\n" +
		"server {\n" +
		"    listen 443 ssl http2;\n" +
		"    server_name " + domain + ";\n\n" +
		"    ssl_certificate " + cert + ";\n" +
		"    ssl_certificate_key " + clave + ";\n" +
		"    ssl_session_cache shared:DNSREV:10m;\n" +
		"    ssl_session_timeout 1d;\n" +
		"    ssl_protocols TLSv1.2 TLSv1.3;\n" +
		"    ssl_prefer_server_ciphers off;\n" +
		"    ssl_ciphers \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\";\n\n" +
		"    client_max_body_size 512m;\n\n" +
		dnsReverseAcmeLocation + "\n" +
		dnsReverseProxyBlock(ip, port, websockets) +
		"}\n")
}

// ---------------------------------------------------------------------------
//  Certificados en disco
// ---------------------------------------------------------------------------

func dnsReverseCertPaths(domain string) (string, string) {
	base := filepath.Join(dnsReverseCertRoot, domain)

	return filepath.Join(base, "cert.pem"), filepath.Join(base, "key.pem")
}

func dnsReverseStoreCertificate(domain string, cert, clave []byte, modo, email string) error {
	rutaCert, rutaClave := dnsReverseCertPaths(domain)

	// 0755 de verdad. La version anterior pasaba os.ModeDir como permisos, que
	// no es un permiso: dejaba la carpeta sin bits de acceso.
	if err := os.MkdirAll(filepath.Dir(rutaCert), 0o755); err != nil {
		return fmt.Errorf("no se pudo crear la carpeta del certificado: %w", err)
	}

	if err := os.WriteFile(rutaCert, cert, 0o644); err != nil {
		return fmt.Errorf("no se pudo guardar el certificado: %w", err)
	}

	// La clave privada no la puede leer todo el mundo.
	if err := os.WriteFile(rutaClave, clave, 0o600); err != nil {
		return fmt.Errorf("no se pudo guardar la clave del certificado: %w", err)
	}

	meta := dnsReverseCertMeta{
		Domain:   domain,
		Mode:     modo,
		Email:    email,
		IssuedAt: time.Now().UTC(),
	}

	if datos, err := json.MarshalIndent(meta, "", "  "); err == nil {
		_ = os.WriteFile(filepath.Join(filepath.Dir(rutaCert), "meta.json"), datos, 0o600)
	}

	return nil
}

func dnsReverseReadCertificate(domain string) ([]byte, []byte, error) {
	rutaCert, rutaClave := dnsReverseCertPaths(domain)

	cert, err := os.ReadFile(rutaCert)
	if err != nil {
		return nil, nil, err
	}

	clave, err := os.ReadFile(rutaClave)
	if err != nil {
		return nil, nil, err
	}

	return cert, clave, nil
}

func dnsReverseReadMeta(domain string) dnsReverseCertMeta {
	meta := dnsReverseCertMeta{Domain: domain}

	datos, err := os.ReadFile(filepath.Join(dnsReverseCertRoot, domain, "meta.json"))
	if err != nil {
		return meta
	}

	_ = json.Unmarshal(datos, &meta)

	return meta
}

// Fecha de caducidad y emisor leidos del propio certificado.
func dnsReverseInspect(domain string) (time.Time, string, error) {
	rutaCert, _ := dnsReverseCertPaths(domain)

	datos, err := os.ReadFile(rutaCert)
	if err != nil {
		return time.Time{}, "", err
	}

	bloque, _ := pem.Decode(datos)
	if bloque == nil {
		return time.Time{}, "", errors.New("el certificado no tiene formato PEM")
	}

	certificado, err := x509.ParseCertificate(bloque.Bytes)
	if err != nil {
		return time.Time{}, "", err
	}

	return certificado.NotAfter, certificado.Issuer.CommonName, nil
}

func dnsReverseListCertificates() []gin.H {
	salida := make([]gin.H, 0)

	entradas, err := os.ReadDir(dnsReverseCertRoot)
	if err != nil {
		return salida
	}

	for _, entrada := range entradas {
		if !entrada.IsDir() || strings.HasPrefix(entrada.Name(), ".") {
			continue
		}

		dominio := entrada.Name()
		caduca, emisor, err := dnsReverseInspect(dominio)

		if err != nil {
			continue
		}

		meta := dnsReverseReadMeta(dominio)

		salida = append(salida, gin.H{
			"domain":     dominio,
			"expires_at": caduca.UTC().Format(time.RFC3339),
			"days_left":  int(time.Until(caduca).Hours() / 24),
			"issuer":     emisor,
			"mode":       meta.Mode,
			"in_use":     dnsReverseSiteExists(dominio),
		})
	}

	return salida
}

// ¿Queda alguna configuracion de nginx usando este dominio? Sirve para no
// renovar eternamente certificados de dominios que ya nadie usa.
func dnsReverseSiteExists(domain string) bool {
	var carpeta string

	if dnsReverseUsesSitesDirs() {
		carpeta = "/etc/nginx/sites-available"
	} else {
		carpeta = "/etc/nginx/conf.d"
	}

	entradas, err := os.ReadDir(carpeta)
	if err != nil {
		return false
	}

	for _, entrada := range entradas {
		if strings.HasPrefix(entrada.Name(), domain+"_") && strings.HasSuffix(entrada.Name(), ".conf") {
			return true
		}
	}

	return false
}

// ---------------------------------------------------------------------------
//  Let's Encrypt
// ---------------------------------------------------------------------------

type dnsReverseAcmeUser struct {
	Email        string                 `json:"email"`
	Registration *registration.Resource `json:"registration"`

	key crypto.PrivateKey
}

func (u *dnsReverseAcmeUser) GetEmail() string                        { return u.Email }
func (u *dnsReverseAcmeUser) GetRegistration() *registration.Resource { return u.Registration }
func (u *dnsReverseAcmeUser) GetPrivateKey() crypto.PrivateKey        { return u.key }

// dnsReverseEnsureLetsEncrypt devuelve un certificado valido para el dominio.
// Si ya hay uno guardado y le queda cuerda, se reutiliza: asi una
// resincronizacion del panel no gasta cupo de Let's Encrypt.
func dnsReverseEnsureLetsEncrypt(domain, email string, forzar bool) ([]byte, []byte, error) {
	if !forzar {
		if caduca, _, err := dnsReverseInspect(domain); err == nil && time.Until(caduca) > 21*24*time.Hour {
			cert, clave, err := dnsReverseReadCertificate(domain)

			if err == nil {
				return cert, clave, nil
			}
		}
	}

	return dnsReverseObtainCertificate(domain, email)
}

func dnsReverseObtainCertificate(domain, email string) ([]byte, []byte, error) {
	// El desafio HTTP-01 ocupa un puerto fijo: de uno en uno.
	dnsReverseAcmeLock.Lock()
	defer dnsReverseAcmeLock.Unlock()

	usuario, err := dnsReverseLoadAccount(email)
	if err != nil {
		return nil, nil, err
	}

	configuracion := lego.NewConfig(usuario)
	configuracion.Certificate.KeyType = certcrypto.RSA2048

	cliente, err := lego.NewClient(configuracion)
	if err != nil {
		return nil, nil, fmt.Errorf("no se pudo hablar con Let's Encrypt: %w", err)
	}

	// Se escucha solo en 127.0.0.1: nginx es quien reenvia el desafio, asi que
	// no hace falta abrir el puerto 81 al exterior.
	if err := cliente.Challenge.SetHTTP01Provider(http01.NewProviderServer(dnsReverseChallenge, dnsReverseChallengePort)); err != nil {
		return nil, nil, fmt.Errorf("no se pudo preparar la comprobacion del dominio: %w", err)
	}

	if usuario.Registration == nil {
		registro, err := cliente.Registration.Register(registration.RegisterOptions{TermsOfServiceAgreed: true})

		if err != nil {
			return nil, nil, fmt.Errorf("no se pudo registrar la cuenta de Let's Encrypt: %w", err)
		}

		usuario.Registration = registro

		if err := dnsReverseSaveAccount(usuario); err != nil {
			log.WithField("error", err).Warn("dns-reverse: la cuenta de Let's Encrypt no se pudo guardar")
		}
	}

	certificados, err := cliente.Certificate.Obtain(certificate.ObtainRequest{
		Domains: []string{domain},
		Bundle:  true,
	})

	if err != nil {
		return nil, nil, fmt.Errorf("Let's Encrypt no pudo comprobar %s. Revisa que el dominio apunte a la IP de este nodo "+
			"y que en Cloudflare este con la nube gris (DNS only). Detalle: %w", domain, err)
	}

	return certificados.Certificate, certificados.PrivateKey, nil
}

// La cuenta se guarda en disco y se reutiliza siempre. La version anterior
// generaba una nueva en cada peticion, lo que acababa dando "too many
// registrations for this IP" de Let's Encrypt.
func dnsReverseLoadAccount(email string) (*dnsReverseAcmeUser, error) {
	if err := os.MkdirAll(dnsReverseAcmeDir, 0o700); err != nil {
		return nil, fmt.Errorf("no se pudo preparar la carpeta de la cuenta ACME: %w", err)
	}

	rutaClave := filepath.Join(dnsReverseAcmeDir, "account.key")
	rutaCuenta := filepath.Join(dnsReverseAcmeDir, "account.json")

	usuario := &dnsReverseAcmeUser{Email: strings.TrimSpace(email)}

	// --- Clave de la cuenta ---
	if datos, err := os.ReadFile(rutaClave); err == nil {
		bloque, _ := pem.Decode(datos)

		if bloque != nil {
			if clave, err := x509.ParseECPrivateKey(bloque.Bytes); err == nil {
				usuario.key = clave
			}
		}
	}

	if usuario.key == nil {
		clave, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			return nil, fmt.Errorf("no se pudo generar la clave de la cuenta: %w", err)
		}

		codificada, err := x509.MarshalECPrivateKey(clave)
		if err != nil {
			return nil, fmt.Errorf("no se pudo codificar la clave de la cuenta: %w", err)
		}

		if err := os.WriteFile(rutaClave, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: codificada}), 0o600); err != nil {
			return nil, fmt.Errorf("no se pudo guardar la clave de la cuenta: %w", err)
		}

		usuario.key = clave
	}

	// --- Registro ---
	if datos, err := os.ReadFile(rutaCuenta); err == nil {
		guardado := &dnsReverseAcmeUser{}

		if err := json.Unmarshal(datos, guardado); err == nil && guardado.Registration != nil {
			usuario.Registration = guardado.Registration

			// El correo de la cuenta manda sobre el que llegue en la peticion:
			// es el que esta registrado en Let's Encrypt.
			if guardado.Email != "" {
				usuario.Email = guardado.Email
			}
		}
	}

	if usuario.Email == "" {
		// Let's Encrypt admite cuentas sin correo, pero entonces no avisa de
		// las caducidades. Se deja vacio antes que inventarse uno falso.
		usuario.Email = ""
	}

	return usuario, nil
}

func dnsReverseSaveAccount(usuario *dnsReverseAcmeUser) error {
	datos, err := json.MarshalIndent(usuario, "", "  ")
	if err != nil {
		return err
	}

	return os.WriteFile(filepath.Join(dnsReverseAcmeDir, "account.json"), datos, 0o600)
}

// dnsReverseRenewAll repasa todos los certificados guardados y renueva los
// automaticos que caducan dentro de los proximos "dias".
func dnsReverseRenewAll(dias int) ([]string, []string) {
	renovados := make([]string, 0)
	fallidos := make([]string, 0)

	entradas, err := os.ReadDir(dnsReverseCertRoot)
	if err != nil {
		return renovados, fallidos
	}

	limite := time.Duration(dias) * 24 * time.Hour

	for _, entrada := range entradas {
		if !entrada.IsDir() || strings.HasPrefix(entrada.Name(), ".") {
			continue
		}

		dominio := entrada.Name()

		// Un dominio que ya no tiene configuracion en nginx no se renueva:
		// seria gastar cupo de Let's Encrypt para nada.
		if !dnsReverseSiteExists(dominio) {
			continue
		}

		caduca, emisor, err := dnsReverseInspect(dominio)
		if err != nil {
			continue
		}

		if time.Until(caduca) > limite {
			continue
		}

		meta := dnsReverseReadMeta(dominio)

		// Los certificados de origen de Cloudflare duran 15 anos y no se
		// renuevan por ACME. Los que vienen de la version anterior no tienen
		// meta.json, asi que se mira quien los emitio.
		if meta.Mode == "origin" {
			continue
		}

		if meta.Mode == "" && !dnsReverseLooksLikeAcme(emisor) {
			continue
		}

		cert, clave, err := dnsReverseObtainCertificate(dominio, meta.Email)
		if err != nil {
			log.WithField("error", err).WithField("domain", dominio).Warn("dns-reverse: no se pudo renovar")
			fallidos = append(fallidos, dominio+": "+err.Error())

			continue
		}

		if err := dnsReverseStoreCertificate(dominio, cert, clave, "letsencrypt", meta.Email); err != nil {
			fallidos = append(fallidos, dominio+": "+err.Error())

			continue
		}

		renovados = append(renovados, dominio)
	}

	if len(renovados) > 0 {
		if err := dnsReverseNginxReload(); err != nil {
			log.WithField("error", err).Warn("dns-reverse: certificados renovados pero nginx no recargo")
		}
	}

	return renovados, fallidos
}

func dnsReverseLooksLikeAcme(emisor string) bool {
	emisor = strings.ToLower(emisor)

	for _, pista := range []string{"let's encrypt", "lets encrypt", "r3", "r10", "r11", "e1", "e5", "e6", "zerossl"} {
		if strings.Contains(emisor, pista) {
			return true
		}
	}

	return false
}

// ---------------------------------------------------------------------------
//  Utilidades
// ---------------------------------------------------------------------------

// El destino tiene que ser una IP o un nombre de maquina: acaba dentro de un
// proxy_pass, asi que no puede llevar espacios, punto y coma ni saltos.
func dnsReverseValidHost(valor string) bool {
	if valor == "" || len(valor) > 253 {
		return false
	}

	for _, caracter := range valor {
		esValido := (caracter >= 'a' && caracter <= 'z') ||
			(caracter >= 'A' && caracter <= 'Z') ||
			(caracter >= '0' && caracter <= '9') ||
			caracter == '.' || caracter == '-' || caracter == ':'

		if !esValido {
			return false
		}
	}

	return true
}
