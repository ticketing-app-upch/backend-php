# AlpaTeck — Backend (Laravel)

API REST de la plataforma de ticketing. Usa PHP 8.4, Laravel 13, MySQL 8 y autenticación JWT (`php-open-source-saver/jwt-auth`). El entorno de desarrollo se ejecuta con Docker Compose. **La API usa MySQL** mediante la conexión `mysql` configurada en `compose.yaml` y `.env.example`.

En producción, Render usa PostgreSQL mediante `DB_URL`. Para continuar con una IA,
leer [AGENTS.md](AGENTS.md) y el [contexto breve del proyecto](docs/PROJECT_CONTEXT.md)
antes de abrir únicamente los archivos del flujo solicitado.

El contrato vigente para **frontend-angular, rama demo3**, está en
[FRONTEND_COMPATIBILITY.md](docs/FRONTEND_COMPATIBILITY.md). Incluye eventos JSON,
dos imágenes Base64, 15 recintos/50 zonas, órdenes e importes finales sin comisión adicional.
La entrega anterior está registrada en [DEMO3_IMPLEMENTATION.md](docs/DEMO3_IMPLEMENTATION.md).
El multipart y la compra anterior siguen disponibles; para obtener la respuesta
anterior de eventos se puede usar `?contract=legacy`. Registro/login conservan
sus rutas y usan únicamente los campos de registro confirmados por el usuario.

## Funciones disponibles

| Método y ruta | Acceso | Función |
|---|---|---|
| `POST /api/auth/register` | Público | Registra un cliente con DNI o un organizador con empresa y RUC |
| `POST /api/auth/login` | Público | Inicia sesión con correo y contraseña |
| `POST /api/auth/logout` | Cualquier rol autenticado | Invalida el JWT presentado |
| `GET /api/v1/admin/usuarios` | `ADMIN` | Lista asistentes/organizadores y filtra por rol y estado |
| `PATCH /api/v1/admin/usuarios/{id}/estado` | `ADMIN` | Activa o desactiva una cuenta no administradora |
| `GET /api/events` | Público | Lista eventos publicados y futuros |
| `GET /api/events/{id}` | Público | Consulta un evento |
| `POST /api/events` | `ORGANIZER` activo | Crea un evento con recinto predefinido, banner y precios por zona |
| `GET /api/v1/recintos` | `ORGANIZER` | Lista los 15 recintos demostrativos y sus 50 zonas |
| `GET /api/v1/eventos/mios` | `ORGANIZER` | Lista solo sus propios eventos |
| `PUT/PATCH /api/events/{id}` | `ORGANIZER` | Rechaza edición con 403 en Demo 3 |
| `GET /api/events/{id}/banner` | Público | Entrega el banner JPEG de un evento publicado |
| `GET/POST/DELETE /api/v1/favoritos` | `CLIENT` | Consulta, agrega o elimina favoritos propios |
| `POST /api/events/{id}/tickets` | `CLIENT` activo | Compra entradas con pago simulado y máximo acumulado de 6 por usuario/evento |
| `GET /api/v1/tickets` | `CLIENT` | Lista sus tickets individuales |
| `GET /api/v1/tickets/{id}` | Cliente propietario | Consulta su propia entrada |
| `GET /api/v1/tickets/{code}/validacion` | Público | Confirmación de compra sin datos del comprador |
| `GET /validar/{code}` | Público | Alias JSON para la URL del QR sin frontend configurado |
| `GET /api/events/{id}/sales-report` | Organizador propietario | Consulta ventas del evento |
| `GET /api/events/{id}/secondary-banner` | Público | Entrega la segunda imagen JPEG |
| `GET /api/organizers/{organizerId}/events` | Organizador propietario o `ADMIN` | Lista eventos del organizador |
| `DELETE /api/events/{id}` | Organizador propietario o `ADMIN` | Elimina solo eventos sin ventas ni órdenes |
| `POST /api/orders` | `CLIENT` activo | Compra sin comisión adicional, tickets individuales e idempotencia |
| `GET /api/orders/me` | `CLIENT` | Lista compras propias |
| `GET /api/orders/{id}` | Cliente propietario o `ADMIN` | Detalle de una compra |
| `PATCH /api/orders/{id}` | `ADMIN` | Cancela una compra confirmada y restaura stock una sola vez |
| `GET /api/admin/users` | `ADMIN` | Alias del listado de asistentes/organizadores |
| `PATCH /api/admin/users/{id}` | `ADMIN` | Alias del cambio de estado de usuario |
| `GET /api/admin/events` | `ADMIN` | Lista eventos de cualquier organizador/estado |
| `GET /api/admin/orders` | `ADMIN` | Lista compras de la plataforma |
| `GET /api/admin/metrics` | `ADMIN` | Totales de usuarios, eventos, órdenes y ventas confirmadas |
| `POST /api/v1/dynamic-price` | Público | Precio base autoritativo de una zona, sin reglas dinámicas |
| `POST /pricing/api/v1/dynamic-price` | Público | Alias de cotización para el prefijo de producción del frontend |

