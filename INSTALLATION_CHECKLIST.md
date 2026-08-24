# Checklist de instalación limpia

1. Extraer el ZIP.
2. Configurar DNS (A, AAAA o CNAME) hacia el servidor.
3. Configurar Apache con `DocumentRoot` apuntando a `public`.
4. Activar PHP 8.2+ con `pdo_sqlite` y `sqlite3`.
6. Abrir el dominio.
7. Entrar en `/admin`.
8. Crear el primer administrador.
9. Configurar la duración global, plantilla, branding, 404 y APIs.

La aplicación no migra instalaciones de versiones anteriores. Cada ZIP es una instalación limpia.

## Ejemplo de carga mediante API

```bash
curl -X POST \
  -H "X-API-Key: pf_TU_SECRET" \
  -F "file=@/ruta/factura.pdf" \
  https://share.ejemplo.com/api/v1/upload
```

La respuesta incluye `url`, `pin`, `expires_at` y `sha256`. El SHA-256 se calcula en el servidor.


### v1.7.40 — Opciones de enlace en carga manual

La carga web permite usar la configuración predeterminada o personalizar el enlace antes de subir:

- duración en horas;
- máximo de descargas;
- enlace de un solo uso.

La carga por API ofrece los mismos controles con `duration_hours`, `max_downloads` y `one_time`. Si no se especifican, se utilizan los valores globales.

## Paridad web/API para opciones de enlace

La carga manual y la API ofrecen los mismos controles sobre cada enlace:

- `duration_hours`
- `max_downloads`
- `one_time`

En la web se puede marcar **Usar configuración predeterminada** para tomar los valores globales. Al desmarcarlo, se pueden personalizar antes de subir el archivo.

En API, si no se envían esos campos se usan los valores globales; si se envían, se aplican solo a ese enlace.

### v1.7.41 — Corrección de plantilla 404

La plantilla predeterminada de la página 404 ahora contiene los tags obligatorios `{message}` y `{home_url}`. En instalaciones actualizadas, si se detecta una plantilla 404 histórica inválida, administración la reemplaza automáticamente por la plantilla predeterminada válida y registra la reparación en el Registro.

### v1.7.42 — Página 404 fija

La página 404 ya no es configurable. Conserva el diseño del portal y detecta automáticamente Español/English.

Español: `La página que buscas no existe o ya no está el archivo disponible.`
English: `The page you are looking for does not exist or the file is no longer available.`

### v1.7.43 — Límite máximo de carga

La plataforma inicia con un límite máximo de **1024 MB (1 GB) por archivo**, configurable desde Administración → Enlaces.

Al guardar el límite se actualiza `public/php.ini` con `upload_max_filesize` y `post_max_size`. La aplicación además valida el límite internamente en servidor.

La pestaña Salud compara el límite configurado con los límites PHP activos. Si el servidor no admite configuración PHP por directorio, será necesario aplicar esos mismos valores en la configuración global de PHP/FPM o recargar/reiniciar el proceso PHP.

El PIN de descarga acepta únicamente cuatro dígitos y se sanitiza también al pegar texto.

### v1.7.44 — Zona horaria PHP

La configuración incluye **Zona horaria del sistema** con todas las zonas soportadas por PHP mediante `DateTimeZone::listIdentifiers()`.

Valor inicial: `America/Hermosillo`.

La zona seleccionada se guarda en la configuración de la plataforma y se aplica con `date_default_timezone_set()` en cada petición. También se escribe en `public/php.ini` como `date.timezone`.

Esto hace que expiraciones, fechas mostradas y registros usen la misma zona horaria configurada. Si el hosting no permite cambios de `php.ini` por directorio, la aplicación sigue aplicando la zona en tiempo de ejecución mediante PHP.

### v1.0.0 — Arranque robusto y hosting compartido

Se reforzó la inicialización de `storage/`: crea las carpetas, intenta reparar permisos cuando el usuario PHP es propietario y verifica una escritura real.



### v1.0.0 — Corrección crítica de arranque

Se corrigió una regresión introducida al retirar la plantilla 404: la inicialización de SQLite todavía intentaba llamar a `default_404_template()`, función que ya no existe. Eso provocaba que la aplicación fallara antes de cargar Administración o la página principal.

También se corrigió la generación de `php.ini`/`.user.ini` para incluir correctamente `date.timezone` y conservar las directivas de seguridad de PHP.

### v1.0.0 — Permisos del almacenamiento


`755` no es intrínsecamente incorrecto si PHP se ejecuta con el mismo usuario propietario, pero el paquete deja ahora un modo explícito y más restrictivo para almacenamiento.

### v1.0.0 — Alta administrativa robusta

El primer alta administrativa ahora se procesa mediante `fetch()` con las credenciales de la sesión y sigue la redirección HTTP explícitamente. Esto evita que el navegador quede mostrando «Creando acceso…» aunque el servidor ya haya creado el administrador.

### v1.0.0 — Heartbeat del cron

`cron/cleanup.php` registra fecha de ejecución, estado, duración, archivos eliminados y errores. Salud muestra un estado informativo antes de la primera ejecución y advierte ante errores o cuando pasan más de 26 horas sin una ejecución correcta. El estado del programador externo del hosting no se puede consultar directamente desde PHP.

### v1.0.0 — Modo mantenimiento

