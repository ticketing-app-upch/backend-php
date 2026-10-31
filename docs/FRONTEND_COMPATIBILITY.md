# Compatibilidad con frontend-angular demo3

Actualizado: 2026-10-30. Referencia vigente: rama `demo3`, commit `fa96e8f`.
Esta entrega modifica únicamente el backend. No conecta ni despliega los dos
repositorios y no modifica la configuración ni el código fuente de Angular.

## Instalación y contratos

Aplicar la nueva migración y cargar el catálogo desde el entorno Docker habitual:

```bash
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
```

La migración `2026_10_28_000001_add_frontend_contract_support.php` agrega datos de
recintos/eventos, segunda imagen, perfiles opcionales, órdenes y enlaces a tickets.
No reescribe las migraciones anteriores, no borra usuarios ni vuelve a descontar
stock. Las ventas anteriores se conservan como una orden por ticket histórico;
no se inventan agrupaciones que no estaban registradas. La compra multipart
anterior también genera una orden, manteniendo sus importes sin comisión.

Las rutas y permisos están en README y routes/api.php. Se conservan las rutas
anteriores. Los aliases de administración solo listan CLIENT/ORGANIZER y cambian
`active`; no permiten crear/eliminar cuentas ni cambiar roles de administradores.
Los endpoints de métricas, listado global de eventos/órdenes y cancelación son
exclusivos de ADMIN. El ID de organizador en una URL no sustituye la autorización.

## Catálogo y eventos

`database/data/frontend-venues.json` contiene los 15 recintos y 50 zonas tomados
del catálogo original de Angular, más **Recinto de prueba**: ciudad Lima,
slug recinto-prueba, dirección Lima y una zona General de capacidad 1. El total
es de 16 recintos y 51 zonas. El recinto de prueba no agrega una imagen de plano;
mapImage es null y conserva la geometría auxiliar generada por el seeder.
Nombres, direcciones y aforos son de maqueta;
no constituyen aforos oficiales. VenueSeeder conserva IDs al repetir el seeding.
Los tres recintos ficticios anteriores, si ya existen, no se borran: pueden tener
eventos o ventas. El catálogo `/api/v1/recintos` muestra los recintos con slug del
frontend y entrega sus IDs internos, slug, ciudad, mapImage, nombres y aforos.
El frontend sigue dibujando sus propios planos; no se copian imágenes al backend.

La creación JSON admite una o más zonas (`min:1`); deben coincidir con todas las
zonas y aforos del recinto, incluso cuando este solo tiene una zona.
Después de actualizar el código, cargar el catálogo en la base del entorno con
`php artisan db:seed --class=VenueSeeder`; en Docker usar
`docker compose exec app php artisan db:seed --class=VenueSeeder`.
DatabaseSeeder ya incluye VenueSeeder y el arranque de Render ejecuta db:seed.
El pull de Git actualiza el JSON, pero la base necesita ejecutar el seeder.

`GET /api/events` y `GET /api/events/{id}` entregan el EventItem esperado:

- `name`, `venue` string, `city`, `organizerId` string, `publishedAt`, `maxPerOrder`.
- Zonas con `id` string, `name`, `price`, `capacity` y `sold` calculado del stock.
- Estados `PUBLICADO`, `AGOTADO`, `FINALIZADO` y `BORRADOR` (este último en administración).
- URLs de banner y segunda imagen; el catálogo no carga sus bytes de la BD.
- Filtros combinables `search`, `category`, `available=true`, y los anteriores `venue`/`date`.

La BD conserva PUBLISHED/DRAFT. Campos de apoyo como title, venueName, basePrice
y availableCapacity continúan presentes. El objeto anterior `venue` pasa a
`venueDetails`; para obtener el formato anterior completo se usa `?contract=legacy`.
Los endpoints v1 de Mis Eventos y favoritos mantienen el formato anterior.

`POST /api/events` admite el EventItem JSON real del formulario:

```json
{
  "id": "",
  "name": "Concierto de prueba",
  "description": "Descripción completa del evento de prueba.",
  "category": "CONCIERTO",
  "status": "PUBLICADO",
  "venue": "Arena 1 — Costa Verde",
  "city": "Lima",
  "venueCapacity": 2800,
  "startsAt": "2027-06-01T01:00:00Z",
  "organizerId": "7",
  "maxPerOrder": 6,
  "imageUrl": "/mock/event.jpg",
  "bannerUrl": "data:image/jpeg;base64,...",
  "secondaryBannerUrl": "data:image/jpeg;base64,...",
  "zones": [
    {"id":"venue-arena-1-0","name":"General","capacity":2000,"sold":0,"price":100},
    {"id":"venue-arena-1-1","name":"VIP","capacity":800,"sold":0,"price":150}
  ]
}
```