El registro público de `ADMIN` está bloqueado. El guard `api` usa JWT; la contraseña se almacena con hash y el token incluye el rol.

## Instalación

### Requisitos

- Docker Desktop iniciado con contenedores Linux y Docker Compose v2.
- Terminal situada dentro de `backend-php`.
- Puertos locales `8080`, `8000` y `3307` disponibles.

PHP, Composer y MySQL se ejecutan en Docker; no hace falta instalarlos en el host.
El primer build necesita conexión para descargar las imágenes y las dependencias
de `composer.lock`.

### Primer arranque desde un clon nuevo

Crear `.env` si todavía no existe. En PowerShell:

```powershell
if (-not (Test-Path -LiteralPath .env)) {
    Copy-Item -LiteralPath .env.example -Destination .env
}
```

En Bash, solo si `.env` no existe, usar `cp .env.example .env`. Conservar las
claves de cualquier entorno que ya esté configurado.

Opcionalmente, completar `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` e
`INITIAL_ADMIN_PASSWORD` en el `.env` privado antes de levantar los contenedores.
La contraseña requiere al menos ocho caracteres, una mayúscula, un número y un
carácter especial. Los tres valores vacíos omiten el administrador; una
configuración parcial hace fallar el seeder. No versionar esos datos.

Construir y levantar el backend y MySQL:

```bash
docker compose up -d --build
docker compose ps
docker compose logs --tail 50 app
```

Esperar a que termine la instalación de dependencias y el servidor esté listo.
La carpeta local se monta sobre `/var/www/html`; si falta `vendor/autoload.php`,
el arranque instala las dependencias en esa carpeta. Si la instalación falla,
revisar los logs y volver a ejecutarla:

```bash
docker compose exec app composer install --no-interaction --prefer-dist
```

En el primer arranque, con `APP_KEY` y `JWT_SECRET` todavía vacíos, generarlos:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
```

Las claves se escriben en `.env`. Como Compose carga ese archivo mediante
`env_file`, recrear solamente el contenedor de la aplicación para que también
reciba los nuevos valores en su entorno; después aplicar migraciones y seeders:

```bash
docker compose up -d --no-deps --force-recreate app
docker compose exec app php artisan migrate --seed
```

`APP_KEY` y `JWT_SECRET` son privados y permanecen en el `.env` ignorado por Git.

`migrate --seed` crea los roles, carga los 15 recintos y 50 zonas, y crea el
administrador si se configuró. Repetir el seeder conserva sus credenciales e IDs;
un correo asignado a otro rol produce un error.

### Comprobar el servicio y sus puertos

| Uso | Dirección desde el host |
|---|---|
| Salud de Laravel | `http://localhost:8080/up` |
| API principal | `http://localhost:8080/api` |
| Cotización base compatible con Demo 3 | `http://localhost:8000/api/v1/dynamic-price` |
| MySQL para un cliente de escritorio | `localhost:3307` |

Los puertos `8080` y `8000` sirven la misma aplicación PHP; no se necesita el
microservicio Python para la cotización base de Demo 3. Dentro del contenedor,
MySQL usa `DB_HOST=db` y `DB_PORT=3306`, como indica `.env.example`.

Comprobar salud y catálogo desde PowerShell:

```powershell
curl.exe -f http://localhost:8080/up
Invoke-RestMethod -Uri http://localhost:8080/api/events
```

En Bash puede usarse `curl -f http://localhost:8080/up`. El catálogo devuelve
`[]` hasta que se publiquen eventos futuros. El cliente HTTP utiliza
`http://localhost:8080/api` como base; CORS conserva la configuración de Laravel.

### Arranques posteriores y actualización de una base existente

Para iniciar un entorno ya configurado basta con `docker compose up -d`. Después
de actualizar el código del backend, ejecutar:

```bash
docker compose up -d --build
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan migrate --seed
```

Mantener las claves existentes. Si se cambian variables privadas de `.env`,
recrear `app` con `docker compose up -d --no-deps --force-recreate app`. Si se
agregan los datos del administrador después del primer arranque, ejecutar luego
`docker compose exec app php artisan db:seed`.

Para detener los servicios conservando los datos de MySQL:

```bash
docker compose down
```

El volumen `mysql_data` conserva la base entre arranques. Esta entrega actualiza
el esquema mediante migraciones aditivas; no requiere reconstruir la base.

## Registro

`POST /api/auth/register` requiere `fullName`, `email`, `password`, `role`, `acceptedTerms` y los campos del perfil correspondiente indicados abajo. `role` solo admite `CLIENT` u `ORGANIZER`. `acceptedTerms` debe llegar como el booleano JSON `true` (HU-06); si falta, es falso o tiene otro tipo (por ejemplo, `"true"`, `"yes"`, `"on"` o `1`), el registro se rechaza con `422` y no se crea ningún registro.

### Campos aceptados

| Parte del registro | Requeridos |
|---|---|
| Ambos roles | `fullName`, `email`, `password`, `role` y `acceptedTerms=true` |
| Asistente (`CLIENT`) | `profile.docNumber`: DNI de 8 dígitos |
| Organizador (`ORGANIZER`) | `organizer.displayName`: nombre de la empresa (2–255 caracteres); `organizer.taxId`: RUC de 11 dígitos |

Estos son los campos confirmados por el usuario mediante las capturas del
2026-10-29, junto con la aceptación obligatoria de términos ya acordada.
Los campos adicionales se ignoran: teléfono/prefijo, país, ciudad, género,
docType, marketingOptIn y otros detalles no se guardan ni se devuelven en
registro/login. El nombre de empresa usa `organizer_profiles.details.displayName`.
La aceptación de términos queda registrada en `users.terms_accepted_at`. No se
eliminan columnas ni datos históricos; tampoco se devuelven esos detalles extra.

Cliente (`CLIENT`):

```json
{
  "fullName": "Juan Perez",
  "email": "juan@example.com",
  "password": "Secret123!",
  "role": "CLIENT",
  "acceptedTerms": true,
  "profile": { "docNumber": "01234567" }
}
```

Organizador (`ORGANIZER`):

```json
{
  "fullName": "Maria Organiza",
  "email": "maria@example.com",
  "password": "Secret123!",
  "role": "ORGANIZER",
  "acceptedTerms": true,
  "organizer": { "displayName": "Eventos SAC", "taxId": "20123456789" }
}
```

El DNI debe ser una **cadena de 8 dígitos** y el RUC una **cadena de 11 dígitos** que comience por `10`, `15`, `17` o `20`. Enviarlos como cadenas conserva los ceros iniciales. El backend comprueba el formato del RUC, pero no consulta SUNAT. La contraseña requiere al menos 8 caracteres, una mayúscula, un número y un carácter especial.

El documento del asistente siempre es un DNI; docType no forma parte del contrato.
No se aceptan números de CE, pasaporte ni DNI como RUC de organizador.
Los campos no reconocidos no se
persisten; en particular, `role_id`, `active` y `verificationStatus` enviados por
el cliente no permiten cambiar privilegios ni el estado de la cuenta.

El usuario y su perfil se crean en una transacción. La respuesta `201` contiene un JWT y los datos del usuario, por ejemplo:

```json
{
  "token": "eyJ...",
  "user": {
    "id": "1",
    "fullName": "Juan Perez",
    "email": "juan@example.com",
    "role": "CLIENT",
    "profile": { "docNumber": "01234567" }
  }
}
```

