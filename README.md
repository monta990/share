# Portal de archivos

**Versión 1.0.0**

Portal web para cargar, compartir y descargar archivos de forma temporal, con panel de administración y API.

## ¿Qué puede hacer?

### Carga y compartición de archivos
- Cargar archivos desde una interfaz web.
- Arrastrar y soltar archivos.
- Mostrar el progreso de la carga.
- Configurar la duración del enlace.
- Elegir cuántas descargas están permitidas.
- Usar enlaces ilimitados cuando el límite es 0.
- Crear enlaces de un solo uso.
- Proteger los archivos con PIN.
- Mostrar la fecha de expiración y las propiedades del enlace.
- Descargar archivos desde un enlace público.
- Mostrar las descargas realizadas.

### Seguridad
- Protección mediante CSRF.
- Contraseñas de administrador protegidas con hash.
- Bloqueo temporal después de varios intentos incorrectos.
- Protección para PIN y acceso administrativo.
- Límites y controles para API keys y scopes.
- Registro de seguridad y auditoría.
- Cabeceras de seguridad y políticas para el navegador.
- Soporte para HTTPS.
- Los PIN, contraseñas, tokens y secretos no se guardan en los registros de auditoría.

### Antivirus
- Soporte para antivirus proporcionado por el hosting.
- El comando del antivirus se puede configurar desde Administración.
- Compatible con comandos que utilicen `{file}` como marcador del archivo.
- Cuando el antivirus está activo, el archivo se analiza antes de publicarse.
- Los archivos que no pasan el análisis se rechazan.
- Los archivos que se cargaron sin análisis pueden analizarse posteriormente cuando el antivirus quede disponible.

### Administración
El panel permite administrar:

- Personalización del portal.
- Logo, nombre y apariencia.
- Tema claro, oscuro o automático.
- Zona horaria.
- Duración predeterminada de los enlaces.
- Límite predeterminado de descargas.
- Enlaces de un solo uso.
- Límites y parámetros de PHP.
- Antivirus.
- Archivos y enlaces.
- Registros y auditoría.
- Limpieza y mantenimiento.
- Estadísticas.
- Estado y salud del servidor.
- Administradores y contraseña.

### Estadísticas y salud
El panel muestra información como:

- Total de archivos.
- Archivos activos.
- Espacio utilizado.
- Descargas acumuladas.
- Actividad reciente.
- Estadísticas de API.
- Estado de PHP.
- SQLite.
- Almacenamiento.
- Límites de carga.
- Tiempo de ejecución.
- HTTPS.
- Espacio disponible.
- Cron de mantenimiento.
- OPcache y otros componentes.

### API
La API permite integrar la plataforma con otras aplicaciones.

Incluye:
- Carga de archivos.
- Consulta de archivos.
- Eliminación de archivos.
- API keys.
- Scopes de acceso.
- Límites de peticiones.
- Controles de autenticación.

### Mantenimiento
- Limpieza automática de archivos expirados.
- Cron de mantenimiento.
- Bases SQLite separadas.
- Archivos temporales separados.
- Control de espacio y límites del servidor.

## Requisitos

- PHP 8.2 o superior.
- PDO SQLite y SQLite3.
- Servidor web con soporte para `.htaccess` si se utiliza Apache.
- HTTPS recomendado para producción.
- Permisos de escritura para las carpetas de datos.
- Antivirus opcional. Si no se configura, la carga funciona normalmente sin análisis antivirus.

## Instalación rápida

1. Coloca el contenido del proyecto en el servidor.
2. Usa `public/` como document root.
3. Verifica los requisitos de PHP y SQLite.
4. Revisa `config/config.php`.
5. Abre `/admin.php`.
6. Crea el administrador inicial.
7. Configura el portal, límites y seguridad.
8. Configura y prueba el antivirus si el hosting lo ofrece.
9. Configura el cron de `cron/cleanup.php`.

## Archivos de datos

El proyecto mantiene fuera del código fuente:

- Bases de datos SQLite.
- Archivos cargados.
- Archivos temporales.
- Logs.

No deben hacerse públicos ni incluirse en el repositorio.

## Documentación adicional

- `MANUAL_USUARIO.md` — uso del portal.
- `INSTALLATION_CHECKLIST.md` — instalación y puesta en producción.
- `API.md` — integración con la API.


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
