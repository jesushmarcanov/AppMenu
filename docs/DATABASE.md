# Base de datos

AppMenu usa MySQL 8+ o MariaDB 10.4+ con motor InnoDB y `utf8mb4_unicode_ci`.
Todo el acceso pasa por PDO con consultas preparadas; no hay ORM ni migraciones de
terceros.

## Crear y actualizar el esquema

```bash
# Crea la base (si no existe) y aplica las migraciones pendientes
php database/migrate.php

# Carga los datos demo (idempotente)
php database/seed.php

# Restaura también la configuración demo
php database/seed.php --force-settings
```

`database/migrate.php`:

- Crea la base configurada en `.env` (`DB_DATABASE`, por defecto `appmenu`).
- Registra cada archivo aplicado en `schema_migrations`.
- Ejecuta los `.sql` de `database/migrations` en orden alfabético.
- No reejecuta migraciones ya aplicadas.

## Diagrama de relaciones

```text
users ──< audit_log
categories ──< category_translations
categories ──< products ──< product_translations
settings ──< setting_translations
reservations          (independiente)
rate_limits           (independiente)
schema_migrations     (control interno)
```

## Tablas

### `users`

Cuentas del panel.

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | Autoincrmental. |
| `name` | VARCHAR(120) | Nombre visible. |
| `email` | VARCHAR(190) ÚNICO | Se normaliza a minúsculas. |
| `password_hash` | VARCHAR(255) | Bcrypt vía `password_hash`. |
| `role` | ENUM(`admin`,`editor`) | Permisos. |
| `is_active` | TINYINT(1) | `0` impide iniciar sesión. |
| `must_change_password` | TINYINT(1) | Fuerza el cambio inicial. |
| `last_login_at` | DATETIME NULL | Último acceso correcto. |
| `created_at`, `updated_at` | DATETIME | Auditoría temporal. |

Índice `idx_users_role (role, is_active)`.

### `categories`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `slug` | VARCHAR(120) ÚNICO | URL-safe, generado si se omite. |
| `sort_order` | INT | Orden manual ascendente. |
| `is_active` | TINYINT(1) | Oculta la categoría y sus productos. |
| `created_at`, `updated_at` | DATETIME | |

Índice `idx_categories_order (sort_order, is_active)`.

### `category_translations`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `category_id` | FK → `categories.id` | `ON DELETE CASCADE`. |
| `locale` | ENUM(`es`,`en`) | Único por categoría. |
| `name` | VARCHAR(120) | Obligatorio en español. |
| `description` | VARCHAR(500) | Puede ir vacía. |

Clave única `(category_id, locale)`.

### `products`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `category_id` | FK → `categories.id` | `ON DELETE RESTRICT`. |
| `slug` | VARCHAR(120) ÚNICO | |
| `price` | DECIMAL(10,2) | Se trata como cadena decimal en la API. |
| `unit` | VARCHAR(60) | Unidad base (se sincroniza con `unit_label`). |
| `is_available` | TINYINT(1) | `0` = agotado. |
| `is_featured` | TINYINT(1) | Aparece en destacados. |
| `is_active` | TINYINT(1) | `0` = oculto del menú. |
| `sort_order` | INT | Orden dentro de su categoría. |
| `image_path` | VARCHAR(255) NULL | Ruta relativa a `public/`. |
| `image_thumb_path` | VARCHAR(255) NULL | Miniatura generada. |
| `emoji` | VARCHAR(16) | Alternativa visual a la imagen. |
| `created_at`, `updated_at` | DATETIME | |

Índices: `(category_id, sort_order)`, `(is_active, is_available, is_featured)` y
único por `slug`.

### `product_translations`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `product_id` | FK → `products.id` | `ON DELETE CASCADE`. |
| `locale` | ENUM(`es`,`en`) | Único por producto. |
| `name` | VARCHAR(140) | Obligatorio en español. |
| `description` | VARCHAR(1000) | |
| `unit_label` | VARCHAR(60) | Etiqueta localizada de la unidad. |

### `settings`

Configuración clave-valor. Solo se permiten las claves de `Setting::EDITABLE`.

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `setting_key` | VARCHAR(80) ÚNICO | Nombre de la clave. |
| `value` | TEXT | Escalar o JSON serializado. |
| `is_public` | TINYINT(1) | Reservado para visibilidad. |
| `updated_at` | DATETIME | |

Las claves JSON (`opening_hours`, `reservation_days`) se guardan como texto JSON y se
decodifican al leerlas.

Claves añadidas para reservas y pedidos: `reservation_capacity` (aforo de personas por
franja, `0` = sin límite), `reservation_turn_minutes` (duración del turno),
`order_hours_enabled` (bloquear pedidos fuera de horario) y las credenciales de
Telegram `telegram_enabled`, `telegram_bot_token` y `telegram_chat_id`. Estas últimas
nunca se incluyen en la configuración pública.