El perfil del organizador aparece en `user.organizer.displayName` y
`user.organizer.taxId`. Las cuentas antiguas sin nombre de empresa pueden iniciar
sesión; en ese caso displayName es null. La respuesta no incluye contraseña ni
columnas internas. Los conflictos por correo, DNI o RUC duplicados devuelven `409`;
los datos requeridos o formatos inválidos devuelven `422`. Los tres valores tienen
índices únicos en MySQL.

### Casos de éxito y error del registro

| Caso | HTTP | Resultado |
|---|---|---|
| Cliente con campos válidos, DNI disponible y T&C aceptados | `201` | Crea usuario y perfil; devuelve `token` y `user.profile.docNumber` |
| Organizador con empresa válida, RUC disponible y T&C aceptados | `201` | Crea usuario y perfil; devuelve `token`, `user.organizer.displayName` y `user.organizer.taxId` |
| Correo ya registrado, incluso con distintas mayúsculas | `409` | `{"message":"El correo ya está registrado."}` |
| DNI ya registrado | `409` | `{"message":"Ese documento ya está registrado."}` |
| RUC ya registrado | `409` | `{"message":"Ese RUC ya está registrado."}` |
| Duplicado detectado por el índice de la BD durante la inserción | `409` | `{"message":"Ese dato ya está registrado."}` |
| Nombre ausente, de menos de 2 caracteres o de más de 255 | `422` | Error de validación en `fullName` |
| Empresa ausente, vacía, de menos de 2 caracteres o de más de 255 | `422` | Error de validación en `organizer.displayName` |
| Correo ausente, inválido o de más de 255 caracteres | `422` | Error de validación en `email` |
| Contraseña ausente o que incumple algún requisito | `422` | Error de validación en `password` |
| Rol ausente, `ADMIN` o cualquier valor distinto de `CLIENT`/`ORGANIZER` | `422` | Error de validación en `role` |
| T&C ausentes, falsos o enviados como cadena/número | `422` | Error de validación en `acceptedTerms` |
| Perfil requerido ausente, DNI/RUC inválido o documento enviado como número | `422` | Error de validación en el perfil o documento correspondiente |

Los registros rechazados por validación o duplicados no crean un usuario ni un perfil adicional. Enviar `role_id` no permite asignar permisos de administrador: el controlador determina el rol a partir del campo `role` validado.

## Inicio de sesión

`POST /api/auth/login` recibe correo y contraseña:

```json
{
  "email": "juan@example.com",
  "password": "Secret123!"
}
```

Devuelve `200` con `token` y `user` en la misma forma que el registro. Las credenciales incorrectas devuelven `401`; una cuenta inactiva con contraseña correcta devuelve `403`; una solicitud inválida devuelve `422`.

El login está disponible para cuentas activas `CLIENT`, `ORGANIZER` y `ADMIN`. El correo se normaliza a minúsculas. Un correo inexistente y una contraseña incorrecta reciben el mismo cuerpo de error, sin token ni datos del usuario:

```json
{
  "message": "Credenciales inválidas."
}
```

El JWT incluye el identificador del usuario en `sub` y el nombre de su rol en `role`, además de los claims técnicos de autenticación. No incluye nombre, correo, contraseña, DNI ni RUC. El rol se obtiene de la cuenta almacenada en la base de datos; enviar `role` o `role_id` en el login no cambia los permisos ni el contenido del token.

### Casos de éxito y error del login

| Caso | HTTP | Resultado |
|---|---|---|
| Credenciales correctas de una cuenta activa `CLIENT`, `ORGANIZER` o `ADMIN` | `200` | Devuelve `token` y `user` con el rol real de la cuenta |
| Correo con mayúsculas y credenciales correctas | `200` | Se normaliza el correo y se inicia sesión |
| Contraseña incorrecta | `401` | `{"message":"Credenciales inválidas."}`, sin token |
| Correo inexistente | `401` | Mismo mensaje y código que una contraseña incorrecta |
| Cuenta inactiva con contraseña correcta | `403` | `{"message":"Tu cuenta está desactivada. Contacta al administrador"}`; sin token |
| Cuenta inactiva con contraseña incorrecta | `401` | `{"message":"Credenciales inválidas."}`; no revela el estado |
| Correo ausente, inválido o de más de 255 caracteres | `422` | Error de validación en `email` |
| Contraseña ausente, vacía o con un tipo distinto de cadena | `422` | Error de validación en `password` |

