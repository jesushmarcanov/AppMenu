# API de AppMenu

API JSON de la versión 1. Todas las rutas viven bajo `/api/v1` y se consumen desde el
mismo origen (menú público y panel). No hay CORS: el navegador usa la cookie de sesión.

- Base local con XAMPP: `http://localhost/AppMenu/api/v1`
- Base con servidor embebido: `http://127.0.0.1:8099/api/v1`
- Formato: `application/json; charset=utf-8` salvo imágenes y QR.
- Fechas: `YYYY-MM-DD`; horas: `HH:MM`; zona horaria configurable (por defecto
  `America/Caracas`).
- Precios: se envían y reciben como cadenas decimales (`"12.50"`).

## Formato de respuesta

Éxito:

```json
{
  "ok": true,
  "data": { },
  "meta": { "page": 1, "per_page": 20, "total": 16, "pages": 1 }
}
```

Error:

```json
{
  "ok": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Revisa los campos marcados.",
    "request_id": "req_9f2c1a7b3d4e",
    "fields": { "price": "Debe ser un número con máximo 2 decimales." }
  }
}
```

`meta` solo aparece en listados paginados y `fields` solo en errores de validación.
Las respuestas `204 No Content` no llevan cuerpo.

## Autenticación y CSRF

La sesión se guarda en la cookie `appmenu_session` (`HttpOnly`, `SameSite=Lax`,
`Secure` automático bajo HTTPS). El panel la mantiene entre peticiones.

1. Obtén un token CSRF:

   ```http
   GET /api/v1/csrf
   ```

   ```json
   { "ok": true, "data": { "csrf_token": "…64 hex…" } }
   ```

2. Envía el token en todas las escrituras (`POST`, `PUT`, `PATCH`, `DELETE`):

   ```http
   X-CSRF-Token: …64 hex…
   ```

3. Inicia sesión:

   ```http
   POST /api/v1/auth/login
   Content-Type: application/json
   X-CSRF-Token: …

   { "email": "admin@restaurante.local", "password": "Admin123!" }
   ```

   La respuesta incluye `user`, `csrf_token` renovado y `must_change_password`.

   Si la cuenta tiene activa la verificación en dos pasos, `auth/login` todavía no
   crea la sesión: devuelve `two_factor_required: true`, `expires_in` y un
   `csrf_token` para completar el reto:

   ```http
   POST /api/v1/auth/login/2fa
   Content-Type: application/json
   X-CSRF-Token: …

   { "code": "123456" }
   ```

   El `code` puede ser un TOTP de 6 dígitos o un código de recuperación. El reto
   caduca a los 5 minutos y admite 5 intentos por IP + usuario cada 15 minutos.
   Al completarlo se devuelve el mismo payload que un login normal.

4. Si `must_change_password` es `true`, cambia la contraseña antes de usar el panel:

   ```http
   POST /api/v1/auth/change-password
   ```

   ```json
   {
     "current_password": "Admin123!",
     "password": "NuevaClaveSegura1",
     "password_confirmation": "NuevaClaveSegura1"
   }
   ```

5. `POST /api/v1/auth/logout` destruye la sesión. `GET /api/v1/auth/me` devuelve el
   usuario actual y un token CSRF nuevo.

Mientras `must_change_password` sea `true`, todas las rutas de `/admin/*` responden
`403 PASSWORD_CHANGE_REQUIRED`. Solo se permiten `auth/change-password`, `auth/logout`
y `auth/me` hasta completar el cambio.

### Límites de peticiones

| Ámbito | Límite |
| --- | --- |
| API pública por IP | 120 peticiones/minuto |
| Login por IP + correo | 5 intentos / 15 minutos |
| Reto 2FA por IP + usuario | 5 intentos / 15 minutos |
| Activación/gestión de 2FA por IP + usuario | 10 intentos / 15 minutos |
| Reservas por IP | 3 por hora |

Al superarlos se responde `429 RATE_LIMITED`.

### Roles

- `editor`: resumen, reservas, categorías, productos, imágenes y QR.
- `admin`: todo lo anterior más configuración, seguridad (2FA), usuarios y auditoría.

Las rutas marcadas como **admin** responden `403 FORBIDDEN` a un editor.

## Endpoints públicos