Se añadió un interruptor en Administración → Seguridad. Al activarlo:

- el sitio público responde HTTP 503 con una página de mantenimiento basada en el diseño de la pantalla de servicio no disponible;
- las APIs responden HTTP 503 en JSON con `code=MAINTENANCE_MODE`;
- `/admin` permanece accesible;
- los scripts CLI/cron continúan funcionando.

El estado queda registrado en la configuración y cada cambio genera un evento de auditoría.

### v1.0.0 — Alta administrativa estable

El primer administrador se crea mediante POST HTTP nativo y la aplicación ejecuta un `303` después de confirmar la transacción SQLite. El JavaScript únicamente deshabilita el botón y muestra el estado de creación; ya no intercepta la petición con `fetch()`.

Antes de insertar se verifican `db` y `fileshare.sqlite`, se garantiza la tabla `admins` y se comprueba que la fila creada pueda volver a consultarse.

### v1.0.0 — Límites y diagnóstico de PHP

Salud muestra PHP, `upload_max_filesize`, `post_max_size`, `max_file_uploads`, `max_execution_time`, `max_input_time` y `memory_limit`, comparando los tiempos efectivos con los configurados en la plataforma.

Administración → Enlaces permite ajustar `max_execution_time` y `max_input_time` entre 30 y 3600 segundos. Los valores se escriben en `public/php.ini` y `public/.user.ini`. Si el hosting impone límites inferiores, Salud los muestra como valores efectivos y marca la discrepancia.

El cron conserva su heartbeat y ahora el estado muestra la hora, resultado, archivos eliminados y duración.

### v1.0.0 — Salud ampliada

El mínimo de espacio libre mostrado en Salud coincide con el máximo de carga configurado en la plataforma (no se usa ya un mínimo fijo de 512 MB).

La cuadrícula de Salud incorpora tres indicadores adicionales: versión de SQLite, tamaño de las bases SQLite y disponibilidad de OPcache. En escritorio se muestran cuatro tarjetas por fila y en resoluciones menores se adaptan de forma responsiva.

### v1.0.0 — Bases SQLite en `db/` y heartbeat persistente


`db/.htaccess` bloquea el acceso HTTP directo a las bases y al heartbeat. El cron registra además `db/cron-heartbeat.json` mediante reemplazo atómico y Salud lo lee directamente, con respaldo en SQLite.

### v1.0.0 — Ejecución manual de limpieza

Administración → Salud incorpora **Ejecutar limpieza ahora**, protegido por CSRF y confirmación. Ejecuta la misma rutina que `cron/cleanup.php` sin duplicar la lógica. El heartbeat registra el origen como `manual` o `cron` y la ejecución queda auditada.

### v1.0.0 — Botón ejecuta `cron/cleanup.php`

El botón de limpieza manual de Administración ya no invoca directamente la rutina desde `app/helpers.php`. Incluye `cron/cleanup.php` y llama a su entrada `execute_cleanup_from_cron()`.

El mismo archivo `cron/cleanup.php` sirve tanto para:
- el cron del hosting en CLI;
- la ejecución manual desde Administración.

No se duplica la lógica entre ambos caminos.

### v1.0.0 — Corrección de regresión de `db/`


`db/` queda protegido contra acceso HTTP directo con `.htaccess`.

### v1.0.0 — Permisos de SQLite corregidos

Se reforzó la inicialización de `db/` y de ambas bases SQLite para hosting compartido. El sistema repara modos compatibles (`0770`/`0775`/`0755` para directorios y `0660`/`0664` para archivos) y valida una escritura real antes de continuar.

La pantalla de error distingue ahora entre:
- `db/` no escribible;
- `fileshare.sqlite` no escribible.

Esto evita volver a diagnosticar ambos casos como un error genérico de almacenamiento.

### v1.0.0 — Permisos iniciales de SQLite

La distribución incluye `db/fileshare.sqlite`, `db/logs.sqlite` y `db/cron-heartbeat.json` ya creados. La carpeta `db/` se entrega con `0755` y los archivos de datos con `0664`, una combinación compatible con muchos hostings compartidos donde PHP y el propietario de los archivos no siempre reciben el mismo conjunto de permisos.

`db/.htaccess` continúa bloqueando el acceso HTTP directo a las bases. Durante el arranque, la aplicación sigue verificando y reparando permisos cuando el servidor lo permite.

### v1.0.0 — SQLite creado por PHP

La distribución ya no contiene `fileshare.sqlite`, `logs.sqlite` ni `cron-heartbeat.json` precreados. PHP crea esos archivos dentro de `db/` durante el primer arranque, para que propietario y grupo sean los del proceso PHP del hosting.


### v1.0.0 — Instalación limpia en desarrollo

El proyecto continúa en desarrollo y **no incluye compatibilidad de migración desde versiones anteriores**. En una instalación limpia, las bases SQLite se crean directamente por PHP dentro de `db/`.


### v1.0.0 — Instalación limpia durante desarrollo

El proyecto continúa en desarrollo y no incluye compatibilidad de migración desde versiones anteriores.
En una instalación limpia, PHP crea `fileshare.sqlite`, `logs.sqlite` y el heartbeat directamente dentro de `db/`.
`storage/` queda reservado para archivos y temporales.

### v1.0.0 — Acceso SQLite compatible con hosting compartido

