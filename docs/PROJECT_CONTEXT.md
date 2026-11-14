# Contexto del proyecto para IA

Actualizado: 2026-11-13. Alcance de este checkout: **backend-php de AlpaTeck**.
Lee primero AGENTS.md y este archivo. Después consulta únicamente los archivos
del flujo que vas a cambiar. README.md conserva los ejemplos y contratos completos.
Si una implementación cambia, actualiza este resumen; el código y las migraciones
actuales prevalecen sobre documentos antiguos.

## Producto y alcance

AlpaTeck es una plataforma de venta de entradas. Roles internos: CLIENT
(asistente), ORGANIZER (organizador) y ADMIN (administrador). El frontend Angular
es otro repositorio. La rama demo4 sirve como referencia de contrato. La cola se
integra con un servicio Python independiente cuya configuración se documenta en
su README.

La Épica 1 corresponde conjuntamente a las demos 1 y 2, según confirmación del
usuario. Sus historias son HU-01 a HU-08: registro, login, logout, expiración,
autorización, términos y condiciones, listado y activación/desactivación de usuarios.
Su backend está implementado. Demo 3 agrega HU-09 a HU-16 y HU-21 según su
backlog recortado. Los guards, pantallas, mapas/QR visuales, PDF, interceptor 401
y limpieza del navegador corresponden a Angular y no se verifican aquí.

## Stack y persistencia

- PHP ^8.3; imágenes Docker PHP 8.4. Laravel ^13.17, lock 13.31.0.
- php-open-source-saver/jwt-auth ^2.9, lock 2.9.3. Sin Sanctum ni Passport.
- Desarrollo soportado: Docker Compose, MySQL 8. API localhost:8080/api;
  MySQL localhost:3307. `.env.example` coincide con Compose.
- Render: Dockerfile.render, PostgreSQL mediante DB_CONNECTION=pgsql y DB_URL.
- PHPUnit 12; SQLite en memoria, caché array y claves efímeras en tests.
- `roles → users → client_profiles/organizer_profiles` (perfiles 1:1).
  Email, DNI y RUC únicos. Usuarios tienen `active` y `terms_accepted_at`.
- `users → events → event_zones → tickets`; los tickets también pertenecen a users.
  `ticket_orders` agrupa tickets con snapshots, importes, estado e idempotencia.
  Nuevos: venues/venue_zones, event_banners y favorites (UNIQUE user_id,event_id).
  Tickets tienen snapshots de precio/zona/fecha. Las migraciones son la fuente del
  esquema; docs/database/schema.sql está desactualizado.

## Mapa para localizar cambios

| Flujo | Archivos principales | Pruebas |
|---|---|---|
| Registro/login/logout | AuthController, Requests/Auth, UserResource, User | RegisterTest, LoginTest, LogoutTest |
| Administración de usuarios | AdminUserController, Requests/Admin, AdminUserResource | AdminUserManagementTest |
| JWT y roles | config/auth.php, config/jwt.php, EnsureUserHasRole, bootstrap/app.php | SessionSecurityTest, RoleMiddlewareTest, ApiAuthenticationFailureTest |
| Administrador inicial | AdminUserSeeder, DatabaseSeeder, config/initial_admin.php | AdminUserSeederTest |
| Catálogo/creación/compra/reporte | EventController, Requests/Event, Resources de eventos/tickets/ventas | IndexEventTest, StoreEventTest, PurchaseTicketTest, SalesReportTest |
| Recintos/mapas y favoritos | VenueController, VenueSeeder, VenueResource; FavoriteController | StoreEventTest, FavoriteTest |
| Mis Tickets/validación pública | TicketController, TicketResource, Ticket | TicketReadTest, DemoThreeMigrationTest |
| Compra e idempotencia | OrderController, EventController, OrderResource, Requests/Event | FrontendCompatibilityTest, PurchaseTicketTest, PurchaseLimitTest (main) |
| Cola virtual | VirtualQueueController, Support/VirtualQueue, servicio Python | VirtualQueueTest, test_virtual_queue.py |
| Confirmación por correo | Mail/PurchaseConfirmed, Mail/GmailTransport, config/mail.php, config/services.php | Pruebas de correo en FrontendCompatibilityTest |
| Cotización y compatibilidad Angular | PricingController, EventResource, catálogo JSON | FrontendCompatibilityTest |
| Rutas y despliegue | routes/api.php, render.yaml, bin/deploy.sh, Dockerfile.render | route:list y pruebas HTTP |

