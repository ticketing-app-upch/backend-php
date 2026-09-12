# Backend Ticketing

Backend PHP mínimo para probar la comunicación con MySQL desde Postman. Angular todavía no se conecta.

## Iniciar

Desde la raíz del proyecto:

```powershell
docker compose -f backend\docker-compose.yml up -d --build
```

Ver estado:

```powershell
docker compose -f backend\docker-compose.yml ps
```

La API queda en `http://localhost:8080/api`.

- API PHP: `http://localhost:8080`
- MySQL desde Windows: `localhost:3307`
- MySQL dentro de Docker: `mysql:3306`
- Base: `ticketing_platform`
- Usuario: `ticketing`
- Password: `ticketing`

## Postman

Crear una variable:

```text
baseUrl = http://localhost:8080/api
```

### Health

```http
GET {{baseUrl}}/health
```

Respuesta esperada:

```json
{
  "status": "ok",
  "database": "ok"
}
```

### Login admin

```http
POST {{baseUrl}}/auth/login
Content-Type: application/json
```

```json
{
  "email": "admin@ticketing.com",
  "password": "password123"
}
```

Cuentas disponibles:

| Rol | Correo | Password |
| --- | --- | --- |
| Admin | `admin@ticketing.com` | `password123` |
| Organizador | `organizador@demo.com` | `password123` |
| Cliente | `asistente@demo.com` | `password123` |

### Eventos

```http
GET {{baseUrl}}/events
```

Filtros disponibles:

```http
GET {{baseUrl}}/events?category=Concierto
GET {{baseUrl}}/events?search=parque
```

## Estado actual

Implementado para esta primera etapa:

- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/events`
- Filtros `category` y `search`
- Conexión PDO con MySQL
- Inicialización automática de `schema.sql` y `seed_lima.sql`

Siguiente etapa:

- `GET /api/events/:id`
- Registro de usuarios
- Crear y editar eventos
- `POST /api/orders` con transacción, stock e idempotencia
- Tickets y validación
- Dashboard