Se eliminó la dependencia de `is_writable()` y de `chmod()` como criterio de acceso para `db/` y las bases SQLite. El criterio real ahora es que PDO SQLite pueda crear/abrir la base y ejecutar las escrituras necesarias.

`db/` se distribuye con `0770`, igual que `storage/`, porque el hosting objetivo ya ha sido validado con ese esquema de permisos.

### v1.0.0 — Diagnóstico seguro de arranque

Se añadió diagnóstico por fases para instalaciones en hosting compartido. Si el arranque falla, el sistema registra en `storage/.bootstrap-error.log` la fase, clase y mensaje técnicos, con permisos `0600`. El navegador solo recibe una categoría segura y el nombre de la fase; nunca se exponen rutas, SQL ni trazas.

### v1.0.0 — Corrección del bootstrap de mantenimiento

Se corrigieron las funciones `request_is_admin_area()` y `request_is_cron()`, que eran llamadas por el bootstrap pero no estaban definidas. El estado de mantenimiento vuelve a evaluarse después de cargar `helpers.php`, evitando el fallo de arranque `Call to undefined function request_is_admin_area()`.

Administración queda fuera del modo mantenimiento; el resto de la web y la API siguen la política configurada.

### v1.0.0 — Modo mantenimiento restaurado y validado

Se restauró el conjunto completo de funciones del modo mantenimiento: estado, detección de Administración/API/cron y respuesta 503 para web/API. El bootstrap ya no llama funciones inexistentes.

La Administración queda excluida del mantenimiento y la API devuelve HTTP 503 con `error=maintenance_mode` cuando el modo está activo.

### v1.0.0 — Corrección visual de Salud

Se corrigió la cuadrícula de la pestaña Salud. El bloque de ejecución manual de la limpieza de mantenimiento estaba dentro del `foreach` de comprobaciones y se repetía una vez por cada indicador, rompiendo la cuadrícula de 4 columnas.

Ahora cada comprobación ocupa únicamente su tarjeta y la acción de limpieza aparece una sola vez, a ancho completo, debajo de la cuadrícula.

### v1.0.0 — Control de modo mantenimiento

Se añadió un interruptor visible en Administración → Seguridad para activar/desactivar el modo mantenimiento. El estado se guarda con CSRF y queda registrado en auditoría.

Las instalaciones nuevas empiezan con `maintenance_mode=1`: la web pública y la API quedan en mantenimiento hasta que el administrador entra a Administración y desactiva el interruptor. Administración y cron permanecen accesibles.

### v1.0.0 — Tema también disponible durante mantenimiento

La pantalla de mantenimiento usa el mismo selector de tema que el resto del portal y el mismo `theme.js`. El valor por defecto es `Automático`, respetando `prefers-color-scheme`; la selección se conserva en `localStorage` y sigue funcionando mientras el sitio está en mantenimiento.

### v1.0.0 — Guardado automático del modo mantenimiento

El interruptor de modo mantenimiento se guarda automáticamente al cambiarlo, sin botón adicional ni recarga obligatoria. La operación usa POST protegido con CSRF y una respuesta JSON exclusiva para la interfaz administrativa.

La interfaz muestra `Guardando…`, confirma `activado/desactivado` con un mensaje visible y, si falla la operación, revierte el interruptor al estado anterior y muestra el error.

### v1.0.0 — Guardado nativo del modo mantenimiento

El interruptor se guarda automáticamente mediante un POST nativo al panel administrativo. No usa `fetch()`, JSON ni cabeceras AJAX. Al cambiarlo, el servidor guarda el estado y la misma pantalla muestra el aviso de activación o desactivación.

### v1.0.0 — Slider de mantenimiento y zona horaria inicial de PHP

El interruptor de mantenimiento utiliza un listener JavaScript compatible con CSP, por lo que el cambio se envía correctamente al servidor sin depender de `onchange` inline. El estado se guarda con POST protegido por CSRF y la respuesta del servidor muestra la confirmación.

En una instalación nueva, la zona horaria inicial se obtiene de `date_default_timezone_get()` del PHP del servidor. El paquete no fuerza `America/Hermosillo` mediante `php.ini` o `.user.ini`. El administrador puede seleccionar posteriormente cualquier zona soportada por `DateTimeZone::listIdentifiers()`.

### v1.0.0 — Corrección definitiva del interruptor de mantenimiento

Se corrigió el guardado del slider. El control visual ya no se deshabilita antes del POST, y el estado `0/1` se envía en un campo oculto explícito. Esto evita que un navegador omita el valor por tratarse de un control `disabled`.

El cambio utiliza `requestSubmit()`, conserva CSRF y el servidor valida estrictamente que el estado recibido sea `0` o `1`.

### v1.0.0 — Configuración del enlace antes de cargar

La carga manual ya no depende de descubrir las propiedades después. Al seleccionar o arrastrar un archivo, se muestra explícitamente `Opciones para compartir` antes de iniciar la subida.

Se pueden configurar antes de cargar:
- duración del enlace;
- máximo de descargas (`0 = ilimitado`);
- enlace de un solo uso;
- uso de los valores predeterminados de la plataforma.

La pantalla de resultado también muestra las propiedades que se aplicaron. Se añadió un versionado del `app.js` para evitar que el navegador reutilice JavaScript de una build anterior.

### v1.0.0 — Propiedades visibles en Administración → Archivos