Los controladores están en app/Http/Controllers; requests y resources bajo
app/Http. El patrón es Route → FormRequest → Controller → Resource. No existe
capa adicional de servicios/repositorios. Los recursos usan camelCase e IDs string.

## Contrato de la Épica 1

| Endpoint | Acceso | Respuesta |
|---|---|---|
| POST /api/auth/register | Público | 201: token y user; 409 duplicados; 422 formato/T&C/rol inválido |
| POST /api/auth/login | Público | 200: token y user; 401 credenciales incorrectas; 403 cuenta inactiva con contraseña correcta |
| POST /api/auth/logout | JWT de cualquiera de los tres roles | 200 con message; invalida solo el token presentado; 401 si no es válido |
| GET /api/v1/admin/usuarios | ADMIN | 200: array de usuarios, sin administradores; filtros opcionales role y active |
| PATCH /api/v1/admin/usuarios/{id}/estado | ADMIN | 200: usuario actualizado; 403 cuenta ADMIN; 404 usuario inexistente; 422 estado inválido |

- Registro: fullName, email, password, role, acceptedTerms=true; CLIENT requiere
  profile.docNumber (DNI string de 8 dígitos); ORGANIZER requiere organizer.taxId
  (RUC string de 11 dígitos con prefijo 10/15/17/20). ADMIN no tiene autorregistro.
- Password: mínimo 8 caracteres, mayúscula, número y carácter especial; cast hashed.
  Registro activa la cuenta sin confirmación por correo. No hay recuperación de contraseña.
- Login normaliza email. Cuenta inactiva con contraseña correcta: 403 y
  `Tu cuenta está desactivada. Contacta al administrador`. Con contraseña incorrecta,
  401 genérico; no revelar su estado. JWT lleva sub y role, sin nombre/documentos/email.
- JWT_TTL se mide en minutos: predeterminado 5, configurable. Es vigencia desde
  emisión, sin renovación por actividad. Rutas protegidas rechazan expirados con
  401 JSON incluso sin Accept; roles incorrectos reciben 403.
- HU-08: desactivar NO invalida tokens previamente emitidos. Siguen hasta expirar;
  nuevos logins se bloquean. Crear eventos (ORGANIZER) y comprar (CLIENT) requieren
  cuenta activa, incluso con JWT vigente (403). Sus eventos
  publicados y los datos del usuario se conservan.
- Administración: filtros `role=CLIENT|ORGANIZER`, `active=0|1`, combinables.
  Retorna id, fullName, email, role y active booleano; sin perfiles ni contraseñas.
  PATCH recibe solo `{"active":false}` o `{"active":true}`; asignación idempotente.
  No hay alta/eliminación administrativa ni cambios de estado sobre administradores.
- Logout usa blacklist y caché compartida (database en Render); no desactivar
  JWT_BLACKLIST_ENABLED si se necesita revocación. El navegador debe borrar sus datos.
- Perfiles opcionales del frontend y marketingOptIn se conservan con lista explícita
  de campos; detalles en JSON de client_profiles/organizer_profiles. No aceptar roles
  ni verificationStatus dentro de esos datos. Se mantienen DNI/RUC y permisos.

## Demo 3: eventos, favoritos y tickets

Contrato vigente: docs/FRONTEND_COMPATIBILITY.md. Entrega anterior registrada en
docs/DEMO3_IMPLEMENTATION.md. Referencia frontend: demo3, commit 52e027a.

