# API HTTP — Portal de archivos v1

La API permite que un ERP, sistema interno o aplicación automatizada cargue, consulte y elimine archivos.

## URL base

La API usa el mismo dominio del portal:

```text
https://TU-DOMINIO/api/v1
```

Ejemplo:

```text
https://share.cyalimentos.com/api/v1
```

En los ejemplos usa tu dominio real. `share.ejemplo.com` no es una URL real.

## Autenticación

Crea un **secret** en:

**Administración → APIs**

El secret completo se muestra una sola vez.

Puedes autenticarte de cualquiera de estas dos formas.

### Opción recomendada: X-API-Key

```http
X-API-Key: pf_TU_SECRET
```

### Authorization Bearer

```http
Authorization: Bearer pf_TU_SECRET
```

No envíes las dos cabeceras a la vez.

## Importante para PowerShell

En PowerShell, `curl` normalmente es un alias de `Invoke-WebRequest` en Windows PowerShell. Por eso esto:

```powershell
curl -H "X-API-Key: pf_TU_SECRET" ...
```

puede producir el error de conversión que muestra PowerShell.

Usa **`curl.exe`** explícitamente:

```powershell
curl.exe -X POST `
  -H "X-API-Key: pf_TU_SECRET" `
  -F "file=@C:\ERP\factura.pdf" `
  "https://TU-DOMINIO/api/v1/upload"
```

O usa el cmdlet nativo de PowerShell:

```powershell
$headers = @{
    "X-API-Key" = "pf_TU_SECRET"
}

$form = @{
    file = Get-Item "C:\ERP\factura.pdf"
}

$response = Invoke-RestMethod `
    -Uri "https://TU-DOMINIO/api/v1/upload" `
    -Method Post `
    -Headers $headers `
    -Form $form

$response
```

## Permisos (scopes)

Cada secret puede tener estos permisos:

| Scope | Permite |
|---|---|
| `files.upload` | Cargar archivos |
| `files.read` | Consultar archivos creados por ese secret |
| `files.delete` | Eliminar archivos creados por ese secret |

Un secret sin el scope necesario recibe `403`.

## Endpoints

| Método | Endpoint | Scope |
|---|---|---|
| `POST` | `/api/v1/upload` | `files.upload` |
| `GET` | `/api/v1/files/{id}` | `files.read` |
| `DELETE` | `/api/v1/files/{id}` | `files.delete` |

El `{id}` de consulta/eliminación puede ser el ID numérico del registro o el identificador público del enlace (`download_id`), siempre que pertenezca al secret autenticado.

---

# 1. Cargar un archivo

## POST `/api/v1/upload`

Acepta dos formatos de entrada:

1. `multipart/form-data` — recomendado.
2. Cuerpo binario (`application/octet-stream`).

### Multipart/form-data

Campo obligatorio:

```text
file
```

Campos opcionales:

| Campo | Tipo | Descripción |
|---|---|---|
| `language` | `es` / `en` | Idioma del resultado compartido |
| `duration_hours` | entero | Duración de este enlace. `1` a `8760` horas |
| `max_downloads` | entero | Máximo de descargas. `0` = ilimitado |
| `one_time` | booleano | `true` convierte el enlace en un solo uso |
| `share_template` | texto | Plantilla de salida personalizada, si está habilitada |

Cuando `one_time=true`, el límite efectivo queda en `1`.

### Ejemplo Bash / Linux / macOS

```bash
curl.exe -X POST \
  -H "X-API-Key: pf_TU_SECRET" \
  -F "file=@/ruta/factura.pdf" \
  -F "language=es" \
  -F "duration_hours=72" \
  -F "max_downloads=5" \
  -F "one_time=false" \
  "https://TU-DOMINIO/api/v1/upload"
```

> En Linux/macOS usa `curl`. En Windows PowerShell usa `curl.exe`.

### Ejemplo PowerShell

```powershell
$headers = @{
    "X-API-Key" = "pf_TU_SECRET"
}

$form = @{
    file            = Get-Item "C:\ERP\factura.pdf"
    language        = "es"
    duration_hours  = "72"
    max_downloads   = "5"
    one_time        = "false"
}

$response = Invoke-RestMethod `
    -Uri "https://TU-DOMINIO/api/v1/upload" `
    -Method Post `
    -Headers $headers `
    -Form $form