| Método | Ruta | Descripción |
| --- | --- | --- |
| GET | `/health` | Estado de la API y de la base de datos. |
| GET | `/csrf` | Token CSRF para formularios públicos. |
| GET | `/menu?lang=es` | Menú completo: ajustes, categorías, productos y destacados. |
| GET | `/categories?lang=es` | Categorías activas con traducciones. |
| GET | `/products?lang=es&category=entradas` | Productos activos; filtro opcional por slug de categoría. |
| GET | `/settings/public?lang=es` | Configuración pública de marca, moneda y reservas. |
| GET | `/reservations/availability?date=2026-11-10&lang=es` | Franjas de reserva con asientos restantes. |
| GET | `/orders/availability` | Si el restaurante acepta pedidos ahora y por qué. |
| POST | `/reservations` | Crea una reservación (requiere CSRF). |

`lang` acepta `es` o `en`. Si se omite, se usa `Accept-Language` y luego el idioma por
defecto. `/menu` devuelve `ETag` y admite `If-None-Match` (`304`).

### `GET /menu`

```json
{
  "ok": true,
  "data": {
    "settings": { "business": { }, "currency": { }, "reservation": { } },
    "categories": [
      {
        "id": 1,
        "slug": "entradas",
        "name": "Entradas",
        "description": "Para abrir el apetito y compartir.",
        "products": [
          {
            "id": 1,
            "slug": "bruschetta-de-tomate",
            "category_id": 1,
            "category_slug": "entradas",
            "category_name": "Entradas",
            "name": "Bruschetta de tomate",
            "description": "Pan artesanal tostado…",
            "price": "6.50",
            "unit": "porción",
            "image_url": "",
            "image_thumb_url": "",
            "emoji": "🍅",
            "is_available": true,
            "is_featured": true,
            "status": "featured"
          }
        ]
      }
    ],
    "featured": [],
    "locales": ["es", "en"],
    "generated_at": "2026-11-01T22:00:00-04:00"
  }
}
```

`status` puede ser `available`, `featured` o `sold_out`. Si `show_sold_out` es `false`,
los productos agotados no se incluyen.

### `POST /reservations`

```json
{
  "name": "Ana Pérez",
  "phone": "+58 412 555 0101",
  "email": "ana@example.com",
  "reservation_date": "2026-11-10",
  "reservation_time": "19:30",
  "party_size": 4,
  "notes": "Mesa junto a la ventana",
  "locale": "es",
  "website": ""
}
```

Reglas: fecha entre hoy y 90 días, día permitido en configuración, hora dentro del
horario y múltiplo del bloque configurado, personas entre 1 y el máximo. Las reservas
de hoy exigen al menos 15 minutos de antelación. El aforo se calcula por solapamiento:
cada reserva ocupa `reservation_turn_minutes` desde su hora y solo cuentan las
pendientes o confirmadas. Si la suma supera `reservation_capacity`, se responde
`422 SLOT_FULL` (con `0` el aforo es ilimitado). El campo `website` es un honeypot:
si llega con contenido se responde `422 SPAM_DETECTED`. Devuelve `201` con `id`,
`status`, fecha, hora y tamaño del grupo.

### `GET /reservations/availability`

```json
{
  "ok": true,
  "data": {
    "date": "2026-11-10",
    "closed": false,
    "slot_minutes": 30,
    "turn_minutes": 90,
    "capacity": 20,
    "slots": [
      { "time": "20:00", "remaining": 2, "available": true },
      { "time": "20:30", "remaining": 0, "available": false }
    ]
  }
}
```

`remaining` es `null` cuando el aforo es ilimitado. La franja también se considera no
disponible si no caben las personas seleccionadas en el formulario.

### `GET /orders/availability`

Evalúa el horario en la zona horaria del restaurante y devuelve:

```json
{
  "ok": true,
  "data": {
    "open": true,
    "respect_hours": true,
    "reason": "ok",
    "timezone": "America/Caracas",
    "weekday": "tuesday",
    "today": { "day": "tuesday", "open": "12:00", "close": "22:00", "closed": false },
    "closes_at": "22:00",
    "opens_at": null,
    "enabled": true,
    "can_order": true
  }
}
```

`reason` puede ser `ok`, `disabled`, `invalid_number`, `closed_day`, `before_open`,
`after_close` o `always_open`. `opens_at` incluye `date`, `day`, `time` e
`in_minutes` cuando aplica.

## Autenticación

