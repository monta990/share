# Checklist de instalación y puesta en producción

## Servidor

- [ ] DNS (A, AAAA o CNAME) apunta al servidor.
- [ ] `public/` es el `DocumentRoot`.
- [ ] PHP 8.2+ está disponible.
- [ ] `pdo_sqlite` está habilitado.
- [ ] `sqlite3` está habilitado.
- [ ] HTTPS está configurado.
- [ ] `storage/` y `db/` tienen los permisos necesarios.
- [ ] `storage/` y `db/` no son accesibles directamente desde HTTP.

## Aplicación

- [ ] El paquete se instaló como instalación limpia.
- [ ] Se abrió el dominio correctamente.
- [ ] Se creó el primer administrador.
- [ ] Se configuró la zona horaria.
- [ ] Se configuró la duración predeterminada de los enlaces.
- [ ] Se revisó el límite máximo de carga.
- [ ] Se revisó la configuración de seguridad.
- [ ] Se configuró el branding/personalización.
- [ ] Se configuró el antivirus si el servidor lo ofrece.
- [ ] Se probó una carga desde la web.
- [ ] Se probó una descarga con PIN.
- [ ] Se probó un enlace de un solo uso.
- [ ] Se verificó la expiración.
- [ ] Se probó la API.
- [ ] Se configuró `cron/cleanup.php`.

## API

- [ ] Se creó un secret desde Administración → APIs.
- [ ] Se guardó el secret de forma segura.
- [ ] Se asignaron únicamente los scopes necesarios.
- [ ] Se probaron `POST /api/v1/upload`, `GET /api/v1/files/{id}` y `DELETE /api/v1/files/{id}`.
- [ ] Se verificó el comportamiento de límites y cuotas.

## Producción

- [ ] Se comprobó que no haya secretos de producción dentro del repositorio.
- [ ] Se comprobó que SQLite, uploads, temporales y logs permanezcan fuera del control de versiones.
- [ ] Se configuró el cron de mantenimiento.
- [ ] Se realizó una prueba final desde un equipo cliente.
