# Changelog

Este archivo contiene el historial de cambios de Portal de archivos.

## 1.0.0

### API
- API v1 para carga, consulta y eliminación de archivos.
- Autenticación mediante `X-API-Key` o `Authorization: Bearer`.
- Scopes `files.upload`, `files.read` y `files.delete`.
- Límites de solicitudes y cuotas por secret.
- Soporte para `multipart/form-data` y carga binaria.
- Integración con antivirus cuando está habilitado.
- SHA-256 calculado en servidor.

### Portal
- Enlaces temporales.
- PIN de 4 dígitos.
- Límites de descargas.
- Enlaces de un solo uso.
- Administración, estadísticas, salud, auditoría y mantenimiento.