- GET /api/v1/recintos (ORGANIZER): 15 recintos y 50 zonas exactos del catálogo
  Angular en database/data/frontend-venues.json. Aforos de maqueta, nombres exactos,
  slug y geometría auxiliar. No borrar los tres recintos antiguos si ya existen;
  se conservan como históricos y no se muestran en el catálogo nuevo.
- POST /api/events también recibe EventItem JSON: name, venue string, city,
  zonas con name/capacity/sold=0/price y dos imágenes JPEG Base64 distintas.
  Resuelve recinto/zonas en BD y verifica aforos; el dueño procede del JWT.
  JSON exige 24 horas de separación; frontend IDs temporales no son IDs de compra.
- POST /api/events (ORGANIZER activo): multipart con venueId, archivo banner JPEG
  <=2 MiB, mínimo 1920×480/4:1, startsAt futuro y zones[{venueZoneId,basePrice>0}].
  Hereda nombre/dirección/aforos del recinto; exige todas sus zonas. Transacción
  atómica y bloqueo del recinto; un evento por día de Lima, conflicto 409.
  Publicación directa. PUT/PATCH /api/events/{id} devuelve 403 (inmutable).
- GET /api/events: PUBLISHED futuros; category/venue/date (día de Lima). Incluye
  zonas/precios/cupos, venue/mapa, bannerUrl, shareUrl e isFavorite opcional por JWT.
  GET /api/events/{id}: no publicado/inexistente 404. GET .../{id}/banner: JPEG;
  contenido en event_banners (DB), no cargar sus bytes en el catálogo.
- Respuesta predeterminada de /api/events: EventItem de Angular, con venue texto,
  name/city/organizerId/maxPerOrder, price/sold, estados PUBLICADO/AGOTADO/FINALIZADO.
  El objeto venue previo está en venueDetails. `?contract=legacy` devuelve el formato
  anterior; endpoints v1 de favoritos/Mis Eventos conservan su respuesta previa.
  También filtra search/available=true. Segunda imagen en .../{id}/secondary-banner.
- GET /api/v1/eventos/mios (ORGANIZER): solo sus eventos, o [].
- GET/POST/DELETE /api/v1/favoritos (CLIENT): lista propios publicados; POST y
  DELETE reciben {eventId}, asignación idempotente; no cambiar favoritos ajenos.
- POST /api/events/{id}/tickets (CLIENT activo): {items:[{zoneId,quantity}],payment}
  o {zoneId,quantity,payment}. Máximo 6 entre todas las zonas. payment contiene
  cardNumber string 13–19 dígitos, cvv string 3–4 dígitos y expiresAt YYYY-MM-DD futuro.
  Aprobación simulada sin guardar tarjeta/CVV; bloquea usuario y zonas por ID.
  Sin aforo: 409, sin cambios parciales. Respuesta {paymentStatus,quantity,totalPrice,tickets}.
- Una entrada = un Ticket con quantity=1, código TICK-año-16hex y snapshots
  inmutables de precio, zona, evento y fecha. GET /api/v1/tickets y .../{id}
  requieren CLIENT y solo retornan propios (ajeno 404).
- POST /api/orders (CLIENT activo): CreateOrderPayload del frontend, con eventId,
  items, expectedTotal/paymentMethod/paymentResult/idempotencyKey opcionales.
  Recalcula precios de BD en centavos. Máximo seis entradas por compra.
  DECLINED causa 402; precio distinto/stock insuficiente 409, sin escrituras parciales.
  Bloquea usuario y zonas en orden estable. Misma clave/payload devuelve orden previa
  con 200; clave reutilizada con otro payload causa 409. Primera creación 201.
  GET /orders/me y /orders/{id} exponen compras propias; ADMIN puede consultar detalle.
  PATCH /orders/{id} solo ADMIN, CANCELADA sobre CONFIRMADA; restaura stock una vez
  y la validación pública rechaza tickets cancelados con 409. No hay canje de entradas.