El login valida que la contraseña sea una cadena no vacía; la política de contraseña fuerte se aplica al registro. Los campos adicionales `role` y `role_id` se ignoran para autenticar al usuario.

Los JWT nuevos duran **5 minutos** por defecto (`JWT_TTL=5`), según HU-04. Puede configurarse otra duración positiva en minutos para la demo. Es vigencia desde la emisión, no un contador renovado por actividad. Los tokens emitidos antes de cambiar la configuración conservan la expiración de su claim `exp`. En Render, actualizar la variable y desplegar para recachear la configuración.

## Cierre de sesión

`POST /api/auth/logout` requiere `Authorization: Bearer <token>` y responde
`200` con `{"message":"Sesión cerrada correctamente."}`. Invalida ese JWT mediante
la blacklist; otra sesión del mismo usuario conserva su token. Una petición sin
token, con token vencido o ya revocado recibe `401` JSON.

No desactivar `JWT_BLACKLIST_ENABLED`. Render usa la caché compartida `database`
para conservar las revocaciones entre peticiones y reinicios. La limpieza del
token/datos del navegador y la navegación tras logout corresponden al frontend.

## Administración de usuarios (HU-07 y HU-08)

Estas rutas requieren `auth:api` y `role:ADMIN`: `401` sin JWT válido y `403` para
los demás roles. No se agregan endpoints de creación o eliminación de usuarios.

`GET /api/v1/admin/usuarios` admite filtros opcionales combinables:

- `role=CLIENT` o `role=ORGANIZER`.
- `active=1` (activos) o `active=0` (inactivos).

Ejemplo: `/api/v1/admin/usuarios?role=ORGANIZER&active=0`. Devuelve un array
ordenado por ID, o `[]` si no hay resultados. Nunca incluye cuentas ADMIN.

```json
[
  {"id":"2","fullName":"Juan Perez","email":"juan@example.com","role":"CLIENT","active":true}
]
```

Los filtros inválidos reciben `422`. Solo esta respuesta administrativa expone
`active`; no incluye documentos, contraseña, role_id ni timestamps.

`PATCH /api/v1/admin/usuarios/{id}/estado` recibe un booleano JSON estricto:

```json
{"active":false}
```

Responde `200` con el usuario actualizado en la misma forma del listado. Repetir
el estado es idempotente. `true` reactiva la cuenta y permite login con sus mismas
credenciales. Campos adicionales no cambian el rol, contraseña ni otros datos.
La cuenta inexistente recibe `404`; cualquier cuenta ADMIN (incluida la propia)
recibe `403`; un estado ausente, nulo, numérico o como cadena recibe `422`.

Desactivar conserva los perfiles, eventos y tickets. Los JWT emitidos antes de la
desactivación siguen siendo válidos hasta expirar (HU-08); no hay rechazo global
inmediato por `active`. Un organizador inactivo conserva visibles sus eventos
publicados, pero publicar eventos nuevos recibe `403`, incluso con JWT vigente.

## Autorización por rol

Las rutas protegidas requieren el encabezado `Authorization: Bearer <token>`. El middleware `EnsureUserHasRole` está registrado con el alias `role` en `bootstrap/app.php` y admite uno o varios roles. Para proteger una ruta nueva, utiliza primero `auth:api` y luego los roles permitidos:

```php
Route::middleware(['auth:api', 'role:ORGANIZER,ADMIN'])->group(function () {
    // Registrar aquí las rutas que permiten cualquiera de estos dos roles.
});
```

Este es un ejemplo de reutilización. Las rutas actuales de creación de eventos y reporte de ventas permiten `ORGANIZER`; la compra de entradas permite `CLIENT`. La ausencia de un token válido devuelve `401` y un rol no permitido devuelve `403`.

