# ArixLog

Extension para el panel de **Pterodactyl** compatible con el tema **Arix**.

Reune en un solo sitio del area de administracion las cosas que hacen falta
para llevar un hosting sin sustos: los errores del panel, las instalaciones que
se quedan colgadas, los correos que salen (y los que no), el consumo real de
cada servidor con el nombre y el correo de su cliente, y la actualizacion del
panel con respaldo y vuelta atras.

---

## Por que no rompe el tema Arix

Cuando un tema como Arix deja el panel en blanco despues de instalar un addon,
casi siempre es por lo mismo: el addon toca `resources/scripts` y obliga a
ejecutar `yarn build:production`, lo que sobrescribe los assets compilados del
tema. O reemplaza archivos que el tema tambien reemplaza (`routes/admin.php`,
`resources/views/layouts/admin.blade.php`, modelos, controladores...).

ArixLog no hace nada de eso:

| | ArixLog |
|---|---|
| Recompila el frontend de React (`yarn build`) | **No**, nunca |
| Reemplaza archivos del panel | **No**, ninguno |
| Reemplaza archivos del tema | **No**, ninguno |
| Toca `routes/admin.php` o `routes/base.php` | **No** |
| Toca `resources/views/layouts/admin.blade.php` | **No** |
| Toca `app/Console/Kernel.php` | **No** |

Todo el codigo vive en dos carpetas nuevas que no existian:

```
app/Extensions/ArixLog/          <- todo el codigo
public/extensions/arixlog/       <- css y js sueltos
```

Y la unica linea que se escribe fuera de ahi es el registro del proveedor en
`config/app.php`, que el instalador anade entre marcas y el desinstalador
quita dejando el archivo **byte a byte** como estaba.

Las pantallas del administrador extienden `layouts.admin`, asi que heredan el
aspecto del tema que tengas puesto: con Arix se ven como Arix, y sin Arix se
ven como el panel de siempre.

El aviso que ve el cliente y el enlace del menu lateral se inyectan en la
respuesta HTML ya generada, desde un middleware. Por eso sobreviven a las
actualizaciones del tema sin tener que reinstalar nada.

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
ajusta permisos y termina con un diagnostico. Se puede volver a ejecutar
tantas veces como quieras: no duplica nada ni pisa tu configuracion.

Cuando termine, entra en:

```
https://TU-PANEL/admin/arixlog
```

Tambien aparece **ArixLog** en el menu lateral del area de administracion.

### Comprueba el cron

El corte automatico de instalaciones y el consumo de recursos van con el cron
del panel. Si no lo tienes puesto, ponlo:

```bash
crontab -e
```

```
* * * * * php /var/www/pterodactyl/artisan schedule:run >> /dev/null 2>&1
```

---

## Desinstalacion

```bash
cd pterodactyl-log-extencion
sudo bash uninstall.sh
```

Quita los archivos, la linea de `config/app.php` y las tablas. El panel queda
exactamente como estaba.

Si vas a reinstalar y quieres conservar el historial:

```bash
sudo bash uninstall.sh --keep-data
```

Sin preguntas (para scripts):

```bash
sudo bash uninstall.sh /var/www/pterodactyl --force
```

---

## Actualizar la extension

```bash
cd pterodactyl-log-extencion
git pull
sudo bash install.sh
```

---

## Que trae

### 1. Errores del panel

Visor de `storage/logs` con las entradas troceadas, no un volcado de texto.

- Filtro por nivel (error, aviso, critico...) y busqueda dentro del mensaje
  **y de la traza**.
- Muestra directamente la clase de la excepcion y el **primer archivo tuyo**
  que aparece en la traza, saltandose `vendor/`. Eso es lo que sirve para
  saber donde mirar.
- Traza completa desplegable.
- Contadores por nivel, actualizacion automatica, descarga, vaciado y borrado.

Los archivos de log de un panel con trafico pesan cientos de megas, asi que
nunca se cargan enteros: se lee solo la cola del archivo y se recorre desde la
entrada mas nueva parando cuando ya hay suficientes. Un archivo de 18 MB se
resuelve en unos 200 ms.

### 2. Instalaciones colgadas

El problema de los servidores que se quedan tres dias "instalando".

**Sistema automatico**: cada minuto se revisa si hay instalaciones que pasen
del tiempo que configures (5, 10, 15 minutos...). Cuando una lo pasa:

1. Se marca como fallida en el panel. Eso es lo que **desbloquea la pantalla
   del cliente**: deja de ver "instalando" y puede corregir sus datos.
