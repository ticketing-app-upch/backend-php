# AGENTS.md — backend-php

Guía operativa para agentes de IA que trabajen en este repositorio. Basada en el estado real del código verificado en el repositorio.

## Propósito y arquitectura actual

API REST de la plataforma de ticketing AlpaTeck. Implementa autenticación (registro) para los roles `CLIENT`, `ORGANIZER` y `ADMIN`, consumida por el frontend Angular — `frontend-angular/FRONTEND_HANDOFF.md` es el contrato autoritativo de payloads, códigos HTTP y reglas de validación.

Patrón por endpoint, ya establecido y a seguir: `Route` → `FormRequest` (validación de formato) → `Controller` (orquestación: duplicados, transacción, respuesta) → `Resource` (forma de salida). No existe una capa de "servicios" ni "actions" separada — la lógica de negocio vive directamente en el controlador porque el proyecto todavía tiene un único endpoint real.

## Stack y versiones verificadas

- PHP `^8.3` (`composer.json`); imagen `php:8.4-cli` en Docker.
- Laravel `laravel/framework` `^13.17`, bloqueado en `v13.31.0` (`composer.lock`).
- MySQL 8.0 (contenedor Docker), Eloquent ORM.
- JWT: `php-open-source-saver/jwt-auth` `^2.9`, bloqueado en `v2.9.3`. No hay Sanctum ni Passport instalados — no asumas esos paquetes.
- Docker / Docker Compose — único método soportado para correr el proyecto (no hay instrucciones ni soporte para correrlo con PHP/Composer nativos del host).

## Estructura relevante

```
app/Http/Controllers/AuthController.php
app/Http/Requests/Auth/RegisterRequest.php
app/Http/Resources/UserResource.php
app/Models/{User,Role,ClientProfile,OrganizerProfile}.php
database/migrations/0001_01_01_0000{00..05}_*.php
database/seeders/{DatabaseSeeder,RoleSeeder}.php
database/factories/UserFactory.php
routes/api.php            (único archivo de rutas de API; registrado en bootstrap/app.php)
config/jwt.php             (publicado y versionado; solo referencias env(), sin secretos)
```

Único endpoint implementado hoy: `POST /api/auth/register`.

## Convenciones existentes

- **Form Requests** en `app/Http/Requests/<Dominio>/` (ej. `Auth/RegisterRequest.php`). Contienen solo reglas de formato y condicionales — nunca chequeos de duplicados contra la base de datos.
- **Resources** en `app/Http/Resources/`. Mapean explícitamente snake_case (columnas) → camelCase (contrato API) y nunca exponen columnas internas (`password`, `role_id`, `accepted_terms_at`, `active`, timestamps).
- **Modelos** usan atributos PHP (`#[Fillable([...])]`, `#[Hidden([...])]`) en vez de propiedades `protected $fillable`/`$hidden` — es el estilo ya establecido en los 4 modelos existentes; mantenerlo en modelos nuevos.
- **Resolución de roles**: siempre por nombre (`Role::where('name', ...)`), nunca IDs hardcodeados.
- **Mass assignment**: el controlador arma los arrays de `create()` explícitamente; nunca `Model::create($request->all())`.
- **Migraciones**: el esquema fundacional usa el prefijo `0001_01_01_0000NN_*` (para ordenar antes que cualquier migración de feature futura con fecha real). No reutilices ese prefijo para tablas nuevas — usa la fecha real.
- **Seeders**: idempotentes (`firstOrCreate`), nunca `create()` directo para catálogos fijos como `roles`.