- Compatibilidad: /api/admin/users* alias de administración, /admin/events,
  /admin/orders y /admin/metrics exclusivos ADMIN; /organizers/{id}/events propio
  o ADMIN. DELETE eventos solo propietario/ADMIN y sin ventas ni órdenes históricas.
- POST /api/v1/dynamic-price y /pricing/api/v1/dynamic-price: precio base de la zona
  publicada desde BD. No hay reglas dinámicas Demo 4. Compose sirve la misma API en
  puertos 8080 y 8000 porque el frontend consulta su cotización en 8000.
- GET /api/v1/tickets/{code}/validacion y GET /validar/{code}: públicos, sin datos
  del comprador. Código inexistente 404. Cada ticket incluye validationUrl/qrValue
  estable para generar su QR en Angular. PDF e imagen visual del QR son frontend.
- FRONTEND_URL configura shareUrl (/attendee/event/{id}) y validationUrl
  (/validar/{code}); si está vacío se usa APP_URL. Banner siempre usa APP_URL.

## Demo 4: cola virtual

- Solo el ORGANIZER dueño y activo habilita ventas con PATCH
  /api/v1/eventos/{id}/habilitar-compras, en eventos publicados, futuros y con
  cola. Se guarda purchases_enabled_at. El evento expone purchasesEnabled y
  purchasesEnabledAt.
- El CLIENT activo usa POST /api/v1/eventos/{id}/cola, GET con sufijo
  /cola/estado y POST con sufijos /cola/cancelar y /pago/apertura. Se puede
  esperar antes de la habilitación. Angular consulta cada cinco segundos.
- El listado /api/organizers/{organizerId}/events devuelve status=PENDIENTE
  para Angular demo4. La etiqueta efectiva sigue en displayStatus, incluida
  VENCIDO. La API v1 conserva su contrato. El reenvío JSON acepta PENDIENTE.
- Python ordena y firma los pases RS256 de tres minutos. Laravel verifica
  firma, usuario, evento, vigencia y turno activo en ambas rutas de compra.
  El pase viaja en queuePass. Se registra su consumo en queue_purchases junto
  con la orden. Se notifica a Python después del commit y se reintenta al
  operar la cola si falla. La idempotencia sigue devolviendo la orden original.
- POST /api/orders aplica el total sin comisión del checkout Angular demo4
  para eventos con y sin cola. Compras históricas conservan sus importes.
- Las dos rutas de compra envían la confirmación al correo registrado por el
  comprador después del commit. MAIL_MAILER=log deja constancia
  local del mensaje. Si el envío falla, queda registrado el ID de la orden.
  Un reintento idempotente devuelve la orden sin reenviar el correo.
- Para enviar desde la cuenta Gmail de la plataforma, configurar
  MAIL_MAILER=gmail, MAIL_FROM_ADDRESS con la cuenta Google autorizada,
  MAIL_FROM_NAME, GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET y GMAIL_REFRESH_TOKEN.
  Habilitar Gmail API en Google Cloud y autorizar únicamente el alcance
  https://www.googleapis.com/auth/gmail.send con acceso sin conexión.
  Con un cliente OAuth externo en Testing, el refresh token vence en siete
  días. Configurar las credenciales en Environment del backend en Render.
- Configurar VIRTUAL_QUEUE_URL, VIRTUAL_QUEUE_SERVICE_TOKEN y
  VIRTUAL_QUEUE_PASS_PUBLIC_KEY. Python guarda los turnos en memoria.
  Guarda vencimientos y cancelaciones en el archivo SQLite configurado en
  VIRTUAL_QUEUE_OUTCOMES_DB. Mercado los cruza con queue_purchases y calcula
  completados / (completados + vencidos + cancelados) para los eventos filtrados.
  Anti-scalping pendiente.