2. Se le cambia el puerto a otro libre del mismo nodo (intentando mantener la
   misma IP).
3. Se avisa al dueno por correo explicandole que revise el token, el usuario y
   si el repositorio es privado.
4. Queda registrado en el historial con el cliente, el nodo, el egg, la
   duracion y el puerto viejo y el nuevo.

**Aviso al cliente**: cuando su servidor lleva mas de los minutos que
configures, le aparece una tarjeta con iconos (sin emojis) que le recuerda
revisar el token, el nombre de usuario, si el repositorio es privado y si la
version que puso existe, y un boton para **detener la instalacion** con su
confirmacion.

**Control manual**: desde el admin puedes parar cualquier instalacion en curso
en el momento, con o sin cambio de puerto.

#### Lo que se puede y lo que no (importante)

wings **no tiene ninguna orden para cancelar una instalacion en marcha**. Su
API solo expone `power`, `commands`, `install`, `reinstall`, `sync` y `delete`,
y las acciones de `power` se rechazan mientras el servidor esta instalando.
Por eso hay dos modos:

| Modo | Que hace | Cuando usarlo |
|---|---|---|
| **Solo marcar como fallida** | El panel deja de considerarlo "instalando". | Casi siempre. |
| **Marcar y cambiar puerto** (recomendado) | Lo anterior + mueve el servidor a un puerto libre. | El caso tipico del token mal puesto. |
| **Forzado** | Ademas borra el servidor en el nodo, lo que **si** mata el contenedor de instalacion colgado. | Cuando el contenedor lleva horas y hay que cortarlo de verdad. |

En los dos primeros modos, el contenedor de instalacion del nodo puede seguir
corriendo hasta que termine por su cuenta. En el modo forzado el servidor deja
de existir en el nodo, asi que el boton nativo de "reinstalar" del panel daria
un 404: usa el boton **"Recrear en el nodo"** de la extension, que lo vuelve a
crear y lanza la instalacion.

El cliente nunca puede usar el modo forzado. Eso queda solo para el admin.

### 3. Consumo de recursos en tiempo real

- Tabla en vivo con CPU, RAM, disco y red de cada servidor, **con el nombre y
  el correo de su cliente** al lado.
- Filtro por nodo y busqueda por servidor, cliente o correo.
- Se marcan en rojo los que pasan de los umbrales que configures.
- **Ranking historico** de los que mas consumen (6 h, 24 h, 7 dias, 30 dias),
  con media y maximo de CPU y RAM. Esto es lo que sirve para detectar abusos
  de verdad, no un pico puntual.

Se consulta a cada nodo de una sola vez (`GET /api/servers` de wings) en lugar
de servidor a servidor. La respuesta de wings incluye tambien la configuracion
completa de cada servidor con sus variables de entorno (tokens, contrasenas de
bases de datos...): **se descarta entera**, solo se toman el identificador, el
estado y el consumo. Nunca se guarda ni se muestra.

### 4. Correos enviados

- Todo lo que sale del panel queda registrado: a que cliente, con que asunto,
  cuando y si salio o fallo.
- Boton para **reenviar** cualquier correo, al mismo destinatario o a otro.
- Vista previa del contenido en un marco aislado sin scripts ni conexiones.
- Boton para mandar un correo de prueba y comprobar la configuracion SMTP.

Los enlaces con credenciales de un solo uso (los de restablecer contrasena) se
guardan **censurados** a proposito: el cliente recibe su correo intacto, pero
la copia del registro no deja un token de acceso a la vista de nadie.

Sobre los estados: "enviado" significa que el servidor de correo acepto el
mensaje, no que el cliente lo haya abierto ni que no haya caido en spam. Si el
envio falla, Laravel no emite ningun evento, asi que un correo que se queda
mas de 15 minutos sin confirmar se marca como fallido.

### 5. Historial de instalaciones

Una fila por cada instalacion y reinstalacion: servidor, cliente, correo, nodo,
egg, estado, duracion, puerto de antes y de despues, y quien la detuvo si se
detuvo. Con filtros y buscador.

### 6. Actualizar el panel

Comprueba la ultima version publicada de Pterodactyl y la instala, **sin
perder el tema Arix**:

1. Comprobaciones previas (permisos, espacio, comandos disponibles).
2. Respaldo completo: archivos y base de datos.
3. Panel en mantenimiento, descarga y comprobacion del paquete oficial.
4. `composer install` y migraciones.
5. Restauracion del tema Arix desde su carpeta `arix/<version>` y recompilado
   de sus assets.
