# Demo 3 — backend de las Épicas 2 y 3

> Este documento registra la entrega del 2026-10-25. La adaptación posterior al
> frontend `demo3` está en [FRONTEND_COMPATIBILITY.md](FRONTEND_COMPATIBILITY.md)
> y prevalece para JSON de eventos, catálogo de recintos y órdenes.

Fecha: 2026-10-25. Alcance: HU-09, HU-10, HU-11, HU-12, HU-13, HU-14,
HU-15, HU-16 y HU-21, según Ticketing_Backlog_Demo3.xlsx. Solo backend.
La rama de entrega es feat/demo_3 e incluye los cambios de la Épica 1.
No se integró a main ni se desplegó esta versión en Render.

## Contratos e impacto en Angular

### Recintos, mapas y eventos

- `GET /api/v1/recintos`: JWT ORGANIZER; tres recintos demostrativos con id, name,
  address, capacity, map y zones. Cada zona tiene id, name, capacity y shape
  (rectángulo/color en viewBox `0 0 1000 620`). No son aforos de recintos reales.
- `POST /api/events`: JWT ORGANIZER activo y **multipart/form-data**. Campos:
  title, description, category, startsAt, endsAt opcional, venueId y archivo banner.
  El array zones contiene un precio para cada zona del recinto, sin omisiones,
  duplicados ni zonas ajenas. Obtener IDs reales del catálogo de recintos.

```json
{
  "title": "Concierto Demo 3",
  "description": "Descripción del evento",
  "category": "CONCIERTO",
  "venueId": 1,
  "startsAt": "2027-01-20T19:00:00-05:00",
  "zones": [
    {"venueZoneId": 1, "basePrice": 120.50},
    {"venueZoneId": 2, "basePrice": 80},
    {"venueZoneId": 3, "basePrice": 40}
  ]
}
```

Estos campos se envían como campos multipart, por ejemplo
`zones[0][venueZoneId]` y `zones[0][basePrice]`, junto al archivo `banner`.
El JSON anterior ilustra su estructura, no reemplaza la subida del archivo.
Banner: JPEG real .jpg/.jpeg, máximo 2 MiB, mínimo 1920×480 y proporción 4:1.
El backend verifica también dimensiones/proporción, además de MIME y tamaño.

El backend hereda nombre/dirección/aforos y nombres de zona del recinto. Enviar
venueName, venueAddress, venueCapacity, zones.*.name o zones.*.capacity se rechaza
con 422. Cada precio debe ser mayor a cero, con hasta dos decimales y dentro de
la precisión de la BD. El evento y sus zonas/banner se insertan atómicamente.
El evento queda PUBLISHED y visible inmediatamente; no hay estado de revisión.

No solapamiento: un recinto admite un evento por día calendario de America/Lima;
dos fechas de días contiguos sí están permitidas. La fila del recinto se bloquea
durante esta comprobación para serializar creaciones simultáneas. Conflicto: 409.

- `GET /api/events`: público; solo PUBLISHED futuros, filtros category, venue y
  date (`YYYY-MM-DD` interpretado como día de Lima). Incluye bannerUrl, shareUrl,
  recinto/mapa, zonas/cupos e isFavorite (personalizado si llega un JWT CLIENT).
- `GET /api/events/{id}`: solo publicados; inexistente/no publicado: 404,
  `{"message":"Evento no disponible"}`. La protección de la pantalla es de Angular.
- `GET /api/events/{id}/banner`: JPEG público solo si el evento está publicado.
  Los bytes viven en event_banners dentro de la BD para persistir en Render;
  no se cargan en el catálogo ni requieren storage:link o servicios adicionales.
- `GET /api/v1/eventos/mios`: JWT ORGANIZER; solo sus eventos, o `[]`.
- `PUT/PATCH /api/events/{id}`: JWT ORGANIZER; evento existente: 403, inmutable.
- shareUrl usa FRONTEND_URL + `/attendee/event/{id}`. Copiar el enlace, guards y
  aviso visual corresponden a Angular; no hay tabla de enlaces compartidos.

### Favoritos

Rutas solo CLIENT, con JWT. Sin JWT: 401; otro rol: 403.

- `POST /api/v1/favoritos`, cuerpo `{"eventId":1}`: asigna favorito, responde
  `{"eventId":"1","isFavorite":true}` con 200. Idempotente y con UNIQUE(user_id,event_id).
  El evento debe existir y estar publicado (404 en caso contrario).
- `DELETE /api/v1/favoritos`, mismo cuerpo: elimina solo el favorito del usuario
  autenticado, responde isFavorite=false con 200; también es idempotente.
- `GET /api/v1/favoritos`: eventos publicados guardados por ese usuario; `[]`
  cuando no hay resultados. No devuelve favoritos ajenos ni eventos no publicados.

### Compra y tickets individuales

`POST /api/events/{id}/tickets`: JWT CLIENT, cuenta revalidada como activa durante
la transacción. Evento publicado y futuro. Formato multizona:

```json
{
  "items": [{"zoneId": 1, "quantity": 2}, {"zoneId": 2, "quantity": 1}],
  "payment": {"cardNumber": "4111111111111111", "cvv": "123", "expiresAt": "2027-12-31"}
}
```

Datos de tarjeta únicamente demostrativos: no hay pasarela ni cargos. También se
admite `{zoneId, quantity, payment}` para una sola zona; no mezclar ambos formatos.
Máximo **6 entradas entre todas las zonas**, zonas distintas y pertenecientes al
evento. Tarjeta string de 13–19 dígitos, CVV string de 3–4 dígitos y expiresAt
`YYYY-MM-DD` estrictamente posterior a la fecha actual del sistema. Datos inválidos: 422.

