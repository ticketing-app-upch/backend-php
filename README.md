# AlpaTeck — Backend (Laravel)

API REST de la plataforma de ticketing. Usa PHP 8.4, Laravel 13, MySQL 8 y autenticación JWT (`php-open-source-saver/jwt-auth`). El entorno de desarrollo se ejecuta con Docker Compose. **La API usa MySQL** mediante la conexión `mysql` configurada en `compose.yaml` y `.env.example`.

## Funciones disponibles

| Método y ruta | Acceso | Función |
|---|---|---|
| `POST /api/auth/register` | Público | Registra un cliente con DNI o un organizador con RUC |
| `POST /api/auth/login` | Público | Inicia sesión con correo y contraseña |
| `GET /api/events` | Público | Lista eventos publicados y futuros |
| `GET /api/events/{id}` | Público | Consulta un evento |
| `POST /api/events` | `ORGANIZER` | Crea un evento con zonas |
| `POST /api/events/{id}/tickets` | `CLIENT` | Compra entradas |
| `GET /api/events/{id}/sales-report` | Organizador propietario | Consulta ventas del evento |

El registro público de `ADMIN` está bloqueado. El guard `api` usa JWT; la contraseña se almacena con hash y el token incluye el rol.

## Instalación

Docker y Docker Compose deben estar instalados. Desde `backend-php`:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
docker compose exec app php artisan migrate --seed
```

En el primer arranque, espera a que termine la instalación de dependencias antes de ejecutar los comandos de Artisan. Puedes consultar su progreso con `docker compose logs --tail 50 app`. Si Artisan muestra que falta `vendor/autoload.php`, revisa esos logs; si la instalación ya terminó o falló y el archivo sigue ausente, ejecuta:

```bash
docker compose exec app composer install --no-interaction --prefer-dist
```

Cuando termine correctamente, repite los comandos de generación de claves y migración pendientes. La carpeta local se monta sobre `/var/www/html`, por lo que el contenedor necesita que `vendor/` esté disponible en esa carpeta; el `Dockerfile` intenta instalar las dependencias automáticamente al arrancar si falta el autoload.

`APP_KEY` y `JWT_SECRET` se generan localmente; no deben compartirse ni versionarse. `migrate --seed` crea los roles `ADMIN`, `ORGANIZER` y `CLIENT`, necesarios para el registro. Para crear también la cuenta administradora inicial, completa `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD` en `.env` **antes de levantar los contenedores**. La contraseña debe tener al menos ocho caracteres, una mayúscula, un número y un carácter especial. Si los tres valores están vacíos, se omite la cuenta administradora; si falta solo alguno, el seeder falla. Si los completas con los contenedores ya levantados, ejecuta `docker compose up -d --force-recreate app` y luego `docker compose exec app php artisan db:seed`, para que Docker cargue las nuevas variables. Repetir el seeder no duplica el usuario ni cambia su contraseña; un correo ya asignado a otro rol produce un error. En arranques posteriores basta con `docker compose up -d`.

La API queda en `http://localhost:8080/api`. MySQL se publica en el puerto `3307` del host. Para comprobar que Laravel está activo:

```bash
curl http://localhost:8080/up
```

El cliente HTTP debe usar `http://localhost:8080/api` como URL base. El backend usa la configuración CORS predeterminada de Laravel.

## Registro

`POST /api/auth/register` requiere `fullName`, `email`, `password`, `role`, `acceptedTerms` y el documento del perfil correspondiente. `role` solo admite `CLIENT` u `ORGANIZER`. `acceptedTerms` debe llegar como el booleano JSON `true` (HU-06); si falta, es falso o tiene otro tipo (por ejemplo, `"true"`, `"yes"`, `"on"` o `1`), el registro se rechaza con `422` y no se crea ningún registro.

### Comparación de campos: antes y ahora

| Parte del registro | Antes | Ahora |
|---|---|---|
| Ambos roles | `fullName`, `email`, `password`, `role`, `acceptedTerms` obligatorio y `marketingOptIn` opcional | `fullName`, `email`, `password`, `role` y `acceptedTerms` obligatorio (se guarda la fecha de aceptación en `users.terms_accepted_at`). Ya no se solicita ni guarda `marketingOptIn` |
| Usuario (`CLIENT`) | `profile.country`, `city`, `district`, `hasPeruvianNationality`, `docType`, `docNumber`, `gender`, `phoneCode` y `phone`; se admitían DNI, CE y pasaporte | Solo `profile.docNumber`: DNI de 8 dígitos |
| Organizador (`ORGANIZER`) | `organizer.orgType`, `displayName`, `taxId`, `legalName`, `repName`, `phone`, `country`, `city` y `website`; según el tipo se admitía RUC o DNI | Solo `organizer.taxId`: RUC de 11 dígitos |

