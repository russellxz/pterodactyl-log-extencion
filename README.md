# Logs Pterodactyl

Extension para el panel de **Pterodactyl** compatible con el tema **Arix**.

Reune en el area de administracion lo que hace falta para llevar un hosting
sin sustos: los errores del panel, las instalaciones que se quedan colgadas,
los correos que salen (y los que no), el envio de correos a los clientes, el
consumo real de cada servidor con nombre y correo de su dueno, y la
actualizacion del panel con respaldo y vuelta atras.

> **Novedades de la 1.1.0.** Al parar una instalacion colgada ahora se cambia
> **primero el estado de la instalacion** (el servidor se marca como
> *instalado*, igual que con el boton **"Toggle Install Status"** del admin) y
> **despues el puerto**. Ese era el paso que habia que dar a mano para que el
> panel dejara de decir *Running Installer*; ya no hace falta. Ademas el
> servidor queda vigilado unas horas por si el nodo avisa tarde y lo vuelve a
> bloquear. Se actualiza con `sudo bash update.sh`.

---

## Indice

- [Por que no rompe el tema Arix](#por-que-no-rompe-el-tema-arix)
- [Instalacion](#instalacion)
- [Actualizar sin desinstalar](#actualizar-sin-desinstalar)
- [Permisos](#permisos)
- [Desinstalacion](#desinstalacion)
- [Que trae](#que-trae)
- [Comandos](#comandos)
- [Si algo va mal](#si-algo-va-mal)

---

## Por que no rompe el tema Arix

Cuando un tema como Arix deja el panel en blanco despues de instalar un addon,
casi siempre es por lo mismo: el addon toca `resources/scripts` y obliga a
ejecutar `yarn build:production`, lo que sobrescribe los assets compilados del
tema. O reemplaza archivos que el tema tambien reemplaza (`routes/admin.php`,
`resources/views/layouts/admin.blade.php`, modelos, controladores...).

Esta extension no hace nada de eso:

| | Logs Pterodactyl |
|---|---|
| Recompila el frontend de React (`yarn build`) | **No**, nunca |
| Reemplaza archivos del panel | **No**, ninguno |
| Reemplaza archivos del tema | **No**, ninguno |
| Toca `routes/admin.php` o `routes/base.php` | **No** |
| Toca `resources/views/layouts/admin.blade.php` | **No** |
| Toca `app/Console/Kernel.php` | **No** |

Todo el codigo vive en dos carpetas nuevas que no existian:

```
app/Extensions/LogsPterodactyl/          <- todo el codigo
public/extensions/logspterodactyl/       <- css, js y logos
```

La unica linea que se escribe fuera de ahi es el registro del proveedor en
`config/app.php`, que el instalador anade entre marcas y el desinstalador
quita dejando el archivo **byte a byte** como estaba.

Las pantallas del administrador extienden `layouts.admin`, asi que heredan el
aspecto del tema que tengas: con Arix se ven como Arix, y sin Arix se ven como
el panel de siempre. El aviso del cliente y el enlace del menu lateral se
inyectan en la respuesta HTML ya generada, desde un middleware, por eso
sobreviven a las actualizaciones del tema.

---

## Instalacion

```bash
git clone https://github.com/russellxz/pterodactyl-log-extencion.git
cd pterodactyl-log-extencion
sudo bash install.sh
```

Si tu panel no esta en `/var/www/pterodactyl`, indica la ruta:

```bash
sudo bash install.sh /ruta/de/tu/panel
```

El instalador copia los archivos, registra la extension, crea las tablas,
**ajusta todos los permisos** y termina con un diagnostico. Se puede volver a
ejecutar tantas veces como quieras: no duplica nada ni pisa tu configuracion.

Cuando termine, entra en:

```
https://TU-PANEL/admin/logspterodactyl
```

Tambien aparece **LogsPterodactyl** en el menu lateral del area de
administracion.

### El cron es obligatorio

El corte automatico de instalaciones y el consumo de recursos van con el cron
del panel. Si no lo tienes puesto, nada de eso funcionara:

```bash
(crontab -l 2>/dev/null; echo "* * * * * php /var/www/pterodactyl/artisan schedule:run >> /dev/null 2>&1") | crontab -
```

### ¿Venias de la version anterior (ArixLog)?

No hace falta desinstalar nada. Ejecuta el instalador y ya esta: retira los
archivos viejos, quita su linea de `config/app.php` y **renombra las tablas
conservando todo** (historial de instalaciones, correos, consumo y tu
configuracion). La carpeta de respaldos pasa de `/var/backups/arixlog` a
`/var/backups/logspterodactyl`; la vieja se queda como esta por si acaso.

---

## Actualizar sin desinstalar

```bash
cd pterodactyl-log-extencion
sudo bash update.sh
```

Baja los ultimos cambios con `git pull`, los aplica, ejecuta las migraciones
nuevas y **vuelve a poner todos los permisos**. No se pierde ningun dato ni
ninguna configuracion.

Si tu panel esta en otra ruta:

```bash
sudo bash update.sh /ruta/de/tu/panel
```

---

## Permisos

La mayoria de los errores raros de un panel de Pterodactyl son permisos. El
instalador y `update.sh` ya los ponen, pero si quieres lanzarlo suelto:

```bash
cd pterodactyl-log-extencion
sudo bash permissions.sh
```

Que hace exactamente:

| Carpeta | Que se le pone | Para que |
|---|---|---|
| `app/Extensions/LogsPterodactyl` | dueno del servidor web, `u=rwX,g=rX,o=rX` | leer el codigo |
| `public/extensions/logspterodactyl` | dueno del servidor web, `755` | servir css y js |
| `public/extensions/logspterodactyl/runs` | escribible | progreso del actualizador |
| `public/extensions/logspterodactyl/logos` | escribible | logo de los correos |
| `storage` y `bootstrap/cache` | dueno del servidor web, `755` | receta oficial de Pterodactyl |
| `/var/backups/logspterodactyl` | dueno del servidor web, `700` | respaldos antes de actualizar |

Ademas comprueba de verdad, escribiendo como el usuario del servidor web, que
cada carpeta es accesible, y avisa si falta el cron.

A mano seria esto:

```bash
sudo chown -R www-data:www-data /var/www/pterodactyl/app/Extensions/LogsPterodactyl
sudo chown -R www-data:www-data /var/www/pterodactyl/public/extensions/logspterodactyl
sudo chown -R www-data:www-data /var/www/pterodactyl/storage /var/www/pterodactyl/bootstrap/cache
sudo chmod -R 755 /var/www/pterodactyl/storage /var/www/pterodactyl/bootstrap/cache
sudo mkdir -p /var/backups/logspterodactyl
sudo chown -R www-data:www-data /var/backups/logspterodactyl
sudo chmod 700 /var/backups/logspterodactyl
```

(Si tu servidor web corre como `nginx` o `apache` en vez de `www-data`, cambia
el usuario. `permissions.sh` lo detecta solo.)

---

## Desinstalacion

```bash
cd pterodactyl-log-extencion
sudo bash uninstall.sh
```

Quita los archivos, la linea de `config/app.php` y las tablas. El panel queda
exactamente como estaba.

Conservando el historial por si reinstalas:

```bash
sudo bash uninstall.sh --keep-data
```

Sin preguntas (para scripts):

```bash
sudo bash uninstall.sh /var/www/pterodactyl --force
```

---

## Que trae

### 1. Errores del panel

Visor de `storage/logs` con las entradas troceadas, no un volcado de texto.

- Filtro por nivel y busqueda dentro del mensaje **y de la traza**.
- Muestra la clase de la excepcion y el **primer archivo tuyo** de la traza,
  saltandose `vendor/`. Eso es lo que sirve para saber donde mirar.
- Traza desplegable, contadores por nivel, descarga, vaciado y borrado.

Los logs de un panel con trafico pesan cientos de megas, asi que nunca se
cargan enteros: se lee solo la cola y se recorre desde la entrada mas nueva
parando al llenar el limite. Un archivo de 18 MB se resuelve en unos 200 ms.

### 2. Instalaciones colgadas

El problema de los servidores que se quedan tres dias "instalando".

**Sistema automatico** (activado por defecto, a los 10 minutos): cada minuto
revisa si hay instalaciones que pasen del tiempo configurado. Cuando una lo
pasa:

1. **Cambia el estado de la instalacion**: marca el servidor como *instalado*.
   Es exactamente lo que hace el boton rosa **"Toggle Install Status"** de la
   pestana *Manage* del admin, el que habia que pulsar a mano.
2. Cambia el puerto a otro libre del mismo nodo, intentando mantener la IP.
3. Avisa al dueno por correo diciendole que revise el token, el usuario y si
   el repositorio es privado.
4. Lo anota en el historial con cliente, nodo, egg, duracion y los dos puertos.

**Aviso al cliente**: cuando su servidor pasa de los minutos configurados, le
sale una tarjeta con iconos (sin emojis) recordandole revisar el token, el
nombre de usuario, si el repositorio es privado y si la version existe, con un
boton para **parar la instalacion**. Una vez parada, la tarjeta le dice cual es
su nueva direccion y que reinstale desde *Ajustes* cuando lo tenga corregido.

**Control manual**: desde el admin puedes parar cualquier instalacion en curso
al momento, con o sin cambio de puerto.

#### Que hace exactamente al parar

**Primero el estado, despues el puerto.** El orden importa:

1. **Se cambia el estado de la instalacion**: el servidor pasa a estar
   *instalado*. Es lo mismo que hace el boton rosa **"Toggle Install Status"**
   del admin, y es lo unico que quita de en medio la pantalla *Running
   Installer* y le devuelve al cliente el acceso a la consola, a los archivos y
   a la configuracion de arranque.
2. **Se le cambia el puerto** a otro libre del mismo nodo.
3. Se le pasa la configuracion nueva al nodo, se avisa al dueno por correo y
   queda todo registrado.

Por que en ese orden y no al reves: mientras el panel siga creyendo que el
servidor esta instalando (o que la instalacion fallo), el cliente no puede
entrar a ver nada, por mucho puerto nuevo que se le ponga. De hecho la
aplicacion del panel ensena la **misma** pantalla de *Running Installer* para
los tres estados `installing`, `install_failed` y `reinstall_failed`, asi que
marcar el servidor como "instalacion fallida" no desbloquea nada: el unico
estado que sirve es *instalado*.

Y lo que **no** hace, a proposito: **no borra nada**. Ni el servidor, ni sus
archivos, ni en el panel ni en el nodo. El servidor se queda donde esta, con su
puerto nuevo, para que el cliente revise sus datos de arranque y lo reinstale el
mismo con el boton de siempre del panel (*Ajustes -> Reinstalar servidor*).

Un apunte honesto sobre el contenedor: wings no expone ninguna orden para
cancelar una instalacion en marcha (su API solo tiene `power`, `commands`,
`install`, `reinstall`, `sync` y `delete`, y `power` se rechaza mientras
instala). La unica forma de matar el contenedor seria borrar el servidor en el
nodo, y eso borraria tambien sus archivos, asi que no se hace. El contenedor
colgado termina por su cuenta; para entonces el cliente lleva rato pudiendo
trabajar, que es lo que importa.

#### La vigilancia de desbloqueo

Hay un detalle que hacia que el arreglo manual tampoco durase: cuando el
contenedor colgado muere por fin en el nodo (puede tardar horas), wings avisa al
panel de que **aquella** instalacion fallo y el panel vuelve a marcar el
servidor como `install_failed`. El cliente, que llevaba rato trabajando tan
tranquilo, se lo encuentra otra vez con *Running Installer* sin haber tocado
nada.

Por eso, despues de parar una instalacion, la extension deja el servidor
**vigilado** durante un tiempo (3 horas por defecto, configurable en
*Configuracion*, y con `0` se apaga). Si durante ese rato alguien vuelve a
bloquearlo, lo devuelve a *instalado* al momento y lo anota en el historial.

La vigilancia no estorba nunca a una instalacion de verdad: en cuanto el cliente
pulsa *Reinstalar servidor*, se retira sola. Si esa instalacion nueva falla, el
cliente ve el fallo como siempre; eso no se tapa.

| Modo | Que hace |
|---|---|
| **Parar y cambiar puerto** (por defecto) | Marca el servidor como instalado y lo mueve a otro puerto. |
| Solo parar | Marca el servidor como instalado sin tocar el puerto. |

El sistema automatico, el boton del cliente y el boton del admin hacen
exactamente lo mismo.

### 3. Seguimiento de los reintentos

Cada instalacion lleva su **numero de intento** y queda enlazada con la
anterior del mismo servidor. Con eso, la pantalla de instalaciones tiene un
apartado que responde a la pregunta que importa: de las que el sistema paro y
cambio de puerto, **¿cuantas acabaron instalandose bien despues?**

Para cada una se ve el servidor, el cliente, cuanto tardo antes de que se
cortara, si se forzo, el cambio de puerto y el desenlace: se instalo bien,
volvio a fallar, se esta reinstalando ahora o aun no lo ha reintentado.

### 4. Consumo de recursos en tiempo real

- Tabla en vivo con CPU, RAM, disco y red de cada servidor, **con el nombre y
  el correo de su cliente** al lado.
- Filtro por nodo y busqueda por servidor, cliente o correo.
- En rojo los que pasan de los umbrales que configures.
- **Ranking historico** (6 h, 24 h, 7 dias, 30 dias) con media y maximo de CPU
  y RAM. Esto es lo que sirve para detectar abusos, no un pico puntual.

Se consulta a cada nodo de una sola vez (`GET /api/servers` de wings) en lugar
de servidor a servidor. La respuesta de wings incluye la configuracion completa
de cada servidor con sus variables de entorno (tokens, contrasenas de bases de
datos...): **se descarta entera**, solo se toman identificador, estado y
consumo. Nunca se guarda ni se muestra.

### 5. Correos

**Registro**: todo lo que sale del panel queda anotado (a que cliente, asunto,
cuando, y si salio o fallo), con boton para **reenviar**, vista previa aislada
y un envio de prueba para comprobar la configuracion SMTP.

Los enlaces con credenciales de un solo uso (los de restablecer contrasena) se
guardan **censurados**: el cliente recibe su correo intacto, pero la copia del
registro no deja un token de acceso a la vista.

**Enviar correos** (pestana "Enviar correo"):

- A **todos** los clientes, a **una direccion** suelta o a los que **elijas**
  con un buscador por nombre, usuario o correo.
- Contenido en **HTML** con vista previa de como va a quedar.
- **Logo propio**: subes una imagen (JPG, PNG, GIF o WEBP, hasta 2 MB) y sale
  en la cabecera de todos los correos.
- Marcadores que se sustituyen en cada envio: `{{nombre}}`, `{{correo}}` y
  `{{panel}}`.
- **Colores y estilos**: puedes usar cualquier HTML con estilos en linea
  (`<p style="color:#e63946">`). Se respetan tal cual.
- **Plantilla a tu gusto**, con tres opciones:
  - *El marco de serie*: cabecera con tu logo y pie automatico.
  - *Mi propia plantilla*: escribes el HTML completo del correo y pones
    `{{contenido}}` donde quieras que entre el mensaje. Hay un boton para
    cargar la de serie y editarla en vez de empezar de cero. Ahi tambien valen
    `{{logo}}`, `{{panel}}`, `{{url}}`, `{{nombre}}` y `{{correo}}`.
  - *Sin marco*: se envia exactamente el HTML que escribas, sin nada alrededor.

  Aviso practico: en los correos los estilos van **en linea** y la maquetacion
  con `<table>`. Gmail y Outlook ignoran las hojas de estilo y no entienden
  flexbox ni grid.
- Se manda **uno por uno**, asi ningun cliente ve la direccion de los demas.
- Cada envio queda agrupado como campana: cuantos salieron y cuantos fallaron.

### 6. Actualizar el panel

Comprueba la ultima version publicada de Pterodactyl y la instala **sin perder
el tema Arix**: respaldo completo (archivos + base de datos), descarga y
comprobacion del paquete oficial, `composer install`, migraciones,
restauracion del tema desde su carpeta `arix/<version>`, recompilado de sus
assets y salida del mantenimiento.

Mientras dura, la pantalla muestra el progreso paso a paso leyendolo de un
archivo estatico que sirve el servidor web sin pasar por PHP, asi que **se
sigue viendo aunque el panel este en mantenimiento**.

Tambien desde consola, que es lo mas fiable porque corre como root:

```bash
cd /var/www/pterodactyl
sudo php artisan logspterodactyl:panel-update
```

#### Deshacer la actualizacion

```bash
sudo php artisan logspterodactyl:panel-rollback --list   # ver las disponibles
sudo php artisan logspterodactyl:panel-rollback          # deshacer la ultima
sudo php artisan logspterodactyl:panel-rollback 3        # una concreta
```

O con el boton **Deshacer** del actualizador. Restaura archivos y base de
datos. Los archivos que la version nueva anadio se quedan en el disco, pero el
panel restaurado no los usa.

#### Leelo antes de actualizar con Arix puesto

Arix **no es solo una capa de estilos**: reemplaza modelos, controladores,
transformadores y rutas del panel por los suyos, hechos contra una version
concreta de Pterodactyl (Arix 2.1.2 esta hecho para el panel 1.14.1).

Al restaurar el tema despues de actualizar, esos archivos se vuelven a poner
encima del panel nuevo. Si Pterodactyl ha cambiado alguna de esas clases entre
las dos versiones, el panel puede fallar.

**Lo seguro es actualizar primero el tema.** La extension detecta esta
situacion y te avisa antes de dejarte continuar. Y si aun asi sale mal, para
eso esta el respaldo.

Otro detalle: el tema trae su propio `config/app.php` con la version del panel
escrita a mano, asi que al restaurarlo el panel diria que sigue en la version
vieja. La extension corrige esa linea y deja el resto intacto.

---

## Comandos

| Comando | Para que |
|---|---|
| `php artisan logspterodactyl:doctor` | Comprueba que todo esta en su sitio. **Lo primero si algo falla.** |
| `php artisan logspterodactyl:install` | Crea las tablas y la configuracion inicial. |
| `php artisan logspterodactyl:uninstall` | Borra las tablas. |
| `php artisan logspterodactyl:watch-installs` | Revisa las instalaciones colgadas (lo llama el cron). |
| `php artisan logspterodactyl:watch-installs --dry-run` | Enseña que haria, sin tocar nada. |
| `php artisan logspterodactyl:sample` | Toma una muestra del consumo (lo llama el cron). |
| `php artisan logspterodactyl:prune` | Limpia los registros antiguos (lo llama el cron). |
| `php artisan logspterodactyl:panel-update` | Actualiza el panel. |
| `php artisan logspterodactyl:panel-rollback` | Deshace una actualizacion. |

Y los guiones del repositorio:

| Guion | Para que |
|---|---|
| `sudo bash install.sh` | Instalar (o reinstalar encima). |
| `sudo bash update.sh` | Actualizar sin desinstalar, con permisos incluidos. |
| `sudo bash permissions.sh` | Solo arreglar permisos. |
| `sudo bash uninstall.sh` | Desinstalar del todo. |

---

## Si algo va mal

**Lo primero, siempre:**

```bash
cd /var/www/pterodactyl && php artisan logspterodactyl:doctor
```

Te dice exactamente que falta y como arreglarlo.

### Errores de "Permission denied" en el log

Sale algo como `file_put_contents(.../storage/framework/cache/...): Failed to
open stream: Permission denied`. No es de la extension: es el panel, que no
puede escribir en su propia carpeta `storage`. La extension lo unico que hace
es ensenartelo.

```bash
sudo bash permissions.sh
```

### "Carpeta de respaldos" en rojo en el actualizador

Lo mismo: `/var/backups/logspterodactyl` no existe o no la puede escribir el
usuario del servidor web.

```bash
sudo bash permissions.sh
```

### Despues de actualizar Arix o el panel, la extension desaparece

Es normal: los dos reescriben `config/app.php` y se llevan por delante el
registro. Se arregla con:

```bash
cd pterodactyl-log-extencion && sudo bash update.sh
```

### El boton de parar dice que si pero el panel sigue igual

Lo que hace parar es marcar el servidor como *instalado* y cambiarle el puerto.
Si tras pulsarlo sigues viendo la pantalla de instalacion, es que el navegador
tiene el estado viejo en memoria: recarga con Ctrl+F5. La extension recarga
sola, pero si la pagina estaba abierta de antes puede tardar un ciclo.

Comprueba en **Instalaciones** que ese servidor ya no sale en "instalando ahora
mismo", y en **Registro** que la parada quedo anotada. Si la orden al nodo
fallo, el motivo aparece ahi (normalmente el panel no llega a wings).

### El panel sigue diciendo "Running Installer" despues de detenerla

Desde la version 1.1.0 esto no deberia pasar: al parar, la extension marca el
servidor como *instalado* (lo mismo que el boton **"Toggle Install Status"** de
la pestana *Manage*), que es el unico estado que desbloquea la pantalla del
cliente.

Si aun asi la ves, casi siempre es el navegador: la aplicacion del panel guarda
el estado del servidor en memoria y no lo vuelve a pedir sola. La extension lo
detecta y recarga la pagina automaticamente, pero si la tenias abierta de antes
puede que veas la pantalla vieja un momento. Recarga con Ctrl+F5 (o tirando
hacia abajo en el movil).

Si vuelve a salir **horas despues** sin que nadie haya tocado nada, es el nodo
avisando tarde de aquella instalacion colgada. De eso se encarga la vigilancia
de desbloqueo: comprueba en *Configuracion* que "Mantener el servidor
desbloqueado durante" no este a `0` y que el cron del panel este corriendo. En
**Registro** aparece cada vez que la extension ha tenido que desbloquearlo de
nuevo.

Para saber que dice de verdad la base de datos, entra en **Instalaciones**: si
no hay ninguna en curso, ahi mismo aparece el recuento de servidores por estado.
Un servidor desbloqueado sale como `(instalado / sin estado)`.

### El corte automatico no salta

Comprueba que esta activado en Configuracion y que el cron del panel corre.
`logspterodactyl:doctor` avisa si no hay muestras recientes.

### No aparece el aviso al cliente

Tiene que estar activado en Configuracion, el servidor tiene que llevar mas
minutos de los configurados, y quien mira tiene que ser el **dueno**.

### "Instalaciones" no detecta ninguna instalacion en curso

En esa pantalla, cuando la lista sale vacia, se muestra el recuento de
servidores por estado leido directamente de la base de datos. Si ahi no hay
ninguno en `installing`, es que la instalacion ya termino (bien o mal) y lo que
ves en el navegador es la pantalla sin refrescar.

Si sale un error en rojo, ese es el motivo real y aparece tambien en la pestana
**Registro**.

### La tabla de consumo dice que el nodo no responde

Es la conexion del panel con wings, no la extension: comprueba que wings esta
arriba y que el panel llega a el.

### El panel se ha quedado en blanco

No deberia pasar por esta extension (no toca el frontend), pero si quieres
descartarla del todo:

```bash
sudo bash uninstall.sh
```

---

## Requisitos

- Pterodactyl 1.11 o superior (probado contra 1.14 y 1.15).
- PHP 8.1 o superior.
- El cron del panel funcionando.
- Para actualizar el panel: `curl`, `tar`, `composer` y, si tienes Arix,
  `yarn` y `node` para recompilar sus assets.

Funciona con o sin el tema Arix.

---

## Privacidad y seguridad

- El area de administracion comprueba **dos veces** que quien entra es
  administrador: el middleware del panel y otro propio.
- El cliente solo puede detener o reinstalar **sus** servidores.
- El visor de logs no deja salirse de `storage/logs`.
- Las variables de entorno que devuelve wings se descartan enteras.
- Los tokens de un solo uso de los correos se guardan censurados.
- La contrasena de la base de datos no pasa por la linea de comandos al hacer
  el respaldo: va en un archivo con permisos `600`, para que no aparezca en la
  lista de procesos.
- La carpeta de respaldos no puede estar dentro del panel ni de su carpeta
  publica: si lo intentas, se rechaza.

---

## Licencia

MIT.