## Cómo levantar y detener el proyecto (Docker)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
docker compose exec app php artisan migrate --seed
```

`--seed` es obligatorio (no solo `migrate`): `users.role_id` es una FK `NOT NULL` hacia `roles`, que debe estar sembrada primero. El `Dockerfile` detecta si falta `vendor/` (clon nuevo) y corre `composer install` automáticamente antes de arrancar.

```bash
docker compose down       # detener sin borrar datos de MySQL
docker compose down -v    # DESTRUCTIVO: borra también el volumen de MySQL — requiere autorización explícita
```

Backend en `localhost:8080` (interno: 8000); MySQL en `localhost:3307` (interno: 3306). Detalle completo en `README.md`.

## `.env`, `.env.example` y secretos

- `.env` nunca se versiona (gitignorado). `.env.example` sí, y debe reflejar la configuración real de `compose.yaml` (mismo host/puerto/credenciales de MySQL), con placeholders vacíos solo donde hay un secreto real: `APP_KEY=` y `JWT_SECRET=`.
- `JWT_SECRET` se genera con `php artisan jwt:secret` — nunca escribirlo a mano ni copiarlo de otro entorno.
- Antes de modificar `.env.example` o cualquier archivo de configuración, confirmar que ningún valor real (contraseña personal, clave de API externa) quede versionado.

## API y autenticación

- Prefijo `/api` registrado vía el parámetro `api:` en `bootstrap/app.php` — no viene por defecto en un esqueleto Laravel nuevo; si falta, es un bug.
- Guard `api` con driver `jwt` (`config/auth.php`), sobre `User` (implementa `JWTSubject`, claim custom `role`).
- Distinción deliberada de códigos: `422` = validación de formato (la produce automáticamente el `FormRequest`); `409` = conflicto de datos ya existentes (email/documento/RUC duplicado), verificado explícitamente en el controlador antes de la transacción, con un `catch (QueryException)` como red de seguridad ante condiciones de carrera. No reemplazar este patrón por `Rule::unique` sin discutirlo antes — se perdería la distinción 422/409.
- El registro público de `role: ADMIN` está bloqueado a propósito (`in:CLIENT,ORGANIZER`). No lo hagas configurable ni lo "arregles" sin que te lo pidan explícitamente.
- `password` usa el cast `'hashed'` de Eloquent — pasar siempre el valor plano al crear/actualizar un `User`; nunca `Hash::make()` manual (se doble-hashearía).
- No hay `config/cors.php` propio publicado — corre con el default permisivo de Laravel para rutas `api/*`. Es un cambio de seguridad real si se toca: avisar antes.
- No hay rate limiting configurado (`bootstrap/app.php` no llama `$middleware->throttleApi()`).

## Reglas de base de datos

- MySQL 8, InnoDB, `utf8mb4`/`utf8mb4_unicode_ci`.
- `roles` → `users` (FK `role_id`, `restrictOnDelete`) → `client_profiles`/`organizer_profiles` (FK `user_id`, `cascadeOnDelete`, único = relación 1:1).
- Enums de negocio (`doc_type`, `gender`, `org_type`, `verification_status`, `role`) son columnas `string` validadas en la capa de aplicación — deliberadamente no `ENUM` nativo de MySQL. Mantener el criterio para columnas nuevas equivalentes.
- Índices únicos existentes: `users.email`, `client_profiles.(doc_type, doc_number)`, `organizer_profiles.tax_id`. Un campo nuevo con semántica de "documento único" debería seguir el mismo patrón: verificación explícita en el controlador + índice de BD como respaldo, no solo uno de los dos.

## Testing y deuda técnica conocida

- `tests/Feature/ExampleTest.php` tiene `RefreshDatabase` **comentado** — hoy no hay ninguna migración automática de la base de datos de test. Un test nuevo que toque BD lo va a necesitar habilitado primero.
- No existe ningún test para `AuthController::register()` todavía, pese a ser el único endpoint implementado.
- `docs/database/schema.sql` está desactualizado (esquema de una versión anterior del diseño) — no es fuente de verdad.
- Sin rate limiting ni `config/cors.php` explícito (ver arriba).

Lista completa y priorizada en `README.md` → "Pendientes conocidos".

## Mantener cambios pequeños y coherentes

- Un cambio, una responsabilidad — no mezclar una corrección de validación con una refactorización de controlador en el mismo cambio.
- Antes de crear una clase nueva (Resource, Request, Model, etc.), revisar si ya existe un patrón establecido para ese tipo de archivo y seguirlo, en vez de proponer una estructura distinta.
- No agregar capas de abstracción (servicios, repositorios, DTOs) que el proyecto no tiene todavía — el patrón actual (controlador delgado + Request + Resource) es intencionalmente simple para el tamaño real del proyecto.

## Reglas explícitas para agentes

- Inspeccionar el código existente (modelos, migraciones, rutas, controladores relacionados) antes de implementar cualquier cambio — no asumir convenciones.
- No inventar arquitectura, capas o patrones que este repositorio no tiene.
- No modificar archivos fuera del alcance pedido.
- No instalar dependencias nuevas ni introducir tecnologías (paquetes, servicios, herramientas) sin antes explicar la necesidad concreta y obtener autorización explícita.
- No exponer ni versionar secretos (`.env`, `JWT_SECRET`, `APP_KEY`, credenciales) — verificar `.gitignore` antes de crear o modificar cualquier archivo de configuración.
- No modificar contratos existentes de la API (rutas, payloads, códigos de respuesta) sin advertir explícitamente el impacto en el frontend que los consume.
- No ejecutar operaciones destructivas de Docker (`down -v`, `system prune`, eliminar imágenes/volúmenes) sin autorización explícita.
- No modificar el repositorio `frontend-angular` ni ningún otro repositorio desde aquí.
- Al terminar cualquier tarea, informar exactamente qué archivos se modificaron y qué verificaciones se realizaron.

## Git (Recomendación)

Permitido sin pedir autorización: `git status`, `git diff`, `git log`, `git branch` y cualquier otro comando de solo lectura equivalente.

Requieren autorización explícita: `git add`, `git commit`, `git push`, `git pull`, `merge`, `rebase`, `reset`, creación/eliminación/renombrado de ramas, o cualquier otro comando que altere el repositorio local o el remoto.
