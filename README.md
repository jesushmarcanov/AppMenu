# AppMenu · menú digital para restaurantes

Aplicación completa de menú digital con panel de administración privado. Menú público
elegante con tema oscuro y claro seleccionable, bilingüe español/inglés, con carrito
local, pedidos por WhatsApp, reservaciones y código QR. Backend PHP MVC sin frameworks
ni Composer, frontend HTML/CSS/JavaScript sin dependencias y base de datos MySQL/MariaDB.

![Tema](https://img.shields.io/badge/tema-oscuro%20%2F%20claro-c9a227)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![Sin dependencias](https://img.shields.io/badge/dependencias-0-6fbf8b)

## Características

### Menú público
- Carta agrupada por categorías y productos con orden manual.
- Tema oscuro y claro con botón en la cabecera y preferencia persistente.
- Español e inglés con selector de idioma persistente.
- Búsqueda instantánea y filtro por categoría.
- Productos destacados, agotados y con emoji o imagen propia.
- Carrito en `localStorage` que arma un pedido para WhatsApp.
- Pedido con nombre y teléfono obligatorios; mesa para consumo en local y dirección
  para entrega.
- Estado de apertura calculado con la zona horaria del restaurante; el botón de
  WhatsApp se bloquea fuera del horario configurado.
- Formulario de reservaciones con validación de fecha, horario, día y aforo.
- Las franjas completas aparecen deshabilitadas con el aviso “Sin espacio”.
- Metadatos SEO, Open Graph, Twitter, JSON-LD y favicon generados desde la marca.
- Diseño responsive, accesible y sin librerías externas.

### Panel de administración
- Acceso con sesión, roles `admin` y `editor`, y cambio de contraseña obligatorio.
- Tema oscuro o claro con botón en la barra superior y en el acceso.
- Resumen con métricas, próximas reservas y actividad reciente.
- CRUD de categorías y productos, con orden manual mediante botones.
- Disponibilidad, destacados y activación/desactivación con interruptores.
- Subida de imágenes JPG/PNG/WebP con recorte, optimización y miniatura.
- Gestión de reservaciones: estados, notas internas y contacto directo.
- Aforo por franja y duración de turno para evitar sobreventa.
- Notificaciones de nuevas reservas por Telegram Bot API.
- Código QR del menú en PNG/SVG, descarga e impresión.
- Configuración de marca, moneda, horarios, reservas, pedidos, Telegram y URL pública.
- Usuarios, roles y auditoría de acciones (solo administradores).
- Verificación en dos pasos (TOTP) para administradores, con códigos de recuperación.

### Seguridad
- Sesiones PHP con cookies `HttpOnly`, `SameSite=Lax` y regeneración de ID.
- CSRF por token en todas las escrituras.
- Límite de intentos en login, 2FA, reservas y API pública.
- Contraseñas con `password_hash` (bcrypt) y cambio forzado.
- 2FA TOTP (RFC 6238) sin dependencias externas, con secreto cifrado mediante
  AES-256-GCM (`APP_KEY`) y códigos de recuperación de un solo uso.
- Validación de subidas por MIME real, tamaño y dimensiones.
- Cabeceras CSP, `X-Frame-Options`, `nosniff` y `Referrer-Policy`.
- Registro de auditoría sin datos sensibles.

## Stack

| Capa | Tecnología |
| --- | --- |
| Backend | PHP 8.1+ (MVC propio, PDO) |
| Base de datos | MySQL 8+ / MariaDB 10.4+ |
| Frontend | HTML, CSS y JavaScript (ES Modules) |
| Servidor local | Apache de XAMPP (o el servidor embebido de PHP) |
| Dependencias | Ninguna (sin Composer, sin npm) |

## Estructura del proyecto

```text
AppMenu/
├─ app/
│  ├─ Controllers/Api/V1/    Controladores de la API
│  ├─ Core/                  App, Router, Request, Response, Auth, Validator…
│  ├─ Middleware/            auth, editor, admin, csrf
│  ├─ Models/                Acceso a datos con PDO
│  ├─ Services/              Menú público, imágenes, reservas
│  ├─ Support/               QR en PHP puro y helpers
│  └─ bootstrap.php
├─ config/
│  ├─ config.php             Lee .env
│  └─ routes.php             Tabla de rutas de la API
├─ database/
│  ├─ migrations/001_init.sql
│  ├─ migrate.php            Aplica migraciones pendientes
│  └─ seed.php               Datos demo idempotentes
├─ public/
│  ├─ index.html             Menú público
│  ├─ admin/index.html       Panel de administración (SPA)
│  ├─ api/index.php          Front controller de la API
│  ├─ assets/css|js/         Estilos y módulos sin dependencias
│  └─ uploads/               Imágenes subidas (ignoradas por git)
├─ storage/logs/             Errores de la aplicación
├─ tests/router.php          Router para el servidor embebido
├─ docs/                     API, base de datos y despliegue
└─ .env.example
```

## Requisitos

- PHP 8.1 o superior con extensiones `pdo_mysql`, `gd`, `fileinfo`, `mbstring` y `json`.
- MySQL 8+ o MariaDB 10.4+.
- Apache con `mod_rewrite` y `AllowOverride All` (XAMPP lo trae así por defecto).
- En Linux/macOS, permisos de escritura para `storage/` y `public/uploads/`.

## Instalación rápida en XAMPP

1. Copia el proyecto en `C:\xampp\htdocs\AppMenu`.
2. Crea el archivo de entorno:

   ```bash
   cp .env.example .env
   ```

   Ajusta `DB_*` si tu MySQL usa otra contraseña. En XAMPP lo habitual es
   `root` sin contraseña y base `appmenu`.

   Genera también una `APP_KEY` (necesaria para la verificación en dos pasos):

   ```bash
   php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
   ```

   Copia el valor en `APP_KEY=` dentro de `.env`.

3. Crea la base de datos y las tablas:

   ```bash
   php database/migrate.php
   ```

4. Carga los datos de demostración:

   ```bash
   php database/seed.php
   ```

5. Abre el menú público y el panel:

   - Menú: <http://localhost/AppMenu/>
   - Panel: <http://localhost/AppMenu/admin/>

6. Inicia sesión con las credenciales demo:

   | Correo | Contraseña | Rol |
   | --- | --- | --- |
   | `admin@restaurante.local` | `Admin123!` | Administrador |

   El primer acceso pide cambiar la contraseña temporal antes de entrar al panel.

> El `seed` es idempotente: no duplica categorías, productos ni usuarios existentes.
> Usa `php database/seed.php --force-settings` si quieres restaurar la configuración demo.

## Servidor embebido (sin Apache)

Para desarrollo rápido o pruebas automatizadas:

```bash
php -S 127.0.0.1:8099 -t public tests/router.php
```

Luego visita <http://127.0.0.1:8099/> y <http://127.0.0.1:8099/admin/>.

El router de `tests/router.php` imita las reglas de `public/.htaccess` y solo debe
usarse en local.

## Roles y permisos

| Sección | Editor | Administrador |
| --- | :---: | :---: |
| Resumen | ✅ | ✅ |
| Reservaciones | ✅ | ✅ |
| Categorías | ✅ | ✅ |
| Productos e imágenes | ✅ | ✅ |
| Código QR | ✅ | ✅ |
| Configuración | — | ✅ |
| Seguridad (2FA) | — | ✅ |
| Usuarios | — | ✅ |
| Auditoría | — | ✅ |

Los permisos se validan en el servidor con los middleware `auth`, `editor` y `admin`;
la interfaz solo oculta las secciones no permitidas.

## Uso del panel

- **Orden manual**: en categorías, los botones ↑/↓ mueven la fila. En productos el
  orden es por categoría y se desactiva mientras hay búsqueda o filtros activos.
- **Imágenes**: formatos JPG, PNG y WebP de hasta 5 MB. Se generan versión principal
  y miniatura; si GD soporta WebP se guardan como `.webp`.
- **Disponibilidad**: el interruptor marca el producto como agotado sin borrarlo.
- **Reservas**: el formulario público respeta días, horario, duración de turno y
  aforo configurados. El cálculo suma las personas de reservas pendientes y
  confirmadas que se solapen con la franja; si se llena, la hora se deshabilita.
  El panel permite confirmar, completar o cancelar.
- **Pedidos**: el carrito exige nombre y teléfono, más mesa para consumo en local o
  dirección para entrega. Si “Respetar horario de atención” está activo, el envío se
  bloquea fuera del horario del restaurante. Los pedidos no se persisten: el mensaje
  solo se abre en WhatsApp.
- **Notificaciones**: cada reserva nueva se puede avisar por Telegram. Crea un bot con
  @BotFather, inicia una conversación con él y guarda el token y el chat ID en
  Configuración. El token no se publica ni se escribe en los registros.
- **QR**: usa `public_menu_url` si está definida; si no, la URL pública detectada.
  Cuando el panel se abre por `localhost` o `127.0.0.1`, el QR usa automáticamente
  la IPv4 de la red local para que el teléfono pueda abrirlo. Para producción define
  `APP_URL` o el enlace público en Configuración.
- **Moneda**: código, símbolo, configuración regional y decimales son configurables.
- **Seguridad (2FA)**: en Sistema → Seguridad cada administrador puede activar TOTP
  escaneando el QR con Google Authenticator, Authy o similar. Guarda los códigos de
  recuperación mostrados una sola vez. Requiere `APP_KEY` en `.env`.

## Pruebas y verificación

Comprobación de sintaxis:

```bash
# PHP (ejemplo con todos los archivos)
find app config database tests public -name "*.php" -print0 | xargs -0 -n1 php -l

# JavaScript del panel y del menú (módulos ES)
node --input-type=module --check < public/assets/js/admin/app.js
node --input-type=module --check < public/assets/js/menu/app.js
```

Pruebas unitarias (sin dependencias):

```bash
php tests/run.php
```

Cubren el cálculo de aforo por solapamiento, la evaluación de horarios con zona
horaria y el formato/validación de los mensajes de Telegram.

Prueba manual recomendada:

1. Menú público en escritorio y móvil, cambio ES/EN, búsqueda y carrito.
2. Pedido en mesa, para llevar y delivery: validación y mensaje de WhatsApp.
3. Reserva válida, intento con fecha pasada, fuera de horario y franja llena.
4. Login, cambio de contraseña forzado y cierre de sesión.
5. CRUD de categorías/productos, reordenar, agotar y subir imagen.
6. Reserva desde el panel, QR, notificaciones y guardado de configuración.
7. Usuario editor: no debe ver Configuración, Usuarios ni Auditoría.

## Documentación

- [API](docs/API.md): endpoints, autenticación, CSRF, errores y ejemplos.
- [Base de datos](docs/DATABASE.md): tablas, relaciones, migraciones y respaldos.
- [Despliegue](docs/DEPLOY.md): producción con Apache/Nginx, HTTPS y diagnóstico.

## Notas de producción

- Define `APP_ENV=production` y `APP_DEBUG=false` en `.env`.
- Sirve el sitio por HTTPS; las cookies de sesión se marcan `Secure` automáticamente.
- Mantén `.env` fuera del repositorio y con permisos restringidos.
- Configura respaldos de la base y de `public/uploads/`.
- Revisa [docs/DEPLOY.md](docs/DEPLOY.md) antes de publicar.