La tabla de Archivos y enlaces muestra ahora las propiedades efectivas de cada enlace: duración efectiva calculada entre creación y expiración, máximo de descargas y si es de un solo uso. El PIN continúa sin mostrarse.

### v1.0.0 — Flujo de carga y ajustes visuales

La configuración del enlace está visible desde el inicio, antes de seleccionar el archivo. Se eliminó el control ambiguo de `Usar configuración de la plataforma`; los campos parten de los valores predeterminados de la plataforma y pueden modificarse directamente.

El flujo queda: configuración → selección de archivo → confirmación del archivo seleccionado → subida. El botón `Subir archivo` permanece deshabilitado hasta que exista un archivo seleccionado.

### v1.0.0 — Selector de un solo uso y alineación de opciones

Cuando `Enlace de un solo uso` está activado, `Máximo de descargas` queda deshabilitado y toma automáticamente `1` como valor efectivo. Al desactivar el modo de un solo uso, el campo vuelve a habilitarse y se restablece a `0` (ilimitado).

Se añadió una ayuda contextual bajo `Duración del enlace` para alinear visualmente los tres bloques de configuración.

### v1.0.0 — Correcciones del panel de Administración

Se corrigieron varios fallos detectados en Administración: guardado de zona horaria, guardado de límites de ejecución PHP, límite de cargas por IP, límite máximo de tamaño por archivo, cancelación de confirmaciones, y auditoría de cambios de configuración.

El límite máximo de carga por archivo ahora tiene un control visible en `Administración → Enlaces`, expresado en MB, y sincroniza los valores PHP correspondientes.

### v1.0.0 — Diagnóstico real de PHP y corrección de Registro

Registro ahora escribe el evento de visualización antes de consultar la tabla y las pestañas mantienen correctamente el estado Bootstrap y el estado visual propio.

Salud muestra el `php.ini` cargado, el estado de `.user.ini`, valores configurados frente a valores efectivos y advierte cuando el hosting está imponiendo un límite inferior.

Los archivos INI generados incluyen `file_uploads=On` y `max_input_vars=3000`, además de los límites de subida y tiempos existentes. `.user.ini` queda bloqueado para acceso HTTP directo.

### v1.0.0 — Corrección estructural de pestañas y Registro

Se corrigió una regresión HTML que había dejado `Registro` anidado dentro de `Salud`. Ahora las nueve pestañas son hermanas directas dentro de `adminTabsContent`, por lo que Registro vuelve a mostrarse correctamente.

La pestaña inicial se toma primero del estado servido por PHP, y se mantiene un único slider de modo mantenimiento.

### v1.0.0 — Desplazamiento automático tras una carga

Al completar correctamente una carga, el resultado renderizado se muestra y la página se desplaza suavemente hasta el bloque con los datos del archivo (enlace, PIN, propiedades y SHA-256). El desplazamiento ocurre después de que el contenido se haya renderizado.

### v1.0.0 — PIN de descarga estrictamente numérico

El PIN de descarga ahora acepta únicamente números ASCII, exactamente 4 dígitos. La restricción se aplica en cliente (teclado, pegado y entrada) y se valida nuevamente en servidor antes de comprobar el hash del PIN.

### v1.0.0 — Contador de descargas junto a la expiración

En la página pública de descarga, `Descargas: N` ahora se muestra en la misma línea que el tamaño y la fecha de expiración, manteniendo un espaciado responsive y legible.

### v1.0.0 — Mostrar contador solo después de la primera descarga

La página pública ya no muestra `Descargas: 0` cuando el enlace acaba de ser creado. El contador aparece únicamente después de que exista al menos una descarga. Si existe un máximo configurado, se muestra como `N / máximo` después de la primera descarga.

### v1.0.0 — Sin contador de descargas en la página pública

El contador de descargas se elimina por completo de la información visible de la página pública de descarga. El sistema continúa almacenando y contabilizando las descargas internamente para Administración y estadísticas, pero ese dato no se presenta al usuario final.

### v1.0.0 — Etiqueta de descargas permitidas

En el resultado de una carga, la propiedad que indica el límite configurado ahora se muestra como `Descargas permitidas` en español y `Allowed downloads` en inglés. El contador de descargas realizadas continúa sin mostrarse en la página pública de descarga.

### v1.0.0 — Corrección de PIN, integridad y desplazamiento interno

Se corrigió la validación del PIN en la página pública de descarga para que el script pueda ejecutarse bajo CSP y limpie cualquier carácter que no sea numérico. El PIN continúa validándose también en servidor.

La verificación HMAC de los registros ahora utiliza exactamente los mismos campos que se usaron al crear el hash; el identificador interno autoincremental de SQLite no forma parte de la huella.

Se eliminó el contenedor de desplazamiento vertical interno de Administración para que Salud utilice el mismo desplazamiento de página que el resto de pestañas.

El resultado de carga muestra `Descargas permitidas` como propiedad configurada y no muestra el contador de descargas realizadas.

### v1.0.0 — Presentación del límite de descargas

La pantalla posterior a una carga muestra únicamente la fecha de expiración en esa línea; no muestra descargas realizadas.
La página pública de descarga muestra debajo de la expiración las descargas permitidas y usa `∞` cuando el enlace es ilimitado.

### v1.0.0 — Contador de descargas realizadas en la descarga pública