El middleware `auth` del framework valida el guard JWT. Según HU-08, no comprueba
`users.active` en todas las peticiones: las sesiones abiertas terminan al vencer
su JWT o al cerrar sesión. El login sí verifica el estado; crear eventos y comprar
revalidan que la cuenta esté activa. Esto reemplaza el bloqueo inmediato
de la implementación anterior de BUG-003 para ajustarse al backlog final.

| Caso en una ruta protegida | HTTP | Cuerpo de respuesta |
|---|---|---|
| Token ausente, inválido o expirado | `401` | `{"message":"Unauthenticated."}` |
| Cuenta desactivada con un token vigente | Según el endpoint | Conserva acceso hasta expirar; publicar o comprar recibe `403` |
| Token válido, pero rol no permitido | `403` | `{"message":"No tienes permiso para acceder a este recurso."}` |

Para verificar BUG-004 / TC-EXP-002, utiliza `GET /api/events/{id}/sales-report` con un JWT de organizador y un evento propio. Antes del vencimiento responde `200`; después del vencimiento responde `401`, incluso sin el encabezado `Accept: application/json`. `GET /api/events` es un catálogo público y puede responder `200` con un token vencido o sin token, por lo que no sirve para comprobar autenticación.

## Respuestas de eventos, entradas y reportes

| Método y ruta | Éxito | Errores previstos |
|---|---|---|
| `GET /api/events` | `200`: EventItem compatible con Angular, zonas, imágenes y campos de apoyo; `[]` si no hay coincidencias | `422` si los filtros son inválidos. Admite search, category, available, venue y date (`YYYY-MM-DD`, día calendario de Lima) |
| `GET /api/events/{id}` | `200`: datos de un evento publicado y sus zonas | `404`: `{"message":"Evento no disponible"}` si no existe o no está publicado |
| `POST /api/events` | `201`: evento publicado; admite JSON con dos imágenes Base64 o multipart anterior | `401` sin JWT; `403` por rol/cuenta inactiva; `422` por datos/imágenes inválidos; `409` por separación menor de 24 horas en JSON o mismo día de Lima en multipart |
| `POST /api/events/{id}/tickets` | `201`: paymentStatus, quantity, totalPrice y array tickets; un ticket por entrada | `401` sin JWT; `403` por rol/cuenta inactiva; `404` evento no disponible; `422` por pago/zona inválidos o cantidad por intento superior al límite; `409` por aforo insuficiente o exceso acumulado por usuario/evento |
| `POST /api/orders` | `201`: orden con líneas, tickets, subtotal, fee=0 y total sin recargo; `200` en reintento idéntico | `402` pago simulado rechazado; `409` precio/stock/clave incompatibles o exceso acumulado por usuario/evento; `422` formato, zonas o cantidades inválidos; `401/403` por autenticación/permisos |
| `GET /api/events/{id}/sales-report` | `200`: devuelve `event`, `summary` y `zones`; los totales de ventas e ingresos son cero si no hubo ventas | `401` sin token válido; `403` si no es organizador o no es dueño del evento; `404` si el evento no existe |

En la compra, el error `409` devuelve `{"message":"Aforo insuficiente para la cantidad solicitada"}`. Consultar el reporte de un evento ajeno devuelve `403` con `{"message":"No tienes permiso para consultar este reporte."}`. Una compra rechazada no descuenta cupos ni crea entradas parcialmente.

BUG-008: un usuario puede tener como máximo **6 entradas confirmadas del mismo
evento**, sumando todas sus compras y zonas por cualquiera de las dos rutas.
Se cuentan también las entradas históricas sin orden; las canceladas no consumen
cupo. Las compras de otros usuarios o de otros eventos tienen cupos independientes.
Por ejemplo, después de comprar 4 entradas puede comprar otras 2; una tercera
compra de 1 se rechaza con `409` y el mensaje
`La compra supera el máximo de 6 entradas por usuario para este evento.`
No se crean órdenes/tickets ni se descuenta stock al rechazarla.
`maxPerOrder` sigue limitando cada intento, además del máximo acumulado.
Un reintento idéntico de una orden existente devuelve esa misma orden con `200`
incluso al haber alcanzado el cupo; no emite entradas adicionales. Las cuentas y
zonas se bloquean para evitar que intentos simultáneos excedan el límite.