6. Registro de la extension y salida del mantenimiento.

Mientras dura, la pantalla muestra el progreso paso a paso. Lo lee de un
archivo estatico que sirve el servidor web sin pasar por PHP, asi que **se
sigue viendo aunque el panel este en mantenimiento**.

Tambien desde consola, que es lo mas fiable porque corre como root:

```bash
cd /var/www/pterodactyl
sudo php artisan arixlog:panel-update
```

#### Deshacer la actualizacion

```bash
sudo php artisan arixlog:panel-rollback --list   # ver las disponibles
sudo php artisan arixlog:panel-rollback          # deshacer la ultima
sudo php artisan arixlog:panel-rollback 3        # deshacer una concreta
```

O con el boton **Deshacer** de la pantalla del actualizador.

Restaura los archivos y la base de datos tal y como estaban. Los archivos que
la version nueva anadio y que antes no existian se quedan en el disco, pero el
panel restaurado no los usa.

#### Leelo antes de actualizar con Arix puesto

Arix **no es solo una capa de estilos**: reemplaza modelos, controladores,
transformadores y rutas del panel por los suyos, hechos contra una version
concreta de Pterodactyl (Arix 2.1.2 esta hecho para el panel 1.14.1).

Al restaurar el tema despues de actualizar, esos archivos se vuelven a poner
encima del panel nuevo. Si Pterodactyl ha cambiado alguna de esas clases entre
las dos versiones, el panel puede fallar.

**Lo seguro es actualizar primero el tema** a una version que soporte el panel
al que vas. La extension detecta esta situacion y te avisa en la pantalla
antes de dejarte continuar. Y si aun asi sale mal, para eso esta el respaldo.

Otro detalle: el tema trae su propio `config/app.php` con la version del panel
escrita a mano, asi que al restaurarlo el panel diria que sigue en la version
vieja. La extension corrige esa linea y deja el resto del archivo intacto.

---

## Comandos

| Comando | Para que |
|---|---|
| `php artisan arixlog:doctor` | Comprueba que todo esta en su sitio. **Lo primero si algo falla.** |
| `php artisan arixlog:install` | Crea las tablas y la configuracion inicial. |
| `php artisan arixlog:uninstall` | Borra las tablas. |
| `php artisan arixlog:watch-installs` | Revisa las instalaciones colgadas (lo llama el cron). |
| `php artisan arixlog:watch-installs --dry-run` | Enseña que haria, sin tocar nada. |
| `php artisan arixlog:sample` | Toma una muestra del consumo (lo llama el cron). |
| `php artisan arixlog:prune` | Limpia los registros antiguos (lo llama el cron). |
| `php artisan arixlog:panel-update` | Actualiza el panel. |
| `php artisan arixlog:panel-rollback` | Deshace una actualizacion. |

---

## Si algo va mal

**Lo primero, siempre:**

```bash
cd /var/www/pterodactyl && php artisan arixlog:doctor
```

Te dice exactamente que falta y como arreglarlo.

**Despues de actualizar el tema Arix o el panel, la extension desaparece.**
Es normal: los dos reescriben `config/app.php` y se llevan por delante el
registro. Se arregla con:

```bash
cd pterodactyl-log-extencion && sudo bash install.sh
```

(o directamente `php /var/www/pterodactyl/app/Extensions/ArixLog/tools/register-provider.php /var/www/pterodactyl`)

**El corte automatico no salta.** Comprueba que esta activado en Configuracion
y que el cron del panel corre. `arixlog:doctor` avisa si no hay muestras
recientes.

**No aparece el aviso al cliente.** Tiene que estar activado en Configuracion,
el servidor tiene que llevar mas minutos de los configurados, y quien mira
tiene que ser el **dueno** del servidor.

**La tabla de consumo dice que el nodo no responde.** Es la conexion del panel
con wings, no la extension: comprueba que wings esta arriba y que el panel
llega a el.

**El panel se ha quedado en blanco.** No deberia pasar por esta extension
(no toca el frontend), pero si quieres descartarla del todo:

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
- El cliente solo puede detener la instalacion de **sus** servidores.
- El visor de logs no deja salirse de `storage/logs`.
- Las variables de entorno de los servidores que devuelve wings se descartan
  enteras, nunca se guardan ni se muestran.
- Los tokens de un solo uso de los correos se guardan censurados.
- La contrasena de la base de datos no pasa nunca por la linea de comandos al
  hacer el respaldo: va en un archivo temporal con permisos `600`, para que no
  aparezca en la lista de procesos del servidor.

---

## Licencia

MIT.