La página pública de descarga muestra ahora dos datos independientes debajo de la expiración: `Descargas permitidas` (con `∞` para ilimitadas) y `Descargas realizadas` con el número actual de descargas.

### v1.0.0 — Eliminación de auditoría en un solo paso

La eliminación de todos los registros usa un único POST con una sola confirmación y un redireccionamiento 303 posterior. Se evita el reenvío del POST y se suprime únicamente el registro automático de visualización de la recarga inmediata, por lo que la tabla queda vacía después de eliminar.

### v1.0.0 — Barra y selector de tema en mensajes 404

Las pantallas de mensaje utilizadas por enlaces inválidos, expirados o archivos no disponibles ahora conservan la barra superior pública con identidad de la plataforma y selector de tema. El selector incluye Claro/Oscuro/Automático y respeta `prefers-color-scheme` cuando se usa Automático.

### v1.0.0 — Salud y desplazamiento administrativo

Se corrigió la estructura de la pestaña Salud: la cuadrícula de comprobaciones termina antes de `Limpieza de mantenimiento`, evitando columnas vacías y espacios estructurales innecesarios. La sección de limpieza, el resumen del sistema y la optimización de bases quedan como bloques independientes.

Se forzó el desplazamiento vertical a nivel de documento en Administración y se eliminaron alturas máximas/desplazamientos verticales internos de la envolvente de pestañas para evitar la doble barra visible en la pestaña Salud.

### v1.0.0 — Corrección de regresión en Salud

Se corrigió la duplicación del contenedor de `Limpieza de mantenimiento` que estaba desajustando la pestaña Salud. También se compactó el diagnóstico PHP para no imprimir la lista completa de rutas de INI del servidor; ahora muestra el archivo PHP cargado, el estado de `.user.ini` y la cantidad de INI adicionales detectados.

### v1.0.0 — Espaciado inicial de pestañas

Se eliminó el `min-height` de 240 px del contenedor de pestañas de Administración, que dejaba un bloque vacío antes del contenido visible. El espacio entre la barra de pestañas y el contenido queda reducido a un margen intencional de 1rem.

### v1.0.0 — Corrección del marcado de Registro

Se restauró el contenedor `<div class="tab-pane">` de la pestaña Registro. El atributo `id="tab-register"` había quedado fuera de una etiqueta HTML, por lo que el contenido de Registro se mostraba sobre las demás pestañas. Todas las pestañas vuelven a tener un panel independiente.

### v1.0.0 — Resumen integrado en Estadísticas

Se eliminó la pestaña `Resumen` porque duplicaba información de `Estadísticas`. Sus indicadores generales —total de archivos, archivos activos, descargas acumuladas y espacio físico— ahora aparecen al inicio de `Estadísticas` bajo `Resumen general`. `Estadísticas` queda como pestaña predeterminada y como destino de cualquier selección antigua de `summary`.

### v1.0.0 — Estadísticas como primera pestaña

La pestaña `Estadísticas` ahora aparece en la primera posición, a la izquierda de `Personalización`. El resto de pestañas conserva su orden.

### v1.0.0 — Zona horaria aplicada a expiraciones

Las fechas de expiración se almacenan ahora en UTC y se muestran usando la zona horaria configurada en Administración. Cambiar la zona horaria ya no altera el instante real de expiración: solamente cambia la hora mostrada en Administración, la página pública de descarga y los resultados de carga. Las instalaciones existentes se normalizan una sola vez al arrancar.


---

## v1.0.0 — Corrección del cache del logo

La gestión del logo utiliza un nombre físico estable (`public/assets/branding/logo.*`) para simplificar el despliegue, pero ahora cada reemplazo genera un identificador de versión independiente y se añade como query string a la URL pública del logo. Esto evita que el navegador o una caché intermedia continúe mostrando una imagen anterior después de eliminarla y subir una nueva. Al eliminar el logo también se invalida su versión.

**Estado de la corrección:** verificada en código; el comportamiento esperado es que cada subida de un logo produzca una URL distinta aunque la extensión y el nombre físico sean iguales.

---

## v1.0.0 — Protección contra fuerza bruta y rate limit global de API

Se establecen **5 intentos por ventana de 5 minutos** para credenciales inválidas.

- **PIN de descarga:** límite por `enlace (download_id) + IP`.
- **Administración:** límite por `usuario administrador + IP`, incluso cuando el usuario no existe.
- Al superar el quinto intento fallido se responde HTTP `429` hasta que termina la ventana.
- Un PIN válido o un inicio de sesión válido limpia los intentos fallidos de ese sujeto/IP.

El límite `requests_per_hour` de cada API secret ahora se aplica a **todas las peticiones autenticadas**, incluyendo GET y DELETE además de POST/upload. Las cuotas diarias de archivos y bytes permanecen específicas de las cargas.

---

## v1.0.0 — Bloqueos de PIN visibles y desbloqueo desde Archivos

La pestaña **Archivos** incorpora el estado de seguridad de cada enlace dentro de **Propiedades**. Se muestran los intentos incorrectos de la ventana actual de 5 minutos y, cuando el límite de 5 intentos se alcanza, el enlace aparece como **PIN bloqueado** con la cantidad de IP que lo mantienen bloqueado. Administración puede utilizar **Desbloquear** para eliminar esos bloqueos del enlace sin modificar el archivo, PIN, expiración ni contador de descargas.