El backend simula aprobación de datos válidos. Bloquea al comprador y las zonas
en orden de ID dentro de una transacción, comprueba todo el aforo, descuenta cupos
y emite N tickets para N entradas. No guarda ni devuelve tarjeta o CVV.
Falta de cupos: 409 `{"message":"Aforo insuficiente para la cantidad solicitada"}`,
sin descuentos ni tickets parciales. Cuenta desactivada: 403, incluso con JWT vigente.

Respuesta 201: `{paymentStatus:"APPROVED", quantity, totalPrice, tickets:[...]}`.
Cada ticket tiene quantity=1, código único `TICK-2026-<16 hex>`, precio individual,
evento/zona/fecha conservados como snapshots y validationUrl/qrValue propios.
Esto reemplaza la antigua respuesta de un único registro agregado por compra.
Los valores enviados por el cliente no pueden cambiar precio ni comprador.

- `GET /api/v1/tickets`: JWT CLIENT; solo entradas propias, una por fila.
- `GET /api/v1/tickets/{id}`: JWT CLIENT propietario; ajeno/inexistente: 404.
- `GET /api/v1/tickets/{code}/validacion`: público, devuelve message
  `Confirmación de compra` y ticket sin identidad/documentos ni datos de pago.
  Desconocido: 404 `{"message":"Ticket no encontrado"}`.
- `GET /validar/{code}`: alias público JSON. validationUrl usa FRONTEND_URL si está
  configurado; si no, APP_URL. qrValue es esa misma URL estable, lista para que
  Angular dibuje el QR. La imagen del QR y el PDF en navegador son del frontend.

## Migración y operación

Cuatro migraciones nuevas `2026_10_25_*`: recintos/zonas, relación de eventos y
banners, favoritos y snapshots de tickets. No se reescribieron las migraciones
originales. `migrate --seed` crea los tres recintos mediante VenueSeeder, incluido
en DatabaseSeeder. Repetir el seeding no cambia los recintos existentes.

Ventas anteriores: la migración individualiza quantity>1, conserva ID/código de
la primera entrada, genera las restantes, reparte céntimos conservando el total
y no altera cupos. Guarda snapshots históricos disponibles en ese momento.
Los códigos antiguos siguen validando aunque no tengan el formato nuevo.
Rollback retira columnas pero conserva las ventas ya individualizadas.

```bash
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
```

No ejecutar migrate:fresh ni borrar datos. Render ya corre migrate y db:seed al
arrancar. Configurar APP_URL con el dominio público del backend y FRONTEND_URL
con el de Angular para los enlaces. Nuevas migraciones y recintos no están en
producción hasta realizar publicación/despliegue autorizados.

## Verificación

- Suite completa: **207 pruebas, 1040 aserciones, todas aprobadas** con PHP
  portátil 8.4.27 autorizado y SQLite :memory:.
- Incluye permisos, banners, fechas, favoritos, pago, límites, rollback, stock,
  snapshots, propiedad de tickets, validación pública y migraciones up/down.
- Pint aprobado y sintaxis PHP verificada en los 48 archivos PHP cambiados desde
  main (incluidos los de la entrega previa); 20 rutas API y el alias público verificados.
- git diff --check sin errores de espacios.
- La prueba de stock entre compradores es secuencial. SQLite no ejerce los
  bloqueos SELECT FOR UPDATE de MySQL/PostgreSQL; la concurrencia paralela nativa
  queda pendiente de verificarse en esos motores.
- Sin dependencias nuevas ni cambios de versiones en Composer.

## Fuera de esta entrega (Demo 4)

No se implementan cola/turnos, risk-score/anti-scalping Python, habilitar compras,
aprobación/rechazo administrativo, edición/reenvío de rechazados ni correo de compra.
El reporte de ventas que ya existía se conserva; no se amplió su alcance.

## Archivos de esta entrega

Modificados: .env.example, AGENTS.md, README.md, docs/PROJECT_CONTEXT.md;
app/Http/Controllers/EventController.php; app/Http/Requests/Event/StoreEventRequest.php,
app/Http/Requests/Event/PurchaseTicketRequest.php; app/Http/Resources/EventResource.php,
app/Http/Resources/EventZoneResource.php, app/Http/Resources/TicketResource.php;
app/Models/Event.php, app/Models/EventZone.php, app/Models/Ticket.php, app/Models/User.php;
database/seeders/DatabaseSeeder.php; routes/api.php, routes/web.php;
tests/Feature/StoreEventTest.php.

Nuevos: app/Models/Venue.php, app/Models/VenueZone.php, app/Models/EventBanner.php;
app/Http/Controllers/VenueController.php, app/Http/Controllers/FavoriteController.php,
app/Http/Controllers/TicketController.php; app/Http/Requests/Event/FavoriteRequest.php;
app/Http/Resources/VenueResource.php; config/ticketing.php; database/seeders/VenueSeeder.php;
database/migrations/2026_10_25_000001_create_venues_and_venue_zones_tables.php,
database/migrations/2026_10_25_000002_add_predefined_venues_and_banners_to_events.php,
database/migrations/2026_10_25_000003_create_favorites_table.php,
database/migrations/2026_10_25_000004_add_ticket_snapshots.php;
tests/Concerns/BuildsDemoThreeFixtures.php; tests/Feature/FavoriteTest.php,
tests/Feature/PurchaseTicketTest.php, tests/Feature/TicketReadTest.php,
tests/Feature/DemoThreeMigrationTest.php; tests/Fixtures/banner.jpg,
tests/Fixtures/small-banner.jpg; docs/DEMO3_IMPLEMENTATION.md.