| Método | Ruta | Acceso | Descripción |
| --- | --- | --- | --- |
| POST | `/auth/login` | público + CSRF | Inicia sesión. |
| POST | `/auth/login/2fa` | reto pendiente + CSRF | Completa el reto TOTP o de recuperación. |
| POST | `/auth/logout` | sesión + CSRF | Cierra sesión. |
| GET | `/auth/me` | sesión | Usuario, CSRF y `must_change_password`. |
| POST | `/auth/change-password` | sesión + CSRF | Cambia la contraseña propia. |
| GET | `/auth/2fa` | admin | Estado de 2FA y códigos restantes. |
| POST | `/auth/2fa/setup` | admin + CSRF | Genera secreto, URI y QR para configurar. |
| POST | `/auth/2fa/enable` | admin + CSRF | Confirma el código y activa 2FA. |
| POST | `/auth/2fa/disable` | admin + CSRF | Desactiva 2FA con contraseña y código. |
| POST | `/auth/2fa/recovery-codes` | admin + CSRF | Regenera los códigos de recuperación. |

### Verificación en dos pasos (TOTP)

Solo para cuentas **admin**. El secreto se guarda cifrado con AES-256-GCM usando
`APP_KEY`; los códigos de recuperación se almacenan como hashes bcrypt y son de un
solo uso. Si `APP_KEY` no está configurada, `GET /auth/2fa` devuelve
`available: false` y la activación responde `503 TWO_FACTOR_UNAVAILABLE`.

Flujo de activación:

1. `POST /auth/2fa/setup` devuelve `secret`, `otpauth_uri`, `qr_data_uri`
   (imagen SVG en `data:`) y `expires_in` (10 minutos).
2. Escanea el QR en Google Authenticator, Authy, 1Password o similar.
3. `POST /auth/2fa/enable` con `{ "code": "123456" }`. La respuesta incluye
   `recovery_codes` (8 códigos de un solo uso); muéstralos una única vez.

Desactivar o regenerar códigos exige `{ "password": "…", "code": "123456" }`; el
`code` también puede ser un código de recuperación.

## Panel: resumen

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/dashboard` | editor |

Devuelve `stats`, `upcoming_reservations` (máximo 6) y `recent_activity` (máximo 6).

## Panel: categorías

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/categories` | editor |
| POST | `/admin/categories` | editor + CSRF |
| GET | `/admin/categories/{id}` | editor |
| PUT | `/admin/categories/{id}` | editor + CSRF |
| DELETE | `/admin/categories/{id}` | editor + CSRF |
| PUT | `/admin/categories/reorder` | editor + CSRF |

Crear:

```json
{
  "slug": "entradas",
  "sort_order": 1,
  "is_active": true,
  "translations": {
    "es": { "name": "Entradas", "description": "Para compartir." },
    "en": { "name": "Starters", "description": "To share." }
  }
}
```

- `slug` es opcional; se genera desde el nombre y se hace único.
- `sort_order` es opcional al crear; por defecto va al final.
- Las actualizaciones son parciales: los campos omitidos no cambian.
- No se puede borrar una categoría con productos (`409 CATEGORY_HAS_PRODUCTS`).

Reordenar:

```json
{ "ids": [3, 1, 2, 4] }
```

## Panel: productos

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/products` | editor |
| POST | `/admin/products` | editor + CSRF |
| GET | `/admin/products/{id}` | editor |
| PUT | `/admin/products/{id}` | editor + CSRF |
| DELETE | `/admin/products/{id}` | editor + CSRF |
| PUT | `/admin/products/reorder` | editor + CSRF |
| PATCH | `/admin/products/{id}/availability` | editor + CSRF |
| PATCH | `/admin/products/{id}/featured` | editor + CSRF |
| POST | `/admin/products/{id}/image` | editor + CSRF (multipart) |
| DELETE | `/admin/products/{id}/image` | editor + CSRF |

Listado: `page`, `per_page`, `category_id`, `q`, `status` (`available`, `sold_out`,
`featured`, `inactive`) e `is_active`. Devuelve `meta` con la paginación.

Crear o actualizar:

```json
{
  "category_id": 1,
  "price": "9.99",
  "slug": "producto-de-prueba",
  "emoji": "🍝",
  "is_available": true,
  "is_featured": false,
  "is_active": true,
  "sort_order": 5,
  "translations": {
    "es": { "name": "Pasta", "description": "…", "unit_label": "plato" },
    "en": { "name": "Pasta", "description": "…", "unit_label": "plate" }
  }
}
```

`category_id`, `price` y `translations.es.name` son obligatorios al crear. Al
actualizar, cualquier campo omitido conserva su valor, incluido `sort_order`.

Imagen: `multipart/form-data` con el campo `image`. Formatos JPG, PNG o WebP, máximo
5 MB (configurable con `UPLOAD_MAX_MB`). Devuelve el producto con `image_url` y
`image_thumb_url` actualizados. `DELETE` elimina archivos y referencias.

Disponibilidad y destacado:

```json
{ "is_available": false }
{ "is_featured": true }
```

## Panel: reservaciones

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/reservations` | editor |
| GET | `/admin/reservations/{id}` | editor |
| PATCH | `/admin/reservations/{id}` | editor + CSRF |
| DELETE | `/admin/reservations/{id}` | editor + CSRF |