Los Base64 del ejemplo deben reemplazarse por imágenes JPEG reales de hasta
2 MiB, mínimo 1920×480 y proporción 4:1, distintas entre sí. El backend resuelve
el recinto y todas sus zonas por sus nombres exactos, verifica ciudad/aforos y
genera los IDs persistentes de evento/zona que se usarán en compras. No utiliza
organizerId para elegir al dueño ni acepta ventas históricas enviadas por el cliente.
publishedAt se fija en el servidor. Los aforos proceden del catálogo de BD.

La creación JSON impide eventos con menos de 24 horas de diferencia en el mismo
recinto, bloqueando su fila durante la transacción. El multipart anterior conserva
su regla por día calendario de Lima y su respuesta previa. PUT/PATCH siguen
rechazando edición. DELETE solo permite al propietario o ADMIN eliminar un evento
sin ventas ni órdenes, incluso si una orden anterior fue cancelada.

## Compras y cotización

`POST /api/orders` acepta el CreateOrderPayload original:

```json
{
  "eventId": "123",
  "items": [{"zoneId":"456","quantity":1}],
  "expectedTotal": 50,
  "paymentMethod": "CARD",
  "paymentResult": "APPROVED",
  "idempotencyKey": "identificador-del-intento"
}
```

Los IDs son los devueltos por la API, no los IDs del mock. El backend comprueba
CLIENT activo, evento futuro publicado, zonas propias, límite del evento (máximo
6) y stock. Desde el 2026-10-30, alineado con Angular demo3, recalcula el importe
en centavos desde los precios finales de BD sin agregar comisión: fee=0,
serviceFee=0 y total=subtotal. Las compras históricas y sus importes se conservan;
los reintentos idempotentes devuelven la compra original. Un expectedTotal distinto causa
409 sin escrituras. La aprobación es simulada; DECLINED devuelve 402 sin compras
ni descuentos de stock. No se reciben ni guardan tarjeta, vencimiento ni CVV en
este endpoint: el frontend actual no los envía.

BUG-008 (2026-10-30): el máximo de 6 también es acumulado por usuario y evento,
sumando entradas confirmadas de todas las zonas, intentos y ambas rutas de compra.
Los tickets históricos sin orden cuentan por quantity. Las órdenes canceladas no
consumen ese cupo. maxPerOrder se mantiene como límite adicional de cada intento.
La cuenta se bloquea antes de consultar el acumulado para serializar compras
simultáneas. Si se supera el cupo, ambas rutas responden 409 con
`{"message":"La compra supera el máximo de 6 entradas por usuario para este evento."}`,
sin crear órdenes/tickets ni descontar stock. Los reintentos con la misma clave y
payload se resuelven antes de esta comprobación y conservan la respuesta original.

La respuesta es TicketOrder: id/code, snapshots de comprador/evento/fecha/recinto,
status CONFIRMADA, lines, tickets individuales con códigos únicos, subtotal, fee
y total. `GET /orders/me` y `GET /orders/{id}` solo exponen compras propias
(ADMIN puede consultar detalle). Los tickets siguen disponibles por las rutas v1.

Idempotencia persistente por usuario y clave: se bloquea primero la cuenta y
después las zonas ordenadas por ID. La misma clave y payload devuelve la orden
previa con 200; un payload distinto causa 409. La primera compra responde 201.
La clave es opcional por compatibilidad con el tipo del frontend; sin ella no se
puede reconocer un reintento. No se confía en buyerId ni precios enviados como extras.

La cancelación ADMIN admite únicamente `{status:"CANCELADA"}` sobre una orden
CONFIRMADA. Bloquea la orden y sus zonas, restaura cupos una sola vez y conserva
los snapshots/tickets. Repetirla devuelve 409. Los códigos cancelados reciben 409
en validación pública y sus ventas no cuentan en reportes/métricas confirmados.
No se implementa canje de entradas ni una pasarela de pago real en esta entrega.

Angular llama a PricingService incluso con la Épica 4 deshabilitada al usar HTTP.
Por eso `POST /api/v1/dynamic-price` devuelve `{precio_ajustado, motivo}` con el
precio base autoritativo de la zona publicada. Ignora precios/aforos recibidos y
no ejecuta reglas dinámicas. El mismo backend se publica localmente en 8080 y
8000 por compose.yaml. También existe `/pricing/api/v1/dynamic-price` para el
prefijo de producción. El hosting deberá dirigir esas URLs al backend; esta
entrega no cambia el proxy ni el despliegue del frontend.

Los 120 segundos de cotización continúan siendo una regla local de Angular;
no hay reserva de stock ni token de cotización transmitido para validarla en el
servidor. Se verifica disponibilidad y total de nuevo al confirmar.

## Perfiles y funciones que siguen en el navegador

