# Contrato Frontend - Backend

Este documento fija el contrato antes de implementar la API. Angular nunca accede directamente a MySQL. La API usa JSON en `camelCase`; la base de datos usa nombres internos en `snake_case`.

## Convenciones

- Base URL: `/api`
- Autenticación: `Authorization: Bearer <JWT>`
- Fechas: ISO 8601 con zona horaria, por ejemplo `2026-10-10T20:00:00-05:00`.
- Moneda: `PEN`.
- IDs: strings en JSON. La API puede convertirlos a BIGINT internamente.
- El backend siempre recalcula disponibilidad, precios y totales.
- Nunca se acepta `buyerId` desde el cliente; se obtiene del JWT.

## Estados públicos

| Dominio | Valores JSON |
| --- | --- |
| Rol | `ADMIN`, `ORGANIZER`, `CLIENT` |
| Evento | `BORRADOR`, `PUBLICADO`, `AGOTADO`, `FINALIZADO` |
| Orden | `PENDIENTE`, `CONFIRMADA`, `CANCELADA`, `RECHAZADA` |
| Pago | `PENDING`, `APPROVED`, `DECLINED`, `REFUNDED` |
| Ticket | `EMITIDO`, `VALIDADO`, `ANULADO` |

## Autenticación

### `POST /auth/login`

Request:

```json
{ "email": "cliente@demo.com", "password": "password123", "remember": true }
```

Response `200`:

```json
{
  "token": "jwt",
  "user": {
    "id": "3",
    "fullName": "María García Asistente",
    "email": "cliente@demo.com",
    "role": "CLIENT",
    "marketingOptIn": false
  }
}
```

### `POST /auth/register`

Debe aceptar el `RegisterPayload` de `src/app/core/models/user.model.ts`. Nunca se permite registrar públicamente el rol `ADMIN`. El organizador nuevo queda `PENDING` hasta ser verificado.

## Eventos

### `GET /events`

Query parameters opcionales:

- `search`
- `category`
- `available=true`
- `dateFrom`
- `dateTo`
- `venue`

Response: `EventItem[]` compatible con `src/app/core/models/event.model.ts`.

Cada zona debe devolver:

```json
{
  "id": "12",
  "name": "Platea",
  "price": 180,
  "capacity": 300,
  "sold": 214
}
```

`available` no se persiste en el frontend; se calcula como `capacity - sold`. En la BD, `sold` se deriva de `aforo_maximo - aforo_disponible`.

### `GET /events/:id`

Devuelve eventos publicados o agotados para usuarios públicos. Los borradores solo son visibles para su organizador y administradores.

### `POST /events` y `PUT /events/:id`

Solo `ORGANIZER` propietario o `ADMIN`.

Reglas obligatorias:

- `maxPerOrder` entre 1 y 6.
- Al menos una zona.
- Nombres de zona únicos por evento.
- La suma de capacidades de las zonas no supera la capacidad física del recinto.
- No reducir una zona por debajo de sus entradas vendidas.
- No modificar ni eliminar una zona con ventas de forma destructiva.
- Un organizador pendiente no puede publicar eventos.

## Cotización

El frontend puede consultar el microservicio de precios, pero el servicio de órdenes debe volver a calcular y comprobar el precio antes de confirmar.

Request al pricing service: `POST /v1/dynamic-price`.

```json
{
  "id_zona": "12",
  "aforo_total": 300,
  "aforo_disponible": 86,
  "fecha_evento": "2026-10-10T20:00:00-05:00",
  "fecha_publicacion": "2026-08-01T10:00:00-05:00",
  "precio_base": 180
}
```

Response mínima:

```json
{ "precio_ajustado": 216, "motivo": "Últimos cupos" }
```

## Órdenes

### `POST /orders`

Request compatible con `CreateOrderPayload`:

```json
{
  "eventId": "12",
  "items": [{ "zoneId": "31", "quantity": 2 }],
  "expectedTotal": 381.60,
  "paymentMethod": "CARD",
  "paymentResult": "APPROVED",
  "idempotencyKey": "uuid"
}
```

El servicio debe:

1. Validar JWT y rol `CLIENT`.
2. Validar la clave idempotente.
3. Abrir transacción.
4. Bloquear cada zona con `SELECT ... FOR UPDATE`.
5. Recalcular precio y comisión del 6 %.
6. Comparar `expectedTotal` con tolerancia de centavos.
7. Validar máximo por orden y stock.
8. Descontar disponibilidad.
9. Insertar orden, detalle, pago y un ticket por cada entrada.
10. Confirmar con `COMMIT` o revertir todo con `ROLLBACK`.

Repetir la misma `idempotencyKey` debe devolver la orden original, no crear otra.

### Respuesta `200/201`

Debe ser compatible con `TicketOrder`:

```json
{
  "id": "91",
  "code": "TKT-4F9A2C",
  "eventId": "12",
  "eventName": "Sinfonía bajo las estrellas",
  "eventStartsAt": "2026-10-10T20:00:00-05:00",
  "eventVenue": "Parque de la Exposición, Lima",
  "buyerId": "3",
  "buyerName": "María García Asistente",
  "createdAt": "2026-09-12T18:00:00-05:00",
  "status": "CONFIRMADA",
  "lines": [],
  "subtotal": 360,
  "fee": 21.6,
  "total": 381.6
}
```

## Tickets y validación

- `GET /orders/me`: órdenes del usuario autenticado.
- `GET /orders/:id`: propietario o administrador.
- `POST /tickets/:id/validate`: organizador autorizado o administrador.
- Un ticket validado no puede volver a utilizarse.
- La operación de validación debe ser atómica y tener una restricción única por ticket.
- Los tickets confirmados no tienen `PUT`, `PATCH` ni `DELETE` público.

## Dashboard y administración

- `GET /organizers/:id/dashboard`: propietario o administrador.
- `GET /admin/users`: administrador.
- `PATCH /admin/users/:id`: cambiar rol.
- `DELETE /admin/users/:id`: solo si no tiene historial transaccional.
- `GET /admin/events`: administrador.
- `GET /admin/orders`: administrador.
- `PATCH /orders/:id`: cancelar únicamente órdenes confirmadas no redimidas.

## Errores mínimos

| HTTP | Uso |
| --- | --- |
| `400` | Request mal formado |
| `401` | JWT ausente, inválido o vencido |
| `402` | Pago rechazado |
| `403` | Rol o propietario no autorizado |
| `404` | Recurso inexistente |
| `409` | Stock, precio, idempotencia o estado incompatible |
| `422` | Validación de datos |

Formato recomendado:

```json
{
  "code": "STOCK_CHANGED",
  "message": "La disponibilidad cambió. Actualiza tu selección.",
  "details": {}
}
```
