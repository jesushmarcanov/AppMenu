# Despliegue

Guía para publicar AppMenu en un servidor con Apache o Nginx. El proyecto no necesita
Composer ni npm: solo PHP, la base de datos y los archivos del repositorio.

## 1. Requisitos del servidor

- PHP 8.1+ con `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `json`, `session`.
- MySQL 8+ o MariaDB 10.4+.
- Apache con `mod_rewrite` o Nginx con PHP-FPM.
- HTTPS (recomendado; las cookies se marcan `Secure` automáticamente).
- Extensiones/paquetes de imagen de GD (`php-gd`) para subir fotos.
- `curl` (recomendado) para enviar notificaciones por Telegram. Si no está, se usa
  `file_get_contents` con un contexto HTTP; el servidor debe permitir salidas HTTPS
  hacia `api.telegram.org`.

Configuración PHP sugerida:

```ini
upload_max_filesize = 6M
post_max_size = 8M
max_execution_time = 30
memory_limit = 128M
display_errors = Off
log_errors = On
```

`UPLOAD_MAX_MB` en `.env` debe ser coherente con `upload_max_filesize`.

## 2. Archivos y permisos

Sube el proyecto **fuera** del directorio público, o deja `public/` como único
DocumentRoot. Nunca expongas `.env`, `app/`, `config/`, `database/` ni `storage/`.

```bash
# Propietario y permisos en Linux
sudo chown -R www-data:www-data /var/www/appmenu
sudo find /var/www/appmenu -type d -exec chmod 755 {} \;
sudo find /var/www/appmenu -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/appmenu/storage /var/www/appmenu/public/uploads
```

En Windows/XAMPP el usuario de Apache suele tener permisos de escritura en `htdocs`;
verifica `storage/logs` y `public/uploads` si aparecen errores 500.

## 3. Variables de entorno

Copia `.env.example` a `.env` y ajusta:

```dotenv
APP_NAME=AppMenu
APP_ENV=production
APP_DEBUG=false
APP_URL=https://menu.mirestaurante.com
APP_BASE_PATH=
APP_TIMEZONE=America/Caracas
# Obligatoria para la verificación en dos pasos (cifra el secreto TOTP).
APP_KEY=base64:…
SESSION_LIFETIME=7200
UPLOAD_MAX_MB=5

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=appmenu
DB_USERNAME=appmenu_user
DB_PASSWORD=una-clave-larga
DEFAULT_ADMIN_EMAIL=admin@restaurante.local
DEFAULT_ADMIN_PASSWORD=Admin123!
```

Notas:

- `APP_URL` se usa en el QR, en las URLs de imágenes y en los enlaces absolutos.
  Debe incluir el esquema y **no** una barra final.
- `APP_BASE_PATH` solo es necesario si el sitio vive en una subcarpeta distinta de
  `/` y no se detecta automáticamente (por ejemplo `/menu`).
- `APP_DEBUG=false` evita filtrar trazas internas; los errores se registran en
  `storage/logs/`.
- `APP_KEY` cifra secretos en reposo (secreto TOTP de 2FA). Genera una clave única
  por instalación y no la cambies una vez activada la verificación en dos pasos:

  ```bash
  php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
  ```

  Si la clave cambia o se pierde, los secretos TOTP existentes no podrán descifrarse
  y los administradores deberán desactivar y volver a activar 2FA.
- Guarda `.env` con permisos `600` y fuera del control de versiones.

## 4. Base de datos

Crea un usuario dedicado y aplica las migraciones:

```sql
CREATE DATABASE appmenu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'appmenu_user'@'localhost' IDENTIFIED BY 'una-clave-larga';
GRANT ALL PRIVILEGES ON appmenu.* TO 'appmenu_user'@'localhost';
FLUSH PRIVILEGES;
```

```bash
php database/migrate.php
php database/seed.php          # opcional: solo para una instalación nueva
```

`php database/migrate.php` aplica también `002_two_factor.sql`, que añade las
columnas de verificación en dos pasos a `users`.

Tras el primer acceso, cambia la contraseña del administrador. Para producción
considera no ejecutar el seed demo y crear el usuario desde el panel o con SQL.

## 5. Apache

### Opción A: DocumentRoot en `public/` (recomendada)

```apache
<VirtualHost *:80>
    ServerName menu.mirestaurante.com
    DocumentRoot /var/www/appmenu/public

    <Directory /var/www/appmenu/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/appmenu-error.log
    CustomLog ${APACHE_LOG_DIR}/appmenu-access.log combined