Registro/login mantienen `{token,user}`, permisos y códigos de error. El alcance
del registro fue confirmado por el usuario mediante capturas el 2026-10-29:
fullName, email, password, role y acceptedTerms=true; CLIENT requiere
profile.docNumber (DNI de 8 dígitos) y ORGANIZER requiere organizer.displayName
(empresa, 2–255 caracteres) y organizer.taxId (RUC de 11 dígitos).
El nombre de empresa ya llega como displayName desde Angular y se guarda en
organizer_profiles.details. Se ignoran todos los campos adicionales del formulario,
incluidos teléfono, país, género y marketingOptIn. No se guardan ni se devuelven
esos datos en registro/login. Las respuestas usan campos explícitos para excluir
también detalles históricos; las columnas y los datos existentes se conservan.
Una cuenta antigua sin nombre de empresa puede iniciar sesión y devuelve
organizer.displayName=null. No se exponen active, roles internos ni secretos.

En el frontend original no hay HTTP para favoritos, validación pública del QR
ni logout. Esas acciones seguirán siendo locales hasta que su equipo conecte
las llamadas. Sus APIs existentes se conservan y se prueban. Además, useMock y
useMockAuth siguen en true en ambos environments originales; el backend no puede
activar el modo real del cliente. Los reportes del organizador siguen bajo
epicGuard(5), deshabilitado en Demo 3. No se cambia Angular para ocultar estos límites.

## Verificación

- Recinto de prueba (2026-10-30): catálogo de 16 recintos y 51 zonas; seeder
  idempotente conservando IDs. Publicación JSON con una sola zona General de
  capacidad 1, detalle sin autenticación, una venta exitosa y rechazo de la
  siguiente sin crear otra orden ni ticket. Se conserva la exigencia de todas
  las zonas para recintos mayores y se rechaza un evento sin zonas.
  Suite completa: 265 pruebas y 1510 aserciones aprobadas en Docker/PHP 8.4.27,
  SQLite en memoria, sin red; Pint aprobado en los tres archivos PHP modificados.
- BUG-008 (2026-10-30): la prueba nueva reprodujo primero el fallo (segunda
  compra 201 en vez de 409). Tras la corrección, 19 casos de regresión y la suite
  completa aprobados: 262 pruebas y 1474 aserciones en Docker/PHP 8.4.27 con
  SQLite en memoria, sin red; Pint aprobado en los tres archivos PHP cambiados.
  Cinco carreras en MySQL 8.0.46 efímero con dos procesos PHP y peticiones al
  kernel HTTP de Laravel aprobadas: dos compras de 6; rutas mixtas tras comprar 4;
  dos compras legacy tras comprar 5; dos compras de 3 que completan el cupo;
  y reintento concurrente con la misma clave. Se forzó solapamiento durante la
  emisión de tickets; nunca hubo más de 6 confirmados y la séptima entrada
  recibió 409. El reintento idéntico devolvió 200 sin reemitir entradas.
  Base temporal en tmpfs sin puertos publicados ni red externa, retirada al
  terminar; arnés únicamente dentro del contenedor temporal. No se probó
  PostgreSQL ni se modificó el frontend, despliegue o bases habituales.
- Corrección del registro (2026-10-29): suite completa ejecutada directamente
  con PHPUnit en Docker/PHP 8.4.27, sin red y con SQLite en memoria: 242 pruebas
  y 1318 aserciones aprobadas. Se verifica empresa obligatoria, descarte de
  campos adicionales y login de cuentas históricas sin exponer sus detalles
  fuera del contrato ni modificarlos. Pint aprobado en los 7 archivos PHP
  corregidos. No se modificó el frontend ni el despliegue.
- Las verificaciones de integración y concurrencia siguientes corresponden a
  la entrega anterior del 2026-10-28; no se repitieron para esta corrección.
- Suite completa en Docker/PHP 8.4.27 y SQLite en memoria: 235 pruebas,
  1286 aserciones aprobadas, incluyendo migración histórica y rollback de compras.
- Laravel Pint: 38 archivos PHP modificados aprobados; 34 rutas API registradas.
- Copia temporal de Angular: 8 flujos con servicios originales, JWT real y API
  HTTP real aprobados: registro/login, eventos/imágenes/catálogo, recintos,
  cotización, compras/tickets/idempotencia, errores de pago/precio, administración,
  cancelación y eliminación sin historial. No es una prueba visual de las pantallas.
- MySQL 8.0.46 temporal: cuatro carreras HTTP con varios procesos y demoras de
  prueba dentro de la transacción aprobadas: última entrada, reintentos con la
  misma clave, multizona en orden inverso y doble cancelación. No hubo sobreventa,
  duplicación ni restauración doble de aforo. No se probó PostgreSQL concurrente.
- Todo el arnés, datos, credenciales y copia Angular se crearon en `integración`
  para retirarlos al finalizar. No se ejecutaron migraciones contra la BD local
  habitual ni contra Render y no se desplegó esta versión.