El PIN nunca se muestra ni se almacena en claro.

---

## v1.0.0 — Integración configurable de ClamAV

Se añadió análisis antivirus antes de publicar cualquier archivo subido por web o API. La configuración se encuentra en **Seguridad → Antivirus / ClamAV**.

- Comando predeterminado: `clamdscan --no-summary {file}`.
- El marcador `{file}` se sustituye por la ruta temporal con `escapeshellarg()`.
- Se rechazan separadores, redirecciones, sustituciones y comillas de shell en el campo de configuración.
- Se incluye **Probar antivirus** para comprobar la disponibilidad real del comando en el hosting.
- Un resultado `CLEAN` permite publicar el archivo.
- `FOUND` rechaza el archivo.
- Cualquier error o resultado indeterminado también impide publicar el archivo (**fail closed**).
- Las cargas permanecen en temporal hasta completar el análisis.

---

## v1.0.0 — Antivirus desactivado por defecto en instalaciones nuevas

Las instalaciones nuevas comienzan con el análisis antivirus **desactivado** para mantener compatibilidad con hostings que no ofrecen ClamAV.

- El comando predeterminado continúa siendo `clamdscan --no-summary {file}`.
- Seguridad muestra claramente cuando el antivirus no está configurado.
- Mientras permanezca desactivado, la carga funciona como antes, sin análisis antivirus.
- Al activar el antivirus, la aplicación ejecuta automáticamente una prueba.
- Si la prueba falla, el comando se guarda pero el antivirus permanece desactivado y se muestra el motivo.
- El administrador debe habilitarlo únicamente después de que la prueba sea satisfactoria.

---

## v1.0.0 — Etiqueta genérica de Antivirus

El nombre visible en Administración ahora es **Antivirus**. La implementación continúa siendo configurable y no está ligada visualmente a ClamAV; `clamdscan --no-summary {file}` permanece únicamente como comando predeterminado para el proveedor actual.

---

## v1.0.0 — Estado visual durante análisis antivirus

Durante la subida web, una vez completadas las partes del archivo, la interfaz mantiene la barra al 100% y muestra un indicador visual de **“Analizando archivo…”** con spinner mientras el servidor ejecuta el análisis antivirus y finaliza la publicación. Así se evita que la pausa posterior a la carga parezca un bloqueo.

---

## v1.0.0 — Reinicio completo al cargar otro archivo

El botón **Subir otro archivo** ahora limpia completamente el estado anterior: archivo seleccionado, resumen de selección, resultado de la carga, mensajes de error, progreso, spinner de análisis y estado interno de la carga. También restablece el selector de archivo y deshabilita el botón de subida hasta seleccionar un nuevo archivo.

---

## v1.0.0 — Etiqueta de archivo analizado por antivirus

La pestaña **Archivos** muestra la etiqueta verde **Analizado** únicamente cuando ese archivo concreto superó correctamente el análisis antivirus. Si el antivirus estaba desactivado durante la carga, no se muestra ninguna etiqueta. El estado se persiste en `files.antivirus_scanned` para que cambiar posteriormente la configuración global del antivirus no altere la información histórica del archivo.

---

## v1.0.0 — Esquema de desarrollo sin migraciones

Se mantiene `files.antivirus_scanned` directamente en el esquema actual de desarrollo. No se incluyen migraciones ni compatibilidad para bases de instalaciones anteriores, conforme a la etapa actual de desarrollo del proyecto.

---

## v1.0.0 — Restauración de valores predeterminados al iniciar otro upload

Al pulsar **Subir otro archivo**, se limpia el estado del upload anterior y las opciones del enlace vuelven a los valores configurados por la plataforma para **Máximo de descargas** y **Enlace de un solo uso**. La **Duración del enlace** se conserva con el valor que el usuario había definido para continuar con el mismo flujo de trabajo.

---

## v1.0.0 — Análisis manual de archivos existentes

Cuando el antivirus está activo, los archivos que fueron cargados previamente sin análisis muestran la acción **Analizar** en Administración → Archivos. Un resultado limpio marca el archivo como **Analizado**. Si el antivirus detecta una amenaza, se ejecuta el mismo criterio de rechazo de una carga nueva: el archivo físico y su enlace se eliminan y la acción queda registrada en Auditoría. Si el motor no puede determinar el resultado, el archivo se conserva sin modificar y se informa del error.

---

## v1.0.0 — Restauración completa de valores de plataforma

**Subir otro archivo** ahora restablece las tres opciones del enlace a la configuración actual de la plataforma: **Duración del enlace**, **Máximo de descargas** y **Enlace de un solo uso**. Además, la sincronización inicial del selector de un solo uso conserva el máximo de descargas configurado por la plataforma cuando la opción está desactivada.

---

## v1.0.0 — Botón Analizar para archivos existentes

Cuando el antivirus está activo, un archivo que todavía no tiene el estado **Analizado** y cuyo físico está disponible muestra ahora el botón **Analizar** en Administración → Archivos. El análisis reutiliza el mismo motor de las cargas nuevas. Si resulta limpio se marca como **Analizado**; si detecta una amenaza, se eliminan el archivo físico y el enlace y se registra la acción; si el resultado es indeterminado, el archivo se conserva.

---