$response.file.url
$response.file.pin
$response.file.sha256
```

### Ejemplo PHP

```php
<?php

$url = 'https://TU-DOMINIO/api/v1/upload';
$secret = 'pf_TU_SECRET';
$file = __DIR__ . '/factura.pdf';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'X-API-Key: ' . $secret,
    ],
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($file),
        'language' => 'es',
        'duration_hours' => '72',
        'max_downloads' => '5',
        'one_time' => 'false',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
]);

$body = curl_exec($ch);

if ($body === false) {
    throw new RuntimeException(curl_error($ch));
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

if ($status < 200 || $status >= 300 || !($data['ok'] ?? false)) {
    throw new RuntimeException($data['message'] ?? 'Error de API');
}

echo $data['file']['url'], PHP_EOL;
echo $data['file']['pin'], PHP_EOL;
echo $data['file']['sha256'], PHP_EOL;
```

## Respuesta correcta

HTTP `201 Created`.

```json
{
  "ok": true,
  "message": "Archivo recibido correctamente.",
  "file": {
    "id": 123,
    "filename": "factura.pdf",
    "size": 245760,
    "sha256": "8d4c...91af",
    "url": "https://TU-DOMINIO/f/AbCdEf123",
    "pin": "4119",
    "expires_at": "2026-08-25T09:48:00+00:00",
    "expires_at_local": "25/08/2026 09:48",
    "share_text": "...",
    "language": "es",
    "downloads": 0,
    "max_downloads": 5,
    "one_time": false
  }
}
```

El `pin` se entrega en la respuesta de creación. No se devuelve en consultas posteriores.

## SHA-256

`sha256` se calcula en el servidor sobre el archivo recibido.

No se confía en un hash enviado por el cliente.

## Antivirus

Si el antivirus está habilitado en Administración, la API analiza el archivo antes de publicarlo.

Un archivo infectado no se publica:

```http
422
```

con:

```json
{
  "ok": false,
  "error": "antivirus_detected"
}
```

Si el antivirus no puede ejecutarse correctamente, la carga tampoco se publica:

```http
503
```

con:

```json
{
  "ok": false,
  "error": "antivirus_unavailable"
}
```

---

# 2. Consultar un archivo

## GET `/api/v1/files/{id}`

Requiere:

```text
files.read
```

Ejemplo:

```bash
curl \
  -H "X-API-Key: pf_TU_SECRET" \
  "https://TU-DOMINIO/api/v1/files/123"
```

PowerShell:

```powershell
$headers = @{
    "X-API-Key" = "pf_TU_SECRET"
}

Invoke-RestMethod `
  -Uri "https://TU-DOMINIO/api/v1/files/123" `
  -Method Get `
  -Headers $headers
```

Respuesta:

```json
{
  "ok": true,
  "file": {
    "id": 123,
    "filename": "factura.pdf",
    "size": 245760,
    "mime": "application/pdf",
    "sha256": "8d4c...91af",
    "url": "https://TU-DOMINIO/f/AbCdEf123",
    "expires_at": "2026-08-25T09:48:00+00:00",
    "expires_at_local": "25/08/2026 09:48",
    "downloads": 2,
    "max_downloads": 5,
    "one_time": false,
    "created_at": "2026-08-24T09:48:00+00:00"
  }
}
```

El PIN no vuelve a aparecer.

---

# 3. Eliminar un archivo

## DELETE `/api/v1/files/{id}`

Requiere:

```text
files.delete
```

Ejemplo:

```bash
curl -X DELETE \
  -H "X-API-Key: pf_TU_SECRET" \
  "https://TU-DOMINIO/api/v1/files/123"
```

PowerShell:

```powershell
$headers = @{
    "X-API-Key" = "pf_TU_SECRET"
}

Invoke-RestMethod `
  -Uri "https://TU-DOMINIO/api/v1/files/123" `
  -Method Delete `
  -Headers $headers
```

Respuesta:

```json
{
  "ok": true,
  "message": "Archivo eliminado correctamente.",
  "id": 123
}
```

---

# 4. Subida binaria sin multipart

La API también admite enviar directamente el cuerpo del archivo.

```http
POST /api/v1/upload
Content-Type: application/octet-stream
X-API-Key: pf_TU_SECRET
X-Filename: factura.pdf
X-Language: es
X-Duration-Hours: 72
X-Max-Downloads: 5
X-One-Time: false
```

Ejemplo:

```bash
curl --data-binary "@/ruta/factura.pdf" \
  -H "Content-Type: application/octet-stream" \
  -H "X-API-Key: pf_TU_SECRET" \
  -H "X-Filename: factura.pdf" \
  -H "X-Language: es" \
  -H "X-Duration-Hours: 72" \
  -H "X-Max-Downloads: 5" \
  -H "X-One-Time: false" \
  "https://TU-DOMINIO/api/v1/upload"
```

PowerShell:

```powershell
$headers = @{
    "Content-Type"     = "application/octet-stream"
    "X-API-Key"        = "pf_TU_SECRET"
    "X-Filename"       = "factura.pdf"
    "X-Language"       = "es"
    "X-Duration-Hours" = "72"
    "X-Max-Downloads"  = "5"
    "X-One-Time"       = "false"
}

Invoke-RestMethod `
    -Uri "https://TU-DOMINIO/api/v1/upload" `
    -Method Post `
    -Headers $headers `
    -InFile "C:\ERP\factura.pdf"
```

También se admite:

```text
X-Share-Template
```

para enviar una plantilla de compartición válida.

---

# 5. Límites y cuotas

Cada secret puede tener:

- solicitudes por hora;
- archivos por día;
- GB por día;
- scopes independientes.

En el panel, `0` significa **ilimitado**.

Además de los límites específicos del secret, la aplicación mantiene sus controles generales de carga.

El tamaño máximo por archivo parte de la configuración global de la plataforma.

En la configuración incluida en esta versión:

```text
Máximo por archivo: 1,024 MB
```

---

# 6. Errores HTTP

| HTTP | `error` | Significado |
|---:|---|---|
| 400 | `upload_error` / `size_mismatch` | Solicitud o archivo inválido |
| 401 | `unauthorized` | Secret ausente, inválido o revocado |
| 403 | `forbidden` | Falta el scope necesario |
| 404 | `not_found` | Archivo no encontrado o no pertenece al secret |
| 405 | `method_not_allowed` | Método HTTP incorrecto |
| 409 | `delete_failed` | No se pudo completar una eliminación |
| 413 | `file_too_large` / `invalid_max_downloads` | Archivo o valor fuera de los límites |
| 422 | `invalid_duration_hours` / `invalid_file` / `antivirus_detected` | Datos inválidos o archivo rechazado |
| 429 | `rate_limited` / `quota_exceeded` | Límite o cuota alcanzados |
| 503 | `antivirus_unavailable` | El análisis antivirus no pudo completarse |
| 507 | `insufficient_storage` | Espacio insuficiente |

La respuesta de error sigue este formato:

```json
{
  "ok": false,
  "error": "unauthorized",
  "message": "API key requerida."
}
```

---

# 7. Flujo recomendado para un ERP

```text
ERP
 │
 │ POST /api/v1/upload
 │ X-API-Key
 │ archivo
 ▼
Portal
 │
 ├─ autentica secret
 ├─ comprueba scope
 ├─ aplica límites/cuotas
 ├─ almacena temporalmente
 ├─ analiza antivirus si está activo
 ├─ calcula SHA-256
 ├─ genera enlace
 ├─ genera PIN
 └─ calcula expiración
 │
 ▼
ERP recibe JSON
 │
 ├─ URL
 ├─ PIN
 ├─ SHA-256
 └─ expiración
```

Para automatizaciones empresariales, guarda el `id` o `download_id`, la URL y el SHA-256 asociados al documento de origen.

---

# 8. Seguridad

Los secrets completos solo se muestran al crearlos.

El servidor guarda el hash del secret, no el secret en claro.

No incluyas un secret directamente en el código fuente de un repositorio público.

En producción usa HTTPS.

Para Windows PowerShell, recuerda:

```text
curl.exe     = cURL real
curl         = normalmente alias de Invoke-WebRequest
```

Por eso los ejemplos de Windows de esta documentación usan `curl.exe` o `Invoke-RestMethod`.

---

# 9. Resumen rápido

```text
Crear/cargar:
POST /api/v1/upload

Consultar:
GET /api/v1/files/{id}

Eliminar:
DELETE /api/v1/files/{id}
```

Header recomendado:

```http
X-API-Key: pf_TU_SECRET
```

Scopes:

```text
files.upload
files.read
files.delete
```

`0` en límites y cuotas = ilimitado.