- Angular abre los pendientes y rechazados del dueño por GET /api/events/{id}
  con JWT. Ese detalle privado incluye los dos banners para editar. La bandeja
  ADMIN entrega URL de imagen firmada, válida cinco minutos mientras el evento
  siga pendiente. Renovar la bandeja para obtener URLs nuevas.
- Mercado acepta from/to/venue/queue y devuelve los campos de Angular junto
  con summary, topVenues, sellOutTime y queueConversion. El reporte por evento
  sigue en /api/events/{id}/sales-report. Angular aún debe conectar ese reporte
  y mostrar ocupación por zona. La ficha todavía no muestra explícitamente
  el estado del evento debajo del título. Logout en Angular sigue siendo local.

La rama Angular demo4 sirve como referencia de contrato y conserva
useMock/useMockAuth=true en el checkout. El frontend desplegado aún no tiene
actualizado el flujo de cola, según el usuario. Las pruebas actuales de cola,
compra y correo en Render se hacen con Postman directamente contra Laravel.

## Operación, pruebas y despliegue

Setup y comandos completos en README. En Docker: migrate --seed es obligatorio
para los roles. INITIAL_ADMIN_NAME/EMAIL/PASSWORD privados crean el admin de forma
idempotente; repetir el seeder no cambia contraseñas. Con los tres vacíos se omite;
configuración parcial falla. El email se normaliza para poder iniciar sesión.

Render ejecuta config/route/view cache, migrate --force y db:seed --force.
Completar INITIAL_ADMIN_* en el panel privado; no inventar credenciales en fuentes.
La Épica 1 no agregó tablas; Demo 3 requiere las cuatro migraciones 2026_10_25_*,
la compatibilidad 2026_10_28_000001 y VenueSeeder (incluido en DatabaseSeeder).
No usar migrate:fresh. La migración de
tickets individualiza ventas antiguas conservando ID/código original, importe y
stock; guarda snapshots y no elimina ventas al revertir columnas.

### Estado observado en Render el 2026-11-13 (Lima)