### `setting_translations`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `setting_id` | FK → `settings.id` | `ON DELETE CASCADE`. |
| `locale` | ENUM(`es`,`en`) | Único por clave. |
| `value` | TEXT | Texto localizado. |

Claves traducibles: `business_name`, `tagline`, `description`, `address`,
`opening_hours_text`, `reservation_note`.

### `reservations`

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | INT UNSIGNED PK | |
| `name` | VARCHAR(120) | Cliente. |
| `phone` | VARCHAR(30) | Contacto principal. |
| `email` | VARCHAR(190) NULL | Opcional. |
| `reservation_date` | DATE | |
| `reservation_time` | TIME | |
| `party_size` | INT UNSIGNED | Personas. |
| `notes` | VARCHAR(1000) NULL | Nota del cliente. |
| `admin_notes` | VARCHAR(2000) NULL | Nota interna. |
| `status` | ENUM(`pending`,`confirmed`,`cancelled`,`completed`) | Por defecto `pending`. |
| `locale` | ENUM(`es`,`en`) | Idioma de la solicitud. |
| `created_at`, `updated_at` | DATETIME | |

Índices por fecha/hora, estado y teléfono.

#### Cálculo de aforo

La disponibilidad se calcula por solapamiento de intervalos: una reserva ocupa desde
`reservation_time` hasta `reservation_time + reservation_turn_minutes`. Solo cuentan
los estados `pending` y `confirmed`. La consulta usa `SELECT … FOR UPDATE` dentro de
una transacción al crear la reserva, de modo que dos solicitudes simultáneas no
puedan sobrevender la misma franja.

La ventana de reservas es la intersección entre el horario de atención del día y
`reservation_start`/`reservation_end`. Las reservas de hoy requieren al menos 15
minutos de antelación.

### `rate_limits`

Ventanas de límite de peticiones.

| Columna | Tipo | Notas |
| --- | --- | --- |
| `rate_key` | VARCHAR(191) PK | Acción + IP (+ correo en login). |
| `hits` | INT UNSIGNED | Contador de la ventana. |
| `expires_at` | DATETIME | Fin de la ventana. |

Las filas vencidas se reinician al siguiente intento; se pueden purgar sin riesgo.

### `audit_log`

Registro de acciones del panel y eventos de seguridad.

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | |
| `user_id` | FK → `users.id` NULL | `ON DELETE SET NULL`. |
| `action` | VARCHAR(80) | Ej. `product.updated`. |
| `entity` | VARCHAR(60) NULL | Tabla afectada. |
| `entity_id` | INT UNSIGNED NULL | Identificador afectado. |
| `meta` | JSON NULL | Datos de contexto. |
| `ip` | VARCHAR(45) | IPv4 o IPv6. |
| `created_at` | DATETIME | |

### `schema_migrations`

Control interno de `database/migrate.php`: nombre del archivo y fecha de aplicación.

## Datos demo

`database/seed.php` crea, si no existen:

- Administrador `admin@restaurante.local` con contraseña temporal `Admin123!` y
  `must_change_password = 1`.
- Cuatro categorías: Entradas, Platos fuertes, Postres y Bebidas.
- Dieciséis productos bilingües, con precios, emojis, unidades y un producto agotado
  (`tarta-de-chocolate`).
- Configuración de marca “Casa Oliva”, moneda USD, horarios y reservas.

El seed no sobrescribe registros existentes ni la configuración, salvo que se use
`--force-settings`.

## Reinicio completo (entorno local)

> Esto borra todos los datos.

```bash
mysql -uroot -e "DROP DATABASE IF EXISTS appmenu;"
php database/migrate.php
php database/seed.php
```

En Windows con XAMPP puedes ejecutar los mismos comandos desde la carpeta del
proyecto, o usar phpMyAdmin para eliminar la base.

## Respaldos

```bash
# Estructura + datos
mysqldump -uroot appmenu > appmenu-backup.sql

# Restaurar
mysql -uroot appmenu < appmenu-backup.sql
```

Incluye también `public/uploads/` en los respaldos: las imágenes no están en la base
de datos. En producción usa un usuario MySQL dedicado con permisos solo sobre la base
de AppMenu y programa respaldos automáticos.

## Operaciones útiles

```sql
-- Productos agotados
SELECT id, slug FROM products WHERE is_available = 0;

-- Reservas pendientes de los próximos días
SELECT id, name, reservation_date, reservation_time, party_size, status
FROM reservations
WHERE status = 'pending' AND reservation_date >= CURDATE()
ORDER BY reservation_date, reservation_time;

-- Limpiar ventanas de límite vencidas
DELETE FROM rate_limits WHERE expires_at < NOW();

-- Revisar actividad reciente
SELECT action, entity, entity_id, ip, created_at
FROM audit_log ORDER BY id DESC LIMIT 20;
```
