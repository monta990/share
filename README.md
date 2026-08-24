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