- Laravel en `https://alpateck-backend.onrender.com`. El [PR #21](https://github.com/ticketing-app-upch/backend-php/pull/21)
  integró `fix/compra-render` (`1369af0`) en `main` con el commit `c67749b`.
  El panel de Render mostró ese commit como `Live`, con `Auto-Deploy` como
  origen del [despliegue](https://dashboard.render.com/web/srv-dbelmuvb9u1s73a5c61g/deploys/dep-dbrsr7lbedkc73bhjd2g).
- Python en `https://pricing-service-python.onrender.com`. Render mostró `main`
  en `53dbc00` como `Live` (PR #1 de `ticketing-app-upch/pricing-service-python`).
  Las cuatro pruebas unitarias de Python y la prueba HTTP de `/health` e ingreso
  autenticado pasaron en Docker local.
- El usuario informó que configuró las variables de entorno en Render. En la
  instancia gratuita de Python, los turnos en memoria y el SQLite local se
  pierden si Render reinicia o suspende el servicio. Para un `503` de cola,
  comprobar `/health` de Python, revisar su log y verificar que Laravel apunte
  a la URL pública del servicio, que ambos tengan el mismo token y que la clave
  pública de Laravel corresponda a la privada de Python.
- En `254d26c` (PR #20), Postman mostró `500 Server Error` en `POST /api/orders`.
  Los logs de Laravel registraron `Undefined variable $requestedQuantity` en
  `OrderController.php:68`. `EventController::purchaseTickets` tenía el mismo
  error. Ambas rutas fallaban antes de crear la orden y programar el correo.
  El PR #21 calcula `$requestedQuantity` en los dos controladores antes de
  comprobar el máximo por orden y el máximo acumulado de seis entradas por
  usuario y evento. Si Postman responde `401 Unauthenticated`, volver a iniciar
  sesión y repetir con un JWT vigente.
- El usuario confirmó una compra de prueba en Render y mostró la recepción de
  `Compra confirmada` en Gmail, con una entrada General para el Festival de Luz
  y Sabor. La captura acredita la entrega del correo. Queda por documentar si esa
  compra usó un pase de cola y si se repitió con la misma clave de idempotencia.
- La verificación previa de la corrección en Docker con SQLite tuvo 82 de 83
  casos aprobados, incluidos límite acumulado, cola y Gmail simulado. El caso
  restante,
  `test_single_zone_test_venue_can_be_published_read_publicly_and_sold_out`,
  recibió 404 al consultar el detalle público de un evento recién creado, que
  requiere aprobación de ADMIN. El fallo ocurrió antes de intentar la compra.

### Prueba de compra desde Postman

1. Enviar `POST /api/auth/login` y usar el campo `token` como `Bearer` en las
   solicitudes del comprador. Enviar JSON con `Accept: application/json`.
2. Consultar `GET /api/events/{id}` para obtener `zones[].id`, disponibilidad y
   `purchasesEnabled`. Si se prueba un evento con cola, usar uno publicado,
   futuro y con compras habilitadas por su organizador. Enviar
   `POST /api/v1/eventos/{id}/cola` y consultar
   `GET /api/v1/eventos/{id}/cola/estado` hasta que devuelva `ADMITTED`.
   Guardar `passToken` y abrir el pago con
   `POST /api/v1/eventos/{id}/pago/apertura` (204).
3. Enviar `POST /api/orders` con `eventId`, `items` (zona y cantidad),
   `paymentMethod=CARD`, `paymentResult=APPROVED` y una `idempotencyKey`
   propia de la prueba. Para eventos con cola, pasar `passToken` en `queuePass`
   antes de que expire. La primera creación debe dar 201. Repetir la misma
   solicitud con la misma clave debe dar 200 con la orden original.
4. Confirmar la orden en `GET /api/orders/me` y buscar `Compra confirmada` en
   el buzón del comprador. Si la orden se creó y el correo no llegó, buscar
   `No se pudo enviar el correo de confirmación de compra` y su `order_id` en
   los logs de Laravel.

Verificación normal: `docker compose exec app php artisan test` y
`docker compose exec app php artisan route:list --path=api`. Todos los tests usan
SQLite :memory:; no ejecutar migraciones de tests contra la BD real. Para sesiones
con varias identidades en un test, limpiar guards y el token JWT cacheado al
simular otra petición; el proceso de PHPUnit reutiliza el contenedor.

Verificación anterior del 2026-10-25 con PHP portátil 8.4.27 autorizado: 207 pruebas y 1040
aserciones aprobadas en SQLite. Los bloqueos concurrentes nativos siguen sin
verificarse en MySQL/PostgreSQL; no inferir esa cobertura de las pruebas SQLite.
Pint y sintaxis de 48 archivos PHP aprobados; 20 rutas API verificadas.
En aquella entrega no se ejecutaron pruebas contra MySQL/PostgreSQL ni se desplegó.
El 2026-10-28: suite en Docker/PHP 8.4.27, 235 pruebas y 1286 aserciones aprobadas
en SQLite; 8 flujos con servicios Angular originales contra API HTTP temporal
aprobados. Cuatro carreras concurrentes en MySQL 8.0.46 aislado aprobadas (última
entrada, idempotencia, multizona y cancelación), sin sobreventa ni doble restauración.
No se probó concurrencia PostgreSQL. No se tocaron las BD habituales ni Render.
Pint aprobado en los 38 archivos PHP modificados; 34 rutas API registradas.
Registros de entregas: EPIC1_IMPLEMENTATION.md y DEMO3_IMPLEMENTATION.md;
consultarlos solo para revisar el alcance y los archivos de cada entrega.

Pendientes generales fuera del alcance: CORS explícito, rate limiting y el SQL
documental antiguo. No instalar paquetes nuevos, tocar producción ni realizar
operaciones destructivas/Git de escritura sin las autorizaciones de AGENTS.md.