Filtros del listado: `page`, `per_page`, `status` (`pending`, `confirmed`, `cancelled`,
`completed`), `date_from`, `date_to`, `q`.

Actualizar:

```json
{ "status": "confirmed", "admin_notes": "Mesa 4" }
```

Enviar `admin_notes: ""` borra la nota interna. Enviar solo uno de los campos no
modifica el otro.

## Panel: QR

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/qr?format=png` | editor |
| GET | `/admin/qr?format=svg` | editor |
| GET | `/admin/qr?format=png&download=1` | editor |
| GET | `/admin/qr/print` | editor |

La URL codificada es `public_menu_url` o, si está vacía, la URL pública detectada.
Si la petición llega por `localhost`/`127.0.0.1`, la detección usa la IPv4 de la
interfaz de red con salida (por ejemplo `http://192.168.1.20/AppMenu/`), de modo
que el QR sea abrible desde un teléfono. El PNG se devuelve a 12 px por módulo;
el SVG es vectorial. `print` devuelve una página HTML lista para imprimir.

## Panel: configuración (admin)

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/settings` | admin |
| PUT | `/admin/settings` | admin + CSRF |
| POST | `/admin/settings/image` | admin + CSRF (multipart) |
| POST | `/admin/settings/test-notification` | admin + CSRF |

`GET` devuelve `settings` (valores + traducciones), `public`, `editable` y
`translatable`.

`PUT` acepta solo las claves editables. Los campos traducibles se envían como objeto
`{ "es": "…", "en": "…" }`; el resto como escalares:

```json
{
  "business_name": { "es": "Casa Oliva", "en": "Casa Oliva" },
  "tagline": { "es": "Cocina de autor", "en": "Signature cuisine" },
  "phone": "+58 412 555 0101",
  "whatsapp": "584125550101",
  "currency_code": "USD",
  "currency_symbol": "$",
  "currency_locale": "en-US",
  "currency_decimals": 2,
  "default_lang": "es",
  "timezone": "America/Caracas",
  "opening_hours": {
    "monday": { "open": "12:00", "close": "22:00", "closed": false }
  },
  "reservation_enabled": true,
  "reservation_start": "12:00",
  "reservation_end": "22:00",
  "reservation_days": [2, 3, 4, 5, 6, 7],
  "reservation_slot_minutes": 30,
  "reservation_max_party": 12,
  "reservation_capacity": 20,
  "reservation_turn_minutes": 90,
  "whatsapp_orders_enabled": true,
  "order_hours_enabled": true,
  "show_sold_out": true,
  "public_menu_url": "",
  "telegram_enabled": false,
  "telegram_bot_token": "",
  "telegram_chat_id": ""
}
```

`reservation_capacity` es el aforo de personas por franja (`0` = sin límite) y
`reservation_turn_minutes` el tiempo que ocupa cada reserva. `order_hours_enabled`
hace que el menú solo permita pedidos dentro del horario de atención.

Telegram usa la Bot API sin dependencias. `telegram_bot_token` y `telegram_chat_id`
solo se devuelven en el panel autenticado, nunca en `/settings/public`. El token se
valida con el formato de BotFather y no se escribe en los registros.

```json
POST /admin/settings/test-notification
{ "sent": false, "error": "Completa el token del bot y el chat ID antes de probar." }
```

El endpoint envía un mensaje de prueba usando la configuración guardada.

Campos traducibles: `business_name`, `tagline`, `description`, `address`,
`opening_hours_text`, `reservation_note`.

Imagen de marca: `multipart/form-data` con `key=logo|hero` e `image`. Devuelve
`key`, `path`, `url` y la configuración pública actualizada.

## Panel: usuarios y auditoría (admin)

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/admin/users` | admin |
| POST | `/admin/users` | admin + CSRF |
| GET | `/admin/users/{id}` | admin |
| PUT | `/admin/users/{id}` | admin + CSRF |
| DELETE | `/admin/users/{id}` | admin + CSRF |
| GET | `/admin/audit-log?page=1&per_page=30` | admin |

