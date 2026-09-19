# AlpaTeck — Backend (Laravel)

API REST del backend de la plataforma de ticketing. Implementa autenticación (registro/login) para los roles `CLIENT`, `ORGANIZER` y `ADMIN`, consumida por el frontend Angular según el contrato en `frontend-angular/FRONTEND_HANDOFF.md`.

## Stack

- PHP 8.4 + Laravel 13
- MySQL 8 (Eloquent ORM)
- Autenticación JWT (`php-open-source-saver/jwt-auth`)
- Docker / Docker Compose

## Estado actual

Implementado y probado (Demo 1 / Épica 1):

- `POST /api/auth/register` — registro de `CLIENT` y `ORGANIZER` (el registro público de `ADMIN` está explícitamente bloqueado). Valida documento/RUC según tipo, hashea la contraseña, crea el perfil correspondiente y devuelve un JWT con el claim `role`.
- `POST /api/auth/login` — inicio de sesión con email y contraseña. Devuelve un JWT y los datos del usuario. Rechaza usuarios inactivos.
- Esquema de base de datos: `roles`, `users`, `client_profiles`, `organizer_profiles`.
- Guard JWT (`api`) configurado sobre el modelo `User`.

Pendiente (no implementado todavía): endpoints de eventos/compras/dashboard, tests automatizados, rate limiting y CORS explícito (ver [Pendientes conocidos](#pendientes-conocidos)).

## Requisitos previos

- Docker y Docker Compose instalados. No hace falta PHP, Composer ni MySQL en tu máquina — todo corre dentro de los contenedores.

## Cómo levantar el backend (primera vez)

```bash
git clone <url-del-repo>
cd backend-php
cp .env.example .env
docker compose up -d --build
```

El `Dockerfile` detecta automáticamente si falta `vendor/` (por ejemplo, en un clon nuevo) y ejecuta `composer install` antes de arrancar el servidor — no hace falta ningún paso manual de Composer.

Con los contenedores arriba, corré estos tres comandos **una sola vez**:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
docker compose exec app php artisan migrate --seed
```

- `key:generate` — `.env.example` trae `APP_KEY` vacío a propósito; cada quien genera el suyo, no se comparte.
- `jwt:secret` — mismo caso con `JWT_SECRET`; es la clave que firma los JWT, nunca se versiona.
- `migrate --seed` — **importante que sea con `--seed`**, no solo `migrate`: la tabla `roles` necesita estar sembrada (`ADMIN`/`ORGANIZER`/`CLIENT`) antes de poder registrar cualquier usuario, porque `role_id` es una foreign key obligatoria.

Verificar que quedó arriba:

```bash
curl http://localhost:8080/up
```

Debería responder `200`. A partir de ahí, `http://localhost:8080/api/auth/register` queda disponible.

## Arranques posteriores

Una vez hecho el setup inicial, para las siguientes veces alcanza con:

```bash
docker compose up -d
```

(`key:generate`, `jwt:secret` y `migrate --seed` no hace falta repetirlos — sus resultados ya quedaron en tu `.env` y en la base de datos.)

## Puertos

| Servicio | Puerto interno | Puerto en tu máquina |
|---|---|---|
| Laravel (`app`) | 8000 | **8080** |
| MySQL (`db`) | 3306 | 3307 |

El backend escucha internamente en 8000; Docker lo expone en 8080 para coincidir con `apiBackendUrl` del frontend Angular (`src/enviroments/enviroment.ts`).

## Conectar el frontend

En `frontend-angular/src/enviroments/enviroment.ts`, con `useMock: false` y `apiBackendUrl: 'http://localhost:8080/api'` ya apunta a este backend. Nota: CORS todavía no está configurado explícitamente (ver pendientes) — hoy funciona por el default permisivo de Laravel, pero es una de las primeras cosas a resolver si algo falla ahí.

## Comandos útiles

```bash
# Ver logs del backend
docker compose logs app -f

# Entrar a una consola del contenedor
docker compose exec app bash

# Correr Artisan dentro del contenedor
docker compose exec app php artisan <comando>

# Ver las rutas registradas
docker compose exec app php artisan route:list

# Parar todo (sin borrar datos de MySQL)
docker compose down

# Parar y borrar también los datos de MySQL
docker compose down -v
```

## Probar los endpoints

Base URL: `http://localhost:8080/api`

### POST /api/auth/register

**Registro de CLIENT — caso exitoso (201):**

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "fullName": "Juan Perez",
    "email": "juan@email.com",
    "password": "Secret123!",
    "role": "CLIENT",
    "acceptedTerms": true,
    "marketingOptIn": false,
    "profile": {
      "country": "PE",
      "city": "Lima",
      "district": "Miraflores",
      "hasPeruvianNationality": true,
      "docType": "DNI",
      "docNumber": "12345678",
      "gender": "M",
      "phoneCode": "+51",
      "phone": "987654321"
    }
  }'
```

**Registro de ORGANIZER — caso exitoso (201):**

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "fullName": "Maria Organiza",
    "email": "maria@email.com",
    "password": "Secret123!",
    "role": "ORGANIZER",
    "acceptedTerms": true,
    "marketingOptIn": false,
    "organizer": {
      "orgType": "PERSONA",
      "displayName": "Maria Eventos",
      "taxId": "12345678",
      "repName": "Maria Organiza",
      "phone": "987654321",
      "country": "PE"
    }
  }'
```

**Email duplicado (409):**

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "fullName": "Otro Juan",
    "email": "juan@email.com",
    "password": "Secret123!",
    "role": "CLIENT",
    "acceptedTerms": true,
    "profile": {
      "country": "PE",
      "city": "Lima",
      "hasPeruvianNationality": true,
      "docType": "DNI",
      "docNumber": "87654321",
      "gender": "M",
      "phoneCode": "+51",
      "phone": "911222333"
    }
  }'
