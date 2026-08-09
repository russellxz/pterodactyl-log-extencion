# Logs Pterodactyl

Extension para el panel de **Pterodactyl** compatible con el tema **Arix**.

Reune en el area de administracion lo que hace falta para llevar un hosting
sin sustos: los errores del panel, las instalaciones que se quedan colgadas,
los correos que salen (y los que no), el envio de correos a los clientes, el
consumo real de cada servidor con nombre y correo de su dueno, y la
actualizacion del panel con respaldo y vuelta atras.

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

1. **Corta la instalacion de raiz** en el nodo.
2. Cambia el puerto a otro libre del mismo nodo, intentando mantener la IP.
3. Avisa al dueno por correo diciendole que revise el token, el usuario y si
   el repositorio es privado.
4. Lo anota en el historial con cliente, nodo, egg, duracion y los dos puertos.

**Aviso al cliente**: cuando su servidor pasa de los minutos configurados, le
sale una tarjeta con iconos (sin emojis) recordandole revisar el token, el
nombre de usuario, si el repositorio es privado y si la version existe, con un
boton para **detener la instalacion**. Una vez detenida, la misma tarjeta le
ofrece **volver a instalar** cuando haya corregido sus datos.

**Control manual**: desde el admin puedes parar cualquier instalacion en curso
al momento.

#### Como se para de verdad una instalacion

wings **no tiene ninguna orden para cancelar una instalacion en marcha**. Su
API solo expone `power`, `commands`, `install`, `reinstall`, `sync` y `delete`,
y `power` se rechaza mientras el servidor esta instalando.

Por eso, marcar la instalacion como fallida en el panel **no para nada**: el
contenedor del nodo sigue trabajando y cuando termina avisa al panel, que
vuelve a mover el estado. El cliente pulsa "detener", parece que funciona, y al
rato vuelve a ver "instalando".

La unica forma real de cortarlo es **borrar el servidor en el nodo**: eso
destruye el entorno y con el el contenedor de instalacion. Es lo que hace el
**modo forzado**, que viene activado por defecto tanto para el sistema
automatico como para el boton del cliente.

| Modo | Que hace | ¿Para el contenedor? |
|---|---|---|
| Solo marcar como fallida | El panel deja de considerarlo "instalando". | **No** |
| Marcar y cambiar puerto | Lo anterior + puerto nuevo. | **No** |
| **Forzado** (por defecto) | Ademas borra el servidor en el nodo. | **Si** |

Como en modo forzado el servidor deja de existir en el nodo, el boton nativo de
"reinstalar" del panel daria un 404. Por eso la extension pone su propio boton
**"Recrear en el nodo"** (admin) y **"Volver a instalar"** (cliente), que lo
crean de nuevo y lanzan la instalacion en un paso.

Los archivos que se pierden son los de una instalacion que nunca termino, o
sea, ninguno que importe.

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

### El boton de detener dice que si pero la instalacion sigue

Comprueba en **Configuracion** que esta marcado *"Cortar de raiz cuando lo
detiene el cliente"*. Sin eso, el panel solo marca la instalacion como fallida
y el contenedor del nodo sigue trabajando. Con eso marcado se borra el servidor
en el nodo y se corta de verdad.

Si aun asi sigue, mira el registro de la extension (pestana **Registro**): si
la orden a wings fallo, ahi aparece el motivo (normalmente el panel no llega al
nodo).

### El corte automatico no salta

Comprueba que esta activado en Configuracion y que el cron del panel corre.
`logspterodactyl:doctor` avisa si no hay muestras recientes.

### No aparece el aviso al cliente

Tiene que estar activado en Configuracion, el servidor tiene que llevar mas
minutos de los configurados, y quien mira tiene que ser el **dueno**.

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