## v1.0.0 — Acciones de antivirus y PIN con estilo uniforme de tags

Las acciones **Analizar** y **Desbloquear** de Administración → Archivos ahora usan el mismo estilo visual de etiquetas (`badge rounded-pill`) que las demás propiedades, manteniendo sus colores semánticos y comportamiento interactivo.

---

## v1.0.0 — Botones de Antivirus con tamaño uniforme

En Administración → Seguridad → Antivirus, **Guardar configuración** y **Probar antivirus** ahora comparten tamaño compacto, altura y ancho mínimo para que ambos controles se vean uniformes.

---

## v1.0.0 — Configuración de Enlaces en dos columnas

La pestaña **Enlaces** ahora presenta sus bloques de configuración en una cuadrícula responsive de dos columnas en escritorio. En pantallas pequeñas vuelve automáticamente a una sola columna para conservar legibilidad.

---

## v1.0.0 — Seguridad en dos columnas

La pestaña **Seguridad** ahora organiza Modo mantenimiento, Antivirus y Seguridad en una cuadrícula responsive de dos columnas en escritorio. En pantallas pequeñas vuelve automáticamente a una sola columna.

---

## v1.0.0 — Restauración de la cuadrícula de Enlaces

Se corrigió una regresión introducida al reorganizar otras pestañas: la cuadrícula de **Enlaces** vuelve a declararse localmente y muestra sus bloques en dos columnas en escritorio. En pantallas pequeñas se conserva una sola columna.

---

## v1.0.0 — Regla global de pestañas en dos columnas

Se establece una regla visual global para los grupos de configuración con tarjetas: dos columnas en escritorio y una columna en pantallas pequeñas. La regla común `.admin-two-column-grid` se reutiliza para evitar regresiones de maquetación entre pestañas.

---

## v1.0.0 — Pulido visual de tags y botones de Seguridad

Se homogenizó el tamaño, alineación, altura y espaciado de los tags de propiedades de **Archivos**. Las acciones **Analizar** y **Desbloquear** usan el mismo componente visual de tag. También se fijó un tamaño uniforme para **Guardar configuración** y **Probar antivirus**, con adaptación a ancho completo en móviles.

---

## v1.0.0 — Salud en cuatro columnas

La pestaña **Salud** mantiene sus comprobaciones agrupadas en **cuatro columnas en escritorio**, pasando a dos en pantallas intermedias y a una en móvil. Las demás pestañas conservan la regla global de dos columnas.

---

## v1.0.0 — Corrección visual de Salud en cuatro columnas

Se corrigió la combinación del grid de cuatro columnas de **Salud** con las clases Bootstrap `col-sm-6 col-xl-3`. Las columnas ahora ocupan realmente todo el ancho de cada celda del grid, evitando tarjetas estrechas y texto excesivamente partido. El resto de pestañas no cambia.

---

## v1.0.0 — Documentación actualizada

Se actualizó la documentación para reflejar el estado actual del proyecto en desarrollo: regla global de dos columnas para las pestañas de configuración, Salud con cuatro columnas en escritorio (dos en pantallas intermedias y una en móvil), análisis antivirus configurable y análisis manual de archivos existentes, etiquetas de estado de antivirus/PIN, reinicio de valores del formulario al usar **Subir otro archivo**, y política actual de esquema **sin migraciones** durante esta etapa de desarrollo.

---

## v1.0.0 — Botón Cancelar desactivado sin archivo

En la pantalla de carga, **Cancelar** ahora permanece desactivado mientras no exista un archivo seleccionado, igual que **Subir archivo**. Al seleccionar un archivo ambos controles quedan disponibles; al cancelar/restablecer la carga vuelven a quedar desactivados.

---

## v1.0.0 — Cancelar realmente desactivado sin archivo

Se corrigió la referencia del botón de cancelación y ahora `Cancelar` queda desactivado de forma real tanto en HTML como en JavaScript mientras no exista un archivo seleccionado. Al seleccionar un archivo se habilita; al cancelar, completar una carga o pulsar **Subir otro archivo**, vuelve a quedar desactivado.

---

## v1.0.0 — Paquete sin archivos de auditoría

El paquete de distribución ya no incluye archivos específicos de auditoría interna o listas de QA de seguridad. La documentación operativa y de usuario se mantiene en el paquete.

---

## v1.0.0 — Alineación de acciones de Antivirus

Se corrigió la alineación vertical de **Guardar configuración** y **Probar antivirus**. Ambos controles permanecen en la misma fila, con el mismo alto/ancho y sin margen vertical independiente en el segundo formulario.

---

## v1.0.0 — Restauración de Estadísticas + cuatro columnas

Se restauró la pestaña **Estadísticas** a partir de la base funcional anterior y se aplicó únicamente el diseño de cuatro columnas: cuatro en escritorio, dos en pantallas intermedias y una en móvil. El contenido de estadísticas y **Resumen por API** permanece intacto.

---

## v1.0.0 — Corrección de Enlace de un solo uso

Al activar **Enlace de un solo uso**, el campo **Máximo de descargas** queda realmente deshabilitado y visualmente atenuado, con valor forzado a `1`. Al desactivarlo vuelve a habilitarse y recupera el valor predeterminado de la plataforma. También se mejoró el espaciado entre el selector y su etiqueta.

---

## v1.0.0 — Corrección de caché en controles de carga