Para quien se registra, los datos son **nombre, correo, contraseña y DNI** si es usuario, o **nombre, correo, contraseña y RUC** si es organizador. El cliente HTTP envía además `role` para que el backend sepa qué perfil crear. Los perfiles conservan solo su documento, `user_id`, clave primaria y marcas de tiempo; `users` conserva también `role_id` y `active` para la autenticación, y `terms_accepted_at` como evidencia de la aceptación de Términos y Condiciones.

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
  "organizer": { "taxId": "20123456789" }
}
```

El DNI debe ser una **cadena de 8 dígitos** y el RUC una **cadena de 11 dígitos** que comience por `10`, `15`, `17` o `20`. Enviarlos como cadenas conserva los ceros iniciales. El backend comprueba el formato del RUC, pero no consulta SUNAT. La contraseña requiere al menos 8 caracteres, una mayúscula, un número y un carácter especial.

`profile.docType` todavía se acepta por compatibilidad si vale `DNI`, pero no se almacena ni se devuelve. Otros campos del registro anterior se ignoran. No se aceptan CE, pasaporte ni DNI como documento de organizador.

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

El perfil del organizador aparece en `user.organizer.taxId`. La respuesta no incluye contraseña ni columnas internas. Los conflictos por correo, DNI o RUC duplicados devuelven `409`; los datos requeridos o formatos inválidos devuelven `422`. Los tres valores tienen índices únicos en MySQL.

### Casos de éxito y error del registro

| Caso | HTTP | Resultado |
|---|---|---|
| Cliente con campos válidos, DNI disponible y T&C aceptados | `201` | Crea usuario y perfil; devuelve `token` y `user.profile.docNumber` |
| Organizador con campos válidos, RUC disponible y T&C aceptados | `201` | Crea usuario y perfil; devuelve `token` y `user.organizer.taxId` |
| Correo ya registrado, incluso con distintas mayúsculas | `409` | `{"message":"El correo ya está registrado."}` |
| DNI ya registrado | `409` | `{"message":"Ese documento ya está registrado."}` |
| RUC ya registrado | `409` | `{"message":"Ese RUC ya está registrado."}` |
| Duplicado detectado por el índice de la BD durante la inserción | `409` | `{"message":"Ese dato ya está registrado."}` |
| Nombre ausente, de menos de 2 caracteres o de más de 255 | `422` | Error de validación en `fullName` |
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

Devuelve `200` con `token` y `user` en la misma forma que el registro. Las credenciales incorrectas o un usuario inactivo devuelven `401`; una solicitud inválida devuelve `422`.

El login está disponible para cuentas activas `CLIENT`, `ORGANIZER` y `ADMIN`. El correo se normaliza a minúsculas. Un correo inexistente, una contraseña incorrecta y una cuenta inactiva reciben el mismo cuerpo de error, sin token ni datos del usuario:

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
| Cuenta inactiva con contraseña correcta | `401` | Mismo mensaje; no entrega un token ni deja al usuario autenticado |
| Correo ausente, inválido o de más de 255 caracteres | `422` | Error de validación en `email` |
| Contraseña ausente, vacía o con un tipo distinto de cadena | `422` | Error de validación en `password` |

El login valida que la contraseña sea una cadena no vacía; la política de contraseña fuerte se aplica al registro. Los campos adicionales `role` y `role_id` se ignoran para autenticar al usuario.

## Autorización por rol

Las rutas protegidas requieren el encabezado `Authorization: Bearer <token>`. El middleware `EnsureUserHasRole` está registrado con el alias `role` en `bootstrap/app.php` y admite uno o varios roles. Para proteger una ruta nueva, utiliza primero `auth:api` y luego los roles permitidos:

```php
Route::middleware(['auth:api', 'role:ORGANIZER,ADMIN'])->group(function () {
    // Registrar aquí las rutas que permiten cualquiera de estos dos roles.
});
```

Este es un ejemplo de reutilización. Las rutas actuales de creación de eventos y reporte de ventas permiten `ORGANIZER`; la compra de entradas permite `CLIENT`. La ausencia de un token válido devuelve `401` y un rol no permitido devuelve `403`.

| Caso en una ruta protegida | HTTP | Cuerpo de respuesta |
|---|---|---|
| Token ausente, inválido o expirado | `401` | `{"message":"Unauthenticated."}` |
| Token válido, pero rol no permitido | `403` | `{"message":"No tienes permiso para acceder a este recurso."}` |

## Respuestas de eventos, entradas y reportes

| Método y ruta | Éxito | Errores previstos |
|---|---|---|
| `GET /api/events` | `200`: lista de eventos publicados y futuros con sus zonas; `[]` si no hay coincidencias | `422` si los filtros son inválidos. Admite `category`, `venue` y `date` con formato `YYYY-MM-DD` |
| `GET /api/events/{id}` | `200`: datos del evento y sus zonas | `404`: `{"message":"Evento no encontrado."}` |
| `POST /api/events` | `201`: crea y devuelve el evento publicado y sus zonas | `401` sin token válido; `403` si no es organizador; `422` por datos inválidos, fecha inicial no futura, fecha final no posterior al inicio, zonas repetidas o suma de capacidades superior a la del recinto |
| `POST /api/events/{id}/tickets` | `201`: devuelve la compra con código, cantidad, precio total, evento y zona; descuenta la capacidad disponible | `401` sin token válido; `403` si no es cliente; `422` por zona inexistente o ajena al evento, cantidad fuera de `1`–`10` o capacidad insuficiente detectada en la validación; `409` si la capacidad disponible resulta insuficiente al confirmar la compra |
| `GET /api/events/{id}/sales-report` | `200`: devuelve `event`, `summary` y `zones`; los totales de ventas e ingresos son cero si no hubo ventas | `401` sin token válido; `403` si no es organizador o no es dueño del evento; `404` si el evento no existe |

En la compra, el error `409` devuelve `{"message":"No hay suficiente capacidad disponible en esa zona."}`. Consultar el reporte de un evento ajeno devuelve `403` con `{"message":"No tienes permiso para consultar este reporte."}`. Una compra rechazada por falta de capacidad durante la transacción no descuenta cupos ni crea la entrada.

### Formato de los errores de validación

Los errores `422` incluyen `message` y un objeto `errors` con una lista de mensajes por campo. Los nombres de campos permiten al frontend asociar cada error a su entrada: por ejemplo, `acceptedTerms`, `profile.docNumber`, `organizer.taxId`, `zones.0.capacity` o `quantity`. El texto de los mensajes depende de las reglas y del idioma configurado en Laravel. Los errores de negocio, como credenciales incorrectas o datos duplicados, contienen `message` y usan los códigos indicados arriba.

## Esquema y migraciones

Las migraciones de creación (`0001_01_01_000003` a `000005`) crean las tablas de registro, y `2026_10_01_000000` agrega `users.terms_accepted_at`. La estructura resultante es:

| Tabla | Columnas de negocio |
|---|---|
| `users` | `full_name`, `email`, `password`, `role_id`, `active`, `terms_accepted_at` |
| `client_profiles` | `user_id`, `doc_number` (DNI) |
| `organizer_profiles` | `user_id`, `tax_id` (RUC) |

Las tablas también incluyen sus claves primarias y marcas de tiempo. `role_id` y `user_id` son claves foráneas; `users.email`, `client_profiles.doc_number` y `organizer_profiles.tax_id` tienen índices únicos. Una instalación nueva solo necesita `php artisan migrate --seed`: no hay migraciones posteriores que creen y luego eliminen columnas del registro.

`2026_10_01_000000_add_terms_accepted_at_to_users_table` agrega `users.terms_accepted_at` (nullable: el administrador sembrado no pasa por el registro público). En una base existente, con los contenedores levantados, basta con aplicar las migraciones pendientes; no requiere `migrate:fresh` ni borra datos:

```bash
docker compose exec app php artisan migrate
```

**Base existente con el esquema anterior:** Laravel no repite una migración ya ejecutada aunque cambie su archivo. Para reconstruir esas tablas desde las migraciones actuales se necesita `php artisan migrate:fresh --seed`, que **borra todas las tablas y sus datos**; hazlo solo si puedes regenerarlos. La base usada durante este desarrollo ya tiene el esquema final, por lo que no necesita reconstruirse.

## Pruebas

Solo al ejecutar `php artisan test`, `phpunit.xml` sustituye la conexión de MySQL por una base SQLite temporal en memoria. Así las pruebas pueden crear y borrar registros sin modificar la base MySQL de la aplicación. Al terminar las pruebas, la API sigue conectada a MySQL. `tests/TestCase.php` genera claves efímeras para `APP_KEY` y `JWT_SECRET` durante las pruebas, sin depender de secretos reales en `.env`:

```bash
docker compose exec app php artisan test --filter="RegisterTest|LoginTest|RegistrationMigrationTest|RoleMiddlewareTest"
docker compose exec app php artisan test
```

| Archivo | Cobertura |
|---|---|
| `tests/Feature/RegisterTest.php` | Registro de ambos roles, cada requisito de contraseña, aceptación estricta de T&C, documentos inválidos, duplicados, bloqueo de `ADMIN` y protección frente a manipulación del payload |
| `tests/Feature/LoginTest.php` | Login correcto de los tres roles, credenciales incorrectas, cuentas inactivas, entradas inválidas, JWT firmado con identificador y rol sin datos personales, e intento de modificar el rol desde el payload |
| `tests/Feature/RegistrationMigrationTest.php` | Columnas del esquema final, índices únicos, claves foráneas y unicidad de DNI/RUC en la base de pruebas |
| `tests/Feature/RoleMiddlewareTest.php` | Acceso permitido y rechazos `401` sin token y `403` con rol no autorizado |

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
