# DNS Reverse

Extension para **Pterodactyl** (compatible con el tema **Arix**) que deja a tus
clientes poner un **dominio bonito** a su servidor: una pagina web en
`suempresa.com`, un subdominio tuyo tipo `pepito.tudominio.com`, o un registro
**SRV de Minecraft** para entrar sin escribir el puerto.

Es la evolucion de la extension de "reverse proxy" que se instalaba parcheando
archivos del panel a mano. Hace lo mismo y bastante mas, pero **sin tocar ni un
solo archivo del panel**, asi que ya no desaparece cada vez que actualizas.

---

## Indice

- [Que cambia respecto a la version anterior](#que-cambia-respecto-a-la-version-anterior)
- [Antes de empezar](#antes-de-empezar)
- [Instalacion](#instalacion)
- [Actualizar](#actualizar)
- [Desinstalar](#desinstalar)
- [Que pasa cuando actualizo el panel](#que-pasa-cuando-actualizo-el-panel)
- [Guia del administrador](#guia-del-administrador)
- [Guia del cliente](#guia-del-cliente)
- [Certificados: cual elegir](#certificados-cual-elegir)
- [Venir de la version antigua](#venir-de-la-version-antigua)
- [Comandos](#comandos)
- [Si algo va mal](#si-algo-va-mal)

---

## Que cambia respecto a la version anterior

| | Version anterior | DNS Reverse |
|---|---|---|
| Instalacion | Editar ~20 archivos del panel a mano + `yarn build` | Un script, sin tocar el panel y sin recompilar nada |
| Al actualizar el panel | Desaparece y hay que rehacerlo todo | Se recupera con un comando; los datos no se tocan |
| Tokens de Cloudflare | **Uno solo** para todos los dominios | **Uno por dominio**: puedes mezclar varias cuentas |
| Certificados de origen | **Uno solo** para todos | **Uno por dominio** |
| Anadir dominios | Una caja de texto con comas | Boton "Anadir dominio" con su ficha |
| Limite por servidor | Nace en **0**: nadie puede crear nada | Nace en **1** (configurable) |
| Ver los dominios de los clientes | No habia | Listado con buscador y enlace para abrirlos |
| Bloquear a un cliente | Servidor por servidor en la ficha del panel | Pantalla propia, con cambio en bloque |
| Renovar certificados | **No se renovaban**: caducaban a los 90 dias | Automatica todas las madrugadas |
| Idioma | Ingles | Espanol |
| Si nginx queda mal | Se recargaba igual y tumbaba **todas** las webs del nodo | Se comprueba antes y se deshace si falla |

### Fallos de la version anterior que se han corregido

Al revisar el codigo del complemento de wings aparecieron cosas serias:

1. **`log.Fatal` al generar una clave**: mataba el proceso de wings entero. Un
   cliente pidiendo un certificado podia tirar el nodo. Ahora devuelve un error
   normal.
2. **Carpetas de certificados creadas sin permisos utiles** (se pasaba
   `os.ModeDir` como si fuera un permiso). Ahora `0755`.
3. **La clave privada se guardaba con permisos `0644`**, legible por cualquier
   usuario de la maquina. Ahora `0600`.
4. **El dominio no se validaba** antes de meterlo en una ruta de archivo y en la
   configuracion de nginx. Ahora pasa por una comprobacion estricta.
5. **Se recargaba nginx sin comprobar la configuracion.** Un dominio mal puesto
   dejaba sin web a todos los clientes del nodo. Ahora se ejecuta `nginx -t` y,
   si algo no cuadra, se deja todo como estaba.
6. **La cuenta de Let's Encrypt se creaba nueva en cada peticion**, asi que se
   acababa chocando con el limite de registros por IP y dejaban de salir
   certificados. Ahora se guarda y se reutiliza.
7. **No existia renovacion.** Los certificados caducaban a los 90 dias y las
   paginas empezaban a dar aviso de sitio no seguro.
8. **Los subdominios se creaban siempre con la nube naranja**, tambien cuando se
   pedia Let's Encrypt, que es justo lo que hace que falle la validacion.
9. La purga de dominios huerfanos llamaba al nodo con la ruta
   `/api/servers/any/proxy/delete`, que **nunca podia funcionar** porque `any`
   no es un servidor.
10. La configuracion de nginx no pasaba **WebSockets** ni las cabeceras
    `X-Real-IP` / `X-Forwarded-Proto`.

---

## Antes de empezar

Necesitas:

- **Panel Pterodactyl 1.11 o mas nuevo** (con o sin tema Arix).
- **nginx instalado en cada nodo.** Es quien sirve los dominios.
- **El cron del panel funcionando**, o los certificados no se renovaran:
  ```
  * * * * * php /var/www/pterodactyl/artisan schedule:run >> /dev/null 2>&1
  ```
- **Los puertos 80 y 443 abiertos en los nodos.** El 80 ademas es obligatorio
  para que Let's Encrypt pueda comprobar los dominios.
- Una cuenta de **Cloudflare** con tus dominios, si quieres que los subdominios
  se creen solos. (Opcional: sin token la extension funciona, pero los registros
  DNS los tendrias que crear tu a mano.)

---

## Instalacion

Son **dos partes**: el panel y cada uno de los nodos. Las dos hacen falta.

### 1. En el panel

```bash
git clone https://github.com/russellxz/pterodactyl-log-extencion.git /opt/pterodactyl-log-extencion
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/install.sh
```

Si tu panel no esta en `/var/www/pterodactyl`, pasa la ruta:

```bash
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/install.sh /ruta/de/tu/panel
```

El instalador copia los archivos, registra la extension, aplica las migraciones,
ajusta permisos y termina con una revision que te dice si falta algo.

> **No borra nada.** Si ya tenias dominios creados con la version antigua,
> aparecen solos en cuanto termina.

### 2. En CADA nodo

Por SSH, **en la maquina donde corre wings** (no en el panel):

```bash
git clone https://github.com/russellxz/pterodactyl-log-extencion.git /opt/pterodactyl-log-extencion
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/wings/install-wings.sh
```

Esto detecta que version de wings tienes, se baja **esa misma version**, le
anade el complemento, lo compila y reinicia el servicio. Antes de tocar nada
guarda una copia del wings actual, asi que volver atras es un comando.

Tarda unos minutos (compila Go). No toca ningun servidor ni ningun archivo de
tus clientes.

#### Si te dice que no puede averiguar la version

```
[!!] No se ha podido averiguar que version de wings tienes.
```

Es lo normal **si ya tenias puesto el complemento antiguo**: ese wings esta
compilado a mano y responde `wings vdevelop`, sin numero. El script se para a
proposito en vez de coger la ultima publicada, porque eso te cambiaria wings de
version sin avisar y una wings mas nueva que tu panel puede dejar de hablarse
con el.

Mira que version del panel tienes (abajo a la derecha en el area de
administracion) y usa la misma serie:

| Panel | wings |
|---|---|
| 1.11.x | `v1.11.13` |
| 1.12.x | `v1.12.3` |
| 1.13.x | `v1.13.2` |

```bash
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/wings/install-wings.sh --version v1.11.13
```

A partir de esta instalacion el problema no se repite: el script **graba el
numero de version en el binario**, asi que la proxima vez lo detecta solo (y el
panel tambien deja de mostrar "develop" en la ficha del nodo).

Si sabes lo que haces y quieres la ultima publicada, `--latest`.

### 3. Comprobar

En el panel: **Admin -> DNS Reverse -> Nodos**. Cada nodo tiene que salir como
*"v2 al dia"*. Si sale en rojo, ahi mismo tienes el comando.

Y desde consola:

```bash
cd /var/www/pterodactyl && php artisan dnsreverse:doctor
```

---

## Actualizar

```bash
cd /opt/pterodactyl-log-extencion && git pull
sudo bash dnsreverse/update.sh
```

(`update.sh` hace el `git pull` el solo, asi que con lanzarlo basta.)

**No se pierde nada**: dominios, tokens, certificados, limites y DNS de clientes
siguen igual. Solo se aplican las migraciones nuevas, que siempre son aditivas.

Si la version trae cambios en el complemento de wings, el panel te lo dira en
**Admin -> DNS Reverse -> Nodos**. Para actualizarlo, en cada nodo:

```bash
cd /opt/pterodactyl-log-extencion && git pull
sudo bash dnsreverse/wings/install-wings.sh
```

---

## Desinstalar

```bash
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/uninstall.sh
```

**Por defecto no borra ningun dato.** Quita los archivos y la linea del
`config/app.php`, y ya esta. Se conservan:

- los DNS de tus clientes (tabla `server_proxy`),
- tus dominios, tokens de Cloudflare y certificados,
- la configuracion,
- todo lo que hay montado en los nodos (los dominios **siguen funcionando**
  mientras la extension no esta puesta: nginx no depende del panel).

Si vuelves a instalar, **reaparece todo tal cual estaba** y ningun cliente tiene
que volver a crear nada.

Si de verdad quieres borrar:

```bash
# borra ademas dominios, tokens, certificados y ajustes
sudo bash dnsreverse/uninstall.sh --borrar-config

# borra ademas los DNS de los clientes (destructivo, piensalo dos veces)
sudo bash dnsreverse/uninstall.sh --borrar-config --borrar-dns
```

Para quitar tambien el complemento de wings de un nodo, restaura la copia que
dejo el instalador:

```bash
ls /usr/local/bin/wings.antes-de-dnsreverse.*
systemctl stop wings
install -m 0755 /usr/local/bin/wings.antes-de-dnsreverse.20260101120000 /usr/local/bin/wings
systemctl start wings
```

---

## Que pasa cuando actualizo el panel

Esto es lo que le pasaba a la version antigua y por lo que seguramente estas
leyendo esto.

El paquete oficial de Pterodactyl **reemplaza `config/app.php`**, que es donde
se registra la extension. No borra `app/Extensions/DnsReverse` (el paquete no
borra carpetas que no son suyas), pero al perderse esa linea la extension
**deja de cargarse**: desaparece del menu y las rutas dejan de responder.

La base de datos **no se toca nunca**: tus dominios y los DNS de tus clientes
siguen exactamente donde estaban.

Para recuperarla, cualquiera de las dos:

```bash
# opcion rapida: solo vuelve a registrarla
cd /var/www/pterodactyl
php app/Extensions/DnsReverse/tools/register-provider.php .

# opcion completa: reinstala y ademas repasa migraciones y permisos
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/install.sh
```

Despues, si quieres asegurarte de que los nodos tienen la configuracion al dia:

```bash
cd /var/www/pterodactyl && php artisan dnsreverse:sync
```

Ese comando vuelve a mandar a cada nodo la configuracion de todos los DNS
guardados. No borra nada y reutiliza los certificados que sigan siendo validos,
asi que **no gasta cupo de Let's Encrypt**.

> Lo mismo vale si reinstalas un nodo desde cero: instalas el complemento de
> wings, ejecutas `dnsreverse:sync` y todos los dominios de ese nodo vuelven a
> montarse solos.

---

## Guia del administrador

Todo esta en **Admin -> DNS Reverse**.

### Resumen

Cuantos DNS hay, que dominios tienes dados de alta y una lista de avisos con lo
que falta por hacer, cada uno con su enlace para arreglarlo.

### Dominios

Aqui esta el cambio grande. **Cada dominio es una ficha con lo suyo**:

- **Token de Cloudflare propio.** Puedes tener `dominiouno.click` en una cuenta
  de Cloudflare y `dominiodos.com` en otra distinta. Se guarda cifrado y no se
  vuelve a mostrar; si lo dejas en blanco al editar, se queda el que habia.
- **Certificado de origen propio.** Lo normal es un comodin `*.tudominio.com`
  generado en Cloudflare (*SSL/TLS -> Origin Server -> Create Certificate*).
  Con eso, todos los subdominios de tus clientes tienen HTTPS sin que ellos
  toquen nada.
- **Nube de Cloudflare**: automatica (recomendado), siempre naranja o siempre
  gris. En automatica se pone naranja con certificado de origen y gris con
  Let's Encrypt, que es lo correcto en cada caso.
- **Que permite**: subdominios, SRV de Minecraft, Let's Encrypt.
- **Nombres reservados**: `www`, `panel`, `admin`... nadie podra pedirlos.
- **Activo**: si lo desmarcas, nadie crea subdominios nuevos de ese dominio,
  pero **lo ya creado sigue funcionando**.

Hay un boton **Probar conexion** que pregunta a Cloudflare si el token vale y si
encuentra la zona, para que te enteres tu antes de que se lo lleve un cliente.

### DNS de clientes

El listado de todo lo que han creado. Por cada uno: el dominio (**pinchable**,
se abre en una pestana nueva para comprobar que responde), el tipo, el servidor,
el cliente, el nodo y el destino, el certificado y la fecha.

Con buscador y filtros (por tipo, por dominio, solo huerfanos), y tres acciones:

- **Resincronizar** uno: vuelve a mandar su configuracion al nodo.
- **Borrar** uno: lo quita de Cloudflare, del nodo y del panel.
- **Purgar seleccionados**: varios a la vez, pensado para los *huerfanos*
  (DNS cuyo servidor ya no existe en el panel).
- **Resincronizar todo**: lo que hay que pulsar despues de actualizar el panel o
  de reinstalar un nodo.

### Limites

Cuantos DNS puede tener cada servidor.

- Poner **0** bloquea a ese cliente: no puede crear mas. **Los que ya tiene
  siguen funcionando** (si quieres quitarselos de verdad, borralos desde *DNS de
  clientes*).
- Hay un boton **0** en cada fila para bloquear de un clic.
- Y un cambio **en bloque**, con la opcion de tocar solo los que estan a 0, que
  es lo que interesa justo despues de instalar.

### Tipos de servidor

Que puede crear cada *egg*: normal (web), solo SRV de Minecraft, ambos o
desactivado. El cliente solo vera las opciones que tengan sentido para su
servidor.

### Nodos

Estado del complemento de wings nodo por nodo: si responde, que version tiene,
si nginx esta bien y cuantos certificados guarda. Con el comando exacto para
instalarlo o actualizarlo, y un boton para renovar certificados a mano.

### Registro

Quien creo o borro cada DNS y cuando. Sirve para saber a quien preguntar cuando
un dominio da guerra.

### Configuracion

- Limite con el que nacen los servidores nuevos (**1** por defecto).
- Si se permiten dominios propios de los clientes.
- Certificados automaticos: activarlos, renovacion automatica y con cuantos dias
  de margen.
- El texto que ve el cliente cuando trae su dominio (con `[ip]` se sustituye
  solo por la direccion de su servidor).
- Dominios prohibidos. **Ademas de los que pongas**, la extension bloquea
  siempre el dominio del panel y el FQDN de todos los nodos, para que nadie
  pueda secuestrarlos por accidente.

---

## Guia del cliente

El cliente entra en su servidor y pulsa **DNS Reverse** en la barra de arriba.

La pantalla trae una ayuda desplegable escrita en cristiano, y en el formulario
solo le salen las opciones que puede usar de verdad.

**Puede elegir entre:**

1. **Subdominio nuestro** — escribe un nombre, elige el dominio de la lista y
   listo. No tiene que comprar nada ni configurar nada.
2. **Su propio dominio** — el que ya compro. La pantalla le dice exactamente que
   registro tiene que crear y a que IP apuntarlo.
3. **Minecraft SRV** — para entrar al servidor sin escribir el puerto. Se crea
   el registro SRV y tambien uno normal, asi que la misma direccion sirve para
   el juego y para una web (mapa dinamico, por ejemplo).

Despues elige el certificado, ve cuantos DNS le quedan, y puede borrar los suyos
cuando quiera.

---

## Certificados: cual elegir

Esta es la parte que mas confusion crea, asi que va explicada del tiron.

### Certificado de origen (Cloudflare)

Lo genera Cloudflare en *SSL/TLS -> Origin Server*. **Dura 15 anos** y solo lo
acepta Cloudflare, asi que **el trafico tiene que pasar por Cloudflare**: el
registro va con la **nube naranja**.

- Ventaja: no caduca en la practica y no hay que validar nada.
- Requisito: nube naranja obligatoria.
- Ideal para: tus dominios de la casa. Pones un comodin `*.tudominio.com` una
  vez en la ficha del dominio y todos tus clientes lo aprovechan sin ver ni
  tocar la clave privada.

### Certificado automatico (Let's Encrypt)

Lo pide el nodo solo. **Dura 90 dias** y esta extension lo renueva sola.

- Ventaja: no hay que generar ni pegar nada.
- Requisito: **nube gris** (opcion *DNS only* en Cloudflare) y puerto 80
  accesible desde internet. La validacion tiene que llegar al nodo; con la nube
  naranja de por medio suele fallar.
- Ideal para: dominios propios del cliente que no estan en tu Cloudflare.

### La regla facil

> **Nube gris = Let's Encrypt. Nube naranja = certificado de origen.**

Mezclarlos es lo que provoca el famoso **error 526** de Cloudflare (Cloudflare
llega al origen pero el certificado no le vale).

### Como se renuevan

Los de Let's Encrypt se renuevan solos. El panel repasa todos los nodos cada
madrugada y renueva los que caducan en menos de 21 dias (configurable). Para eso
**el cron del panel tiene que estar puesto**.

A mano, cuando quieras:

```bash
cd /var/www/pterodactyl && php artisan dnsreverse:renew
```

Los de origen no se renuevan porque duran 15 anos.

---

## Venir de la version antigua

Se puede instalar **con la version antigua todavia puesta**: las dos conviven
sin pisarse, porque esta no toca ningun archivo del panel y usa rutas propias
(`/admin/dnsreverse` y `/api/dnsreverse`).

Al instalar:

- Se reutiliza la **misma tabla** `server_proxy`, asi que **todos los dominios
  de tus clientes aparecen tal cual**, sin migrar nada.
- El token de Cloudflare y el certificado que tenias en la configuracion
  antigua se **copian** a una ficha por cada dominio de tu lista. Las claves
  `proxy::*` de la version antigua **no se borran**, por si quieres volver.
- La columna `proxy_limit` de los servidores se conserva; solo cambia el valor
  con el que nacen los nuevos (pasa de 0 a 1).

Despues de instalar, dos cosas recomendables:

```bash
# 1. Subir de golpe a todos los que estaban bloqueados a 0
cd /var/www/pterodactyl && php artisan dnsreverse:install --unlock-all

# 2. Confirmar que los nodos tienen la configuracion al dia
php artisan dnsreverse:sync
```

Cuando ya lo veas funcionando, puedes dejar de usar la pantalla antigua. No hace
falta desmontarla: cuando actualices el panel se ira sola.

---

## Comandos

Todos se ejecutan desde la carpeta del panel (`cd /var/www/pterodactyl`).

| Comando | Para que |
|---|---|
| `php artisan dnsreverse:install` | Prepara la base de datos. Se puede repetir sin miedo. |
| `php artisan dnsreverse:install --unlock-all` | Ademas sube el limite a todos los servidores que estan a 0. |
| `php artisan dnsreverse:install --limit=3` | Fija con cuantos DNS nacen los servidores nuevos. |
| `php artisan dnsreverse:doctor` | Revision completa: que esta bien, que esta mal y como se arregla. |
| `php artisan dnsreverse:doctor --cloudflare` | Ademas prueba de verdad cada token de Cloudflare. |
| `php artisan dnsreverse:sync` | Vuelve a mandar a los nodos la configuracion de todos los DNS. |
| `php artisan dnsreverse:sync --node=1` | Solo los de un nodo. |
| `php artisan dnsreverse:sync --dry-run` | Solo lista lo que haria, sin tocar los nodos. |
| `php artisan dnsreverse:renew` | Renueva ya los certificados que caducan pronto. |
| `php artisan dnsreverse:renew --days=45 --force` | Renueva con mas margen, aunque la automatica este apagada. |
| `php artisan dnsreverse:uninstall` | Informa de lo que hay guardado. Por defecto **no borra nada**. |
| `php artisan dnsreverse:uninstall --borrar-config` | Borra dominios, tokens, certificados y ajustes. |

Y los scripts, desde la carpeta del repositorio:

| Script | Donde | Para que |
|---|---|---|
| `sudo bash dnsreverse/install.sh` | panel | Instalar o reinstalar |
| `sudo bash dnsreverse/update.sh` | panel | Actualizar |
| `sudo bash dnsreverse/uninstall.sh` | panel | Desinstalar sin perder datos |
| `sudo bash dnsreverse/permissions.sh` | panel | Repasar permisos |
| `sudo bash dnsreverse/wings/install-wings.sh` | **nodo** | Instalar o actualizar el complemento de wings |

---

## Si algo va mal

Lo primero, siempre:

```bash
cd /var/www/pterodactyl && php artisan dnsreverse:doctor
```

### "DNS Reverse" no sale en el menu del admin

Se perdio el registro (tipico despues de actualizar el panel o el tema):

```bash
cd /var/www/pterodactyl
php app/Extensions/DnsReverse/tools/register-provider.php .
php artisan route:clear && php artisan view:clear && php artisan config:clear
```

Si sigue sin salir, recarga la pagina con Ctrl+F5: el menu lo dibuja un
JavaScript que el navegador puede tener cacheado.

### El cliente no ve la entrada DNS Reverse

Primero: la entrada sale **dentro de un servidor**, en la barra de secciones
(Consola, Archivos, Bases de datos...). En la lista de servidores no aparece,
porque ahi todavia no se sabe de que servidor se trata.

Si dentro de un servidor tampoco sale:

1. **Ctrl+F5** para tirar la cache del navegador.
2. Comprueba que el archivo llega: abre
   `https://TU-PANEL/extensions/dnsreverse/client.js`. Tiene que salir codigo,
   no un error 404. Si da 404, vuelve a lanzar `install.sh`.
3. Comprueba que el limite de ese servidor no esta a **0**
   (*Admin -> DNS Reverse -> Limites*).
4. Comprueba que su tipo de servidor no esta en *Desactivado*
   (*Admin -> DNS Reverse -> Tipos de servidor*).
5. Abre la consola del navegador (**F12 -> Consola**). La extension deja escrito
   ahi que ha hecho:
   - `entrada anadida a la barra del servidor` &rarr; esta puesta; si no la ves,
     mira mas a la derecha en la barra (se puede desplazar).
   - `todavia no se encuentra la barra del servidor, reintentando` &rarr; el tema
     dibuja el menu de otra forma. Sigue intentandolo cada 700 ms, asi que si
     el mensaje se repite sin parar es que no lo reconoce.
   - Si no aparece **ningun** mensaje, el archivo no se esta cargando: repasa el
     punto 2.

Mientras tanto, la pantalla siempre se puede abrir por la direccion directa:
`https://TU-PANEL/server/ID-DEL-SERVIDOR/dnsreverse`

Si el tema tiene un segundo menu para movil con los mismos enlaces, la
extension evita meter ahi la entrada: comprueba que se vea de verdad y, si no,
busca otra barra en el siguiente repaso.

### "El nodo no tiene instalado el complemento de wings"

Ejecuta en **ese nodo**:

```bash
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/wings/install-wings.sh
```

### Let's Encrypt falla al crear el certificado

Por orden de probabilidad:

1. **El dominio esta con la nube naranja.** Ponlo en gris (*DNS only*), crea el
   DNS y, si quieres, vuelve a ponerlo naranja despues (aunque entonces te
   interesa mas el certificado de origen).
2. **El dominio todavia no apunta al nodo.** Los cambios de DNS tardan un rato;
   espera unos minutos y reintenta.
3. **El puerto 80 del nodo esta cerrado.** La validacion entra por ahi.
4. **Has pedido muchos certificados del mismo dominio esta semana.** Let's
   Encrypt limita a 5 certificados iguales por semana. Espera o usa el
   certificado de origen.

### Error 526 de Cloudflare

El certificado del origen no le vale a Cloudflare. Casi siempre es la mezcla:
Let's Encrypt con la nube naranja. O pones la nube gris, o cambias a certificado
de origen.

### Un dominio no carga y el resto si

Entra al nodo y mira:

```bash
nginx -t
ls -l /etc/nginx/sites-enabled/ | grep tudominio
```

Si la configuracion no esta, resincroniza desde el panel (*DNS de clientes ->
Resincronizar*) o con `php artisan dnsreverse:sync`.

### Despues de reinstalar un nodo, sus dominios no responden

Normal: el nodo esta vacio. Instala el complemento de wings y luego:

```bash
cd /var/www/pterodactyl && php artisan dnsreverse:sync --node=ID_DEL_NODO
```

### Los certificados caducaron

Se apago el cron del panel o la renovacion automatica. Comprueba las dos y
lanza:

```bash
cd /var/www/pterodactyl && php artisan dnsreverse:renew --force
```

---

## Como esta hecho (por si lo tienes que tocar)

```
dnsreverse/
├── install.sh / update.sh / uninstall.sh / permissions.sh
├── panel/
│   ├── app/Extensions/DnsReverse/     <- todo el codigo, en su propia carpeta
│   └── public/extensions/dnsreverse/  <- css y js que se inyectan
└── wings/
    ├── router_dns_reverse.go          <- el complemento del nodo
    └── install-wings.sh
```

Reglas que sigue el codigo:

- **No se sobrescribe ningun archivo del panel ni del tema.** El menu del admin
  y la pantalla del cliente se inyectan desde JavaScript, no editando plantillas.
- **No se recompila React.** Nada de `yarn build`, que es lo que suele dejar el
  panel en blanco.
- **Los iconos son SVG escritos a mano**, no clases de Font Awesome: Arix no
  carga Font Awesome y saldrian huecos vacios.
- **Las migraciones solo anaden.** Ninguna borra tablas ni columnas, y el
  `down()` de las que tocan datos de clientes esta vacio a proposito.
- **Si la extension falla, el panel sigue vivo**: todo el arranque va dentro de
  un `try/catch`.