Crear usuario:

```json
{
  "name": "María Editora",
  "email": "maria@restaurante.local",
  "role": "editor",
  "password": "Temporal12345",
  "is_active": true
}
```

- La contraseña debe tener entre 10 y 120 caracteres.
- El nuevo usuario deberá cambiarla en su primer acceso.
- `DELETE` desactiva la cuenta (no la borra).
- No puedes desactivar tu propia cuenta ni dejar el sistema sin administradores
  activos (`LAST_ADMIN`).

## Códigos de error

| Código | HTTP | Significado |
| --- | --- | --- |
| `VALIDATION_ERROR` | 422 | Campos inválidos; revisa `fields`. |
| `INVALID_JSON` | 400 | Cuerpo JSON mal formado. |
| `UNAUTHENTICATED` | 401 | Falta sesión o expiró. |
| `INVALID_CREDENTIALS` | 401 | Correo o contraseña incorrectos. |
| `TWO_FACTOR_NOT_STARTED` / `TWO_FACTOR_EXPIRED` | 401 | El reto 2FA expiró; vuelve a iniciar sesión. |
| `INVALID_2FA_CODE` | 401 / 422 | Código TOTP o de recuperación inválido. |
| `TWO_FACTOR_ALREADY_ENABLED` / `TWO_FACTOR_NOT_ENABLED` | 409 | Estado de 2FA incompatible con la acción. |
| `TWO_FACTOR_SETUP_EXPIRED` | 422 | El QR de configuración caducó; genera uno nuevo. |
| `TWO_FACTOR_UNAVAILABLE` | 503 | Falta `APP_KEY` para cifrar el secreto. |
| `FORBIDDEN` | 403 | Rol sin permisos. |
| `PASSWORD_CHANGE_REQUIRED` | 403 | La contraseña temporal debe cambiarse antes de usar el panel. |
| `NOT_FOUND` | 404 | Recurso inexistente. |
| `METHOD_NOT_ALLOWED` | 405 | Método no permitido para la ruta. |
| `CSRF_TOKEN_MISSING` / `CSRF_TOKEN_INVALID` | 403 | Token CSRF ausente o incorrecto. |
| `RATE_LIMITED` | 429 | Demasiados intentos. |
| `CATEGORY_HAS_PRODUCTS` | 409 | La categoría tiene productos. |
| `LAST_ADMIN` | 422 | Debe quedar un administrador activo. |
| `CANNOT_DEACTIVATE_SELF` | 422 | Un usuario no puede desactivarse. |
| `INVALID_PASSWORD` / `WEAK_PASSWORD` / `PASSWORD_MISMATCH` | 422 | Cambio de contraseña inválido. |
| `UPLOAD_ERROR` / `UPLOAD_INVALID_TYPE` / `UPLOAD_TOO_LARGE` / `UPLOAD_INVALID_IMAGE` | 422 | Imagen no válida. |
| `UPLOAD_STORAGE_ERROR` | 500 | No se pudo guardar la imagen. |
| `SERVER_ERROR` | 500 | Error interno; incluye `request_id` en modo debug. |

## Ejemplo con `fetch`

```js
// 1. Token CSRF
const csrfResponse = await fetch('api/v1/csrf', { credentials: 'same-origin' });
const { data: csrf } = await csrfResponse.json();

// 2. Login
const loginResponse = await fetch('api/v1/auth/login', {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrf.csrf_token,
  },
  body: JSON.stringify({
    email: 'admin@restaurante.local',
    password: 'Admin123!',
  }),
});

// 3. Escritura autenticada
const { data: session } = await loginResponse.json();
await fetch('api/v1/admin/categories', {
  method: 'POST',
  credentials: 'same-origin',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': session.csrf_token,
  },
  body: JSON.stringify({
    is_active: true,
    translations: {
      es: { name: 'Nueva categoría', description: '' },
      en: { name: 'New category', description: '' },
    },
  }),
});
```