Se corrigió una regresión causada por el **cache-buster fijo** de `assets/app.js`: la página seguía solicitando `app.js?v=1.0.77`, por lo que el navegador podía ejecutar una versión antigua del JavaScript. El recurso ahora usa la versión actual del build, asegurando que el comportamiento de **Enlace de un solo uso** se actualice correctamente.

---

## v1.0.0 — Invalidación automática de caché

Se eliminó el versionado fijo de `app.js` y se incorporó `app_asset_url()`, que genera URLs de assets usando la versión de la aplicación y la fecha de modificación física del archivo. También se envían cabeceras `no-store/no-cache` en las páginas HTML dinámicas.

Esto evita que el navegador siga utilizando JS/CSS/theme/logo de una build anterior y elimina la necesidad de editar manualmente `?v=` al cambiar archivos estáticos.

---

## v1.0.0 — Estado inicial del enlace de un solo uso renderizado por servidor

El estado inicial de **Enlace de un solo uso** ya no depende del JavaScript para representarse correctamente. PHP renderiza el selector marcado, el valor `1` y el campo **Máximo de descargas** deshabilitado cuando esa es la configuración predeterminada de la plataforma. JavaScript únicamente mantiene el comportamiento al cambiar el selector después de cargar la página.

---

## v1.0.0 — Recuperación estable y corrección del 500

Se corrigió un error fatal que provocaba **HTTP 500 en la portada y Administración**: ambas páginas llamaban `send_no_cache_headers()` antes de cargar `app/bootstrap.php`, donde se cargan las funciones auxiliares. La llamada ahora ocurre después del bootstrap.

La build parte de **v1.0.033** como base estable. El selector **Enlace de un solo uso** conserva su sincronización y añade un refuerzo al evento `click`; `app.js` se carga con `defer`.

---

## v1.0.0 — Control aislado de Enlace de un solo uso

Se aisló el control de **Enlace de un solo uso** en `public/assets/one-time.js`. El controlador se carga como asset independiente y con `defer`, y es el único responsable de habilitar/deshabilitar **Máximo de descargas**.

Comportamiento:
- Activado → `Máximo de descargas = 1` y campo deshabilitado.
- Desactivado → campo habilitado y recupera el valor predeterminado de la plataforma.
- El estado inicial se aplica incluso si el script principal de carga no ha terminado de inicializarse.

---

## v1.0.0 — Reparación estructural de Estadísticas

Se reparó la estructura HTML de **Estadísticas**. Una build anterior había insertado el contenido de Estadísticas dentro del formulario de contraseña de Seguridad, dejando el panel vacío.

Se restaura la estructura funcional de Seguridad + Estadísticas y se mantiene la distribución de los indicadores de Estadísticas en cuatro columnas. Salud conserva sus cuatro columnas y el controlador aislado de Enlace de un solo uso se mantiene en la portada.


## v1.0.0 — Production release candidate

Release candidate for production deployment. Runtime databases, uploaded files, temporary files, and diagnostic logs are intentionally excluded from version control; create them on the server during installation.

---

## v1.0.0 — Selector de archivos corregido

Se corrigió el botón **Seleccionar archivo** usando un `label` HTML asociado directamente a `fileInput`, de modo que el selector nativo funciona sin depender de `input.click()` en JavaScript.

Se eliminó de la interfaz el mensaje **Archivo seleccionado**. La selección sigue habilitando **Subir archivo** y **Cancelar**, pero no añade ese bloque de texto.


### Corrección de selector de archivos

El selector nativo de archivos funciona mediante el control HTML asociado al botón **Seleccionar archivo**. Al seleccionar un archivo, **Subir archivo** y **Cancelar** se habilitan automáticamente. No se muestra ningún texto adicional de “Archivo seleccionado”.


### Carga automática al seleccionar archivo

Al seleccionar o soltar un archivo, la carga comienza automáticamente. Los botones de carga y cancelación no se muestran.

### Adaptación al espacio disponible
La página principal reduce espaciados y alturas en pantallas de escritorio para aprovechar el alto disponible y evitar una barra de desplazamiento innecesaria cuando todo el contenido puede caber en el viewport.

### Corrección del desplazamiento vertical

El layout compacto ya no fija la página a la altura del viewport ni utiliza `overflow: hidden`. La página conserva el ajuste compacto cuando hay espacio disponible, pero recupera el desplazamiento vertical normal cuando el resultado de una carga hace crecer el contenido.


### Corrección de error 500 en enlaces de descarga

Se corrigió un error de inicialización en `public/download.php`: `send_no_cache_headers()` se estaba ejecutando antes de cargar `app/bootstrap.php`. Esto provocaba un error fatal de PHP al abrir enlaces `/f/...`. La llamada ahora ocurre después del bootstrap.


### Tooltip de enlaces multiuso

Los enlaces multiuso muestran una descripción según su configuración: un solo uso, máximo de descargas cuando existe un límite, o descargas ilimitadas cuando el límite es 0.


### Corrección al iniciar una nueva carga

Al pulsar **Subir otro archivo**, el estado de **Enlace de un solo uso** ahora vuelve a sincronizar explícitamente el campo **Máximo de descargas**. Esto evita que el grupo quede visualmente deshabilitado cuando el valor predeterminado de la plataforma permite modificar la cantidad con teclado.