</VirtualHost>
```

Con esta opción `public/.htaccess` envía `/api/v1/*` a `public/api/index.php` y sirve
el resto de archivos estáticos.

### Opción B: DocumentRoot en la raíz del proyecto

El `.htaccess` de la raíz reescribe las peticiones hacia `public/`. Es el modo que usa
XAMPP al copiar el proyecto en `htdocs/AppMenu`. Requiere:

```apache
<Directory /var/www/appmenu>
    AllowOverride All
    Require all granted
</Directory>
```

En XAMPP, comprueba que `httpd.conf` tenga `mod_rewrite` activo y `AllowOverride All`
para `htdocs`.

### HTTPS con Let's Encrypt

```bash
sudo certbot --apache -d menu.mirestaurante.com
```

Redirige HTTP a HTTPS y renueva el certificado automáticamente. Las sesiones usarán
cookie `Secure` sin cambios de código.

## 6. Nginx

```nginx
server {
    listen 80;
    server_name menu.mirestaurante.com;
    root /var/www/appmenu/public;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    # API: todo /api/v1/* pasa por el front controller
    location ^~ /api/v1 {
        try_files $uri /api/index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Subidas: solo imágenes, nunca ejecutar código
    location ^~ /uploads/ {
        try_files $uri =404;
        location ~ \.php$ { return 403; }
    }

    # No exponer archivos sensibles
    location ~ /\.(env|git) { return 404; }

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
```

La CSP también la envía la API (`Response::securityHeaders`); para HTML estático
puedes añadir las mismas cabeceras en el servidor.

## 7. Publicación

1. Respalda base de datos y `public/uploads/`.
2. Sube el código nuevo.
3. Ejecuta `php database/migrate.php`.
4. Ajusta permisos de `storage/` y `public/uploads/`.
5. Limpia cachés del navegador si cambiaste CSS/JS (los archivos se sirven con su
   nombre original).
6. Verifica:
   - `GET /api/v1/health` → `200` y base conectada.
   - Menú público y selector ES/EN.
   - Login, cambio de contraseña y panel.
   - Subida de una imagen y QR.
   - Estado de pedidos (`/api/v1/orders/availability`) y franjas de reserva.
   - Notificación de prueba de Telegram si está configurado.

## 8. Respaldos y mantenimiento

```bash
# Base de datos
mysqldump -u appmenu_user -p appmenu | gzip > /backups/appmenu-$(date +%F).sql.gz

# Imágenes
tar czf /backups/appmenu-uploads-$(date +%F).tar.gz -C /var/www/appmenu/public uploads
```

- Programa ambos respaldos a diario.
- Purga opcionalmente `rate_limits` vencidos.
- Revisa `storage/logs/` y los logs del servidor web.
- Mantén PHP, MySQL/MariaDB y el sistema actualizados.

## 9. Diagnóstico

| Síntoma | Causa probable | Solución |
| --- | --- | --- |
| 404 en `/api/v1/...` | `mod_rewrite` o `.htaccess` inactivo | Activa el módulo y `AllowOverride All`. |
| 500 al subir imágenes | Faltan permisos o `gd`/`fileinfo` | Revisa permisos y extensiones PHP. |
| “No pudimos conectar” en el panel | `APP_URL`, base path o sesión mal configurados | Revisa `.env`, consola del navegador y logs. |
| El QR apunta a `localhost` o el teléfono no lo abre | `APP_URL` vacío o equipo en otra red | Define `APP_URL`/“Enlace público”. Sin configurar, se usa la IPv4 local: el teléfono debe estar en la misma Wi-Fi y el firewall debe permitir Apache. |
| Cambios no se ven en el menú | Caché de 60 s del endpoint `/menu` | Espera o recarga; el panel no cachea. |
| Login redirige siempre al cambio de contraseña | `must_change_password = 1` | Cambia la contraseña o actualiza el usuario. |
| Errores de sesión tras cambiar de dominio | Cookie de otro dominio/ruta | Borra cookies y sirve todo desde un único origen. |
| Imágenes antiguas siguen visibles | Caché del navegador/CDN | Fuerza recarga; los archivos tienen nombre aleatorio. |
| Telegram no envía avisos | Token/chat incorrectos, bot sin conversación o salida HTTPS bloqueada | Guarda el token y chat ID, inicia el bot, revisa `storage/logs/` y permite `api.telegram.org`. |
| El botón de pedido aparece deshabilitado | Fuera de horario, pedidos desactivados o WhatsApp inválido | Revisa “Pedidos y visibilidad”, el horario del día y el número en formato internacional. |
| Una franja de reserva aparece llena | Aforo alcanzado por reservas solapadas | Ajusta el aforo, el turno o revisa las reservas pendientes/confirmadas. |

Los errores internos incluyen un `request_id` en la respuesta; búscalo en
`storage/logs/` para localizar la traza correspondiente.