### Formato de los errores de validación

Los errores `422` incluyen `message` y un objeto `errors` con una lista de mensajes por campo. Los nombres de campos permiten al frontend asociar cada error a su entrada: por ejemplo, `acceptedTerms`, `profile.docNumber`, `organizer.taxId`, `zones.0.capacity` o `quantity`. El texto de los mensajes depende de las reglas y del idioma configurado en Laravel. Los errores de negocio, como credenciales incorrectas o datos duplicados, contienen `message` y usan los códigos indicados arriba.

## Esquema y migraciones

Las migraciones de creación (`0001_01_01_000003` a `000005`) crean las tablas de registro, y `2026_10_01_000000` agrega `users.terms_accepted_at`. La estructura resultante es:

| Tabla | Columnas de negocio |
|---|---|
| `users` | `full_name`, `email`, `password`, `role_id`, `active`, `terms_accepted_at`; `marketing_opt_in` conservado para datos históricos, fuera del registro |
| `client_profiles` | `user_id`, `doc_number` (DNI); `details` JSON histórico, sin nuevas escrituras en el registro |
| `organizer_profiles` | `user_id`, `tax_id` (RUC), `details` JSON con `displayName` (empresa) para registros nuevos |

Las tablas también incluyen sus claves primarias y marcas de tiempo. `role_id`
y `user_id` son claves foráneas; `users.email`, `client_profiles.doc_number` y
`organizer_profiles.tax_id` tienen índices únicos. La migración
`2026_10_28_000001_add_frontend_contract_support` agrega los perfiles opcionales,
datos de eventos/recintos, segunda imagen y `ticket_orders`, que agrupa tickets
con snapshots, importes, estado y clave de idempotencia por usuario.

`2026_10_01_000000_add_terms_accepted_at_to_users_table` agrega `users.terms_accepted_at` (nullable: el administrador sembrado no pasa por el registro público). En una base existente, con los contenedores levantados, basta con aplicar las migraciones pendientes; no requiere `migrate:fresh` ni borra datos:

```bash
docker compose exec app php artisan migrate --seed
```

Laravel registra las migraciones aplicadas y ejecuta las pendientes. Esta
actualización conserva usuarios, tickets históricos y stock; el seeder carga el
catálogo nuevo sin borrar los recintos anteriores que puedan tener historial.

## Pruebas

Solo al ejecutar `php artisan test`, `phpunit.xml` sustituye la conexión de MySQL por una base SQLite temporal en memoria. Así las pruebas pueden crear y borrar registros sin modificar la base MySQL de la aplicación. Al terminar las pruebas, la API sigue conectada a MySQL. `tests/TestCase.php` genera claves efímeras para `APP_KEY` y `JWT_SECRET` durante las pruebas, sin depender de secretos reales en `.env`:

```bash
docker compose exec app php artisan test --filter="RegisterTest|LoginTest|RegistrationMigrationTest|RoleMiddlewareTest"
docker compose exec app php artisan test
```