```

**Validación fallida (422):**

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "no-es-email",
    "password": "123",
    "role": "INVALIDO"
  }'
```

---

### POST /api/auth/login

**Login exitoso (200):**

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "juan@email.com",
    "password": "Secret123!"
  }'
```

Respuesta:
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": "1",
    "fullName": "Juan Perez",
    "email": "juan@email.com",
    "role": "CLIENT",
    "marketingOptIn": false,
    "profile": {
      "country": "PE",
      "city": "Lima",
      "docType": "DNI",
      "docNumber": "12345678"
    }
  }
}
```

**Credenciales incorrectas (401):**

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "juan@email.com",
    "password": "WrongPassword"
  }'
```

**Email inexistente (401):**

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "noexiste@email.com",
    "password": "Secret123!"
  }'
```

**Validación fallida (422):**

```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "formato-invalido",
    "password": ""
  }'
```

---

### Resumen de códigos HTTP

| Endpoint | Código | Significado |
|---|---|---|
| `POST /api/auth/register` | `201` | Registro exitoso, devuelve token + usuario |
| `POST /api/auth/register` | `409` | Email, documento o RUC ya registrado |
| `POST /api/auth/register` | `422` | Error de validación (formato, campos requeridos) |
| `POST /api/auth/login` | `200` | Login exitoso, devuelve token + usuario |
| `POST /api/auth/login` | `401` | Credenciales incorrectas o usuario inactivo |
| `POST /api/auth/login` | `422` | Error de validación (email inválido, campos requeridos) |

## Pendientes conocidos

Detectados en la revisión técnica previa al commit — no bloquean el uso actual, pero conviene abordarlos pronto:

1. Habilitar `RefreshDatabase` en los tests y escribir tests para `register` y `login` (Feature) que cubran los casos ya validados manualmente.
2. Activar rate limiting en las rutas de la API (`$middleware->throttleApi()` en `bootstrap/app.php`).
3. Configurar `config/cors.php` explícitamente (orígenes concretos, no el default `*`).
4. Actualizar o eliminar `docs/database/schema.sql` (desactualizado, no refleja el esquema real).

## Referencia del contrato

El contrato completo que este backend debe cumplir (payloads, códigos HTTP, reglas de validación) está en `frontend-angular/FRONTEND_HANDOFF.md`.
