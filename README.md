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

`APP_KEY` y `JWT_SECRET` se generan localmente; no deben compartirse ni versionarse. `migrate --seed` crea los roles `ADMIN`, `ORGANIZER` y `CLIENT`, necesarios para el registro. Para crear también la cuenta administradora inicial, completa `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD` en `.env` **antes de levantar los contenedores**. La contraseña debe tener al menos ocho caracteres, una mayúscula, un número y un carácter especial. Si los tres valores están vacíos, se omite la cuenta administradora; si falta solo alguno, el seeder falla. Si los completas con los contenedores ya levantados, ejecuta `docker compose up -d --force-recreate app` y luego `docker compose exec app php artisan db:seed`, para que Docker cargue las nuevas variables. Repetir el seeder no duplica el usuario ni cambia su contraseña; un correo ya asignado a otro rol produce un error. En arranques posteriores basta con `docker compose up -d`.

La API queda en `http://localhost:8080/api`. MySQL se publica en el puerto `3307` del host. Para comprobar que Laravel está activo:

```bash
curl http://localhost:8080/up
```

El cliente HTTP debe usar `http://localhost:8080/api` como URL base. El backend usa la configuración CORS predeterminada de Laravel.

## Registro

`POST /api/auth/register` requiere `fullName`, `email`, `password`, `role`, `acceptedTerms` y el documento del perfil correspondiente. `role` solo admite `CLIENT` u `ORGANIZER`. `acceptedTerms` debe llegar como `true` (HU-06); si falta o es falso, el registro se rechaza con `422` y no se crea ningún registro.

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

## Inicio de sesión

`POST /api/auth/login` recibe correo y contraseña:

```json
{
  "email": "juan@example.com",
  "password": "Secret123!"
}
```

Devuelve `200` con `token` y `user` en la misma forma que el registro. Las credenciales incorrectas o un usuario inactivo devuelven `401`; una solicitud inválida devuelve `422`.

## Esquema y migraciones

Las migraciones de creación (`0001_01_01_000003` a `000005`) crean las tablas de registro, y `2026_10_01_000000` agrega `users.terms_accepted_at`. La estructura resultante es:

| Tabla | Columnas de negocio |
|---|---|
| `users` | `full_name`, `email`, `password`, `role_id`, `active`, `terms_accepted_at` |
| `client_profiles` | `user_id`, `doc_number` (DNI) |
| `organizer_profiles` | `user_id`, `tax_id` (RUC) |

Las tablas también incluyen sus claves primarias y marcas de tiempo. `role_id` y `user_id` son claves foráneas; `users.email`, `client_profiles.doc_number` y `organizer_profiles.tax_id` tienen índices únicos. Una instalación nueva solo necesita `php artisan migrate --seed`: no hay migraciones posteriores que creen y luego eliminen columnas del registro.

`2026_10_01_000000_add_terms_accepted_at_to_users_table` agrega `users.terms_accepted_at` (nullable: el administrador sembrado no pasa por el registro público). En una base existente basta con `php artisan migrate`; no requiere `migrate:fresh`.

**Base existente con el esquema anterior:** Laravel no repite una migración ya ejecutada aunque cambie su archivo. Para reconstruir esas tablas desde las migraciones actuales se necesita `php artisan migrate:fresh --seed`, que **borra todas las tablas y sus datos**; hazlo solo si puedes regenerarlos. La base usada durante este desarrollo ya tiene el esquema final, por lo que no necesita reconstruirse.

## Pruebas

Solo al ejecutar `php artisan test`, `phpunit.xml` sustituye la conexión de MySQL por una base SQLite temporal en memoria. Así las pruebas pueden crear y borrar registros sin modificar la base MySQL de la aplicación. Al terminar las pruebas, la API sigue conectada a MySQL. `tests/TestCase.php` genera claves efímeras para `APP_KEY` y `JWT_SECRET` durante las pruebas, sin depender de secretos reales en `.env`:

```bash
docker compose exec app php artisan test --filter="RegisterTest|RegistrationMigrationTest"
docker compose exec app php artisan test
```

## Comandos útiles

```bash
docker compose logs app -f
docker compose exec app php artisan route:list
docker compose exec app php artisan migrate:status
docker compose down
```

`docker compose down` detiene los contenedores sin borrar el volumen de MySQL.

## Pendientes conocidos

- Ampliar las pruebas específicas de login. Las pruebas de registro cubren el login posterior para ambos roles.
- Configurar rate limiting y CORS con orígenes explícitos antes de un despliegue público.
- Actualizar `docs/database/schema.sql`, que aún describe un esquema anterior.