| Archivo | Cobertura |
|---|---|
| `tests/Feature/RegisterTest.php` | Registro de ambos roles, empresa obligatoria para organizadores, campos adicionales ignorados, cada requisito de contraseña, aceptación estricta de T&C, documentos inválidos, duplicados, bloqueo de `ADMIN` y protección frente a manipulación del payload |
| `tests/Feature/LoginTest.php` | Login correcto de los tres roles, exclusión de detalles históricos fuera del contrato, credenciales incorrectas, cuentas inactivas, entradas inválidas, JWT firmado con identificador y rol sin datos personales, e intento de modificar el rol desde el payload |
| `tests/Feature/RegistrationMigrationTest.php` | Columnas del esquema final, índices únicos, claves foráneas y unicidad de DNI/RUC en la base de pruebas |
| `tests/Feature/RoleMiddlewareTest.php` | Acceso permitido y rechazos `401` sin token y `403` con rol no autorizado |
| `tests/Feature/SessionSecurityTest.php` | Sesiones abiertas válidas tras desactivar hasta el vencimiento, duración configurable en minutos, acceso antes/rechazo después de expirar y catálogo público |
| `tests/Feature/LogoutTest.php` | Logout de los tres roles, revocación del JWT presentado, otras sesiones y rechazo de tokens inválidos/revocados |
| `tests/Feature/AdminUserManagementTest.php` | Listado/filtros, permisos ADMIN, cambios idempotentes, reactivación, protección de administradores y conservación de datos/sesiones |
| `tests/Feature/AdminUserSeederTest.php` | Seeding idempotente, correo normalizado, configuración ausente/parcial y conflictos de rol |
| `tests/Feature/StoreEventTest.php` | Recintos/mapas, banners, fechas de Lima, publicación atómica, permisos, inmutabilidad y Mis Eventos |
| `tests/Feature/FavoriteTest.php` | Favoritos propios, idempotencia, índice único y catálogo personalizado |
| `tests/Feature/PurchaseTicketTest.php` | Pago simulado, máximo 6, varias zonas, aforo, rollback y tickets individuales |
| `tests/Feature/PurchaseLimitTest.php` | BUG-008: máximo acumulado por usuario/evento en ambas rutas, compras parciales, distintas zonas, cancelaciones, tickets históricos y reintentos idempotentes |
| `tests/Feature/TicketReadTest.php` | Propiedad de entradas, snapshots inmutables y confirmación pública sin datos personales |
| `tests/Feature/DemoThreeMigrationTest.php` | Migraciones reversibles e individualización de ventas antiguas conservando códigos/importes/stock |
| `tests/Feature/FrontendCompatibilityTest.php` | JSON/imágenes, catálogos, filtros, órdenes sin recargo, idempotencia, permisos, cancelación, rollback e importación histórica |

Última verificación del backend (2026-10-30): **262 pruebas y 1474 aserciones
aprobadas** con PHPUnit en Docker/PHP 8.4.27 y SQLite en memoria, sin red.
BUG-008 agrega 19 casos de regresión y se comprobó además con cinco carreras
en MySQL 8.0.46 temporal, en procesos PHP separados, sin exceder el cupo.
Pint aprobado en los tres archivos PHP corregidos. No se probó concurrencia
en PostgreSQL ni se modificaron las bases habituales.
La comprobación aislada con servicios Angular y las carreras concurrentes en
MySQL están registradas en [FRONTEND_COMPATIBILITY.md](docs/FRONTEND_COMPATIBILITY.md).

## Despliegue en Render

`render.yaml` define el servicio Docker y PostgreSQL. `bin/deploy.sh` cachea
configuración/rutas/vistas, aplica migraciones y ejecuta `DatabaseSeeder`, que
siembra los roles y la cuenta administradora configurada de manera idempotente.
En el panel privado de Render, completar `INITIAL_ADMIN_NAME`,
`INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD`; las tres vacías omiten el admin,
y una configuración parcial detiene el seeding. No poner credenciales en Git.
`JWT_TTL=5` se define en el Blueprint; revisar variables ya existentes en Render.

El servicio existente es `https://alpateck-backend.onrender.com`; su salud se
consulta en `/up`. Las modificaciones locales requieren publicación y despliegue
autorizados antes de estar disponibles allí. Demo 3 requiere las cuatro migraciones
aditivas `2026_10_25_*`, la compatibilidad `2026_10_28_000001` y `VenueSeeder`
(incluido en DatabaseSeeder); no reconstruir
ni borrar la base. El arranque existente ya ejecuta migrate y db:seed.
Configurar APP_URL con el dominio público del backend y FRONTEND_URL con el de
Angular cuando se integre: se usan para bannerUrl, shareUrl y validationUrl.

## Comandos útiles

```bash
docker compose logs app -f
docker compose exec app php artisan route:list
docker compose exec app php artisan migrate:status
docker compose down
```

`docker compose down` detiene los contenedores sin borrar el volumen de MySQL.

## Pendientes conocidos

- Configurar rate limiting y CORS con orígenes explícitos antes de un despliegue público.
- Actualizar `docs/database/schema.sql`, que aún describe un esquema anterior.
