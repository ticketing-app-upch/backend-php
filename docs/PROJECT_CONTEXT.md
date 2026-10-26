# Contexto del proyecto para IA

Actualizado: 2026-10-25. Alcance de este checkout: **backend-php de AlpaTeck**.
Lee primero AGENTS.md y este archivo. Después consulta únicamente los archivos
del flujo que vas a cambiar. README.md conserva los ejemplos y contratos completos.
Si una implementación cambia, actualiza este resumen; el código y las migraciones
actuales prevalecen sobre documentos antiguos.

## Producto y alcance

AlpaTeck es una plataforma de venta de entradas. Roles internos: CLIENT
(asistente), ORGANIZER (organizador) y ADMIN (administrador). El frontend Angular
es otro repositorio y no está incluido aquí. El backlog también contempla una
cola/anti-scalping en Python; ese microservicio no existe en este checkout.

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

## Demo 3: eventos, favoritos y tickets

Contrato completo y archivos de esta entrega: docs/DEMO3_IMPLEMENTATION.md.
Consultar ese documento solo si se trabaja en estos flujos.

- GET /api/v1/recintos (ORGANIZER): tres recintos ficticios de demo y sus zonas,
  aforos y geometrías para renderizar mapas. Los aforos no son editables por API.
- POST /api/events (ORGANIZER activo): multipart con venueId, archivo banner JPEG
  <=2 MiB, mínimo 1920×480/4:1, startsAt futuro y zones[{venueZoneId,basePrice>0}].
  Hereda nombre/dirección/aforos del recinto; exige todas sus zonas. Transacción
  atómica y bloqueo del recinto; un evento por día de Lima, conflicto 409.
  Publicación directa. PUT/PATCH /api/events/{id} devuelve 403 (inmutable).
- GET /api/events: PUBLISHED futuros; category/venue/date (día de Lima). Incluye
  zonas/precios/cupos, venue/mapa, bannerUrl, shareUrl e isFavorite opcional por JWT.
  GET /api/events/{id}: no publicado/inexistente 404. GET .../{id}/banner: JPEG;
  contenido en event_banners (DB), no cargar sus bytes en el catálogo.
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
- GET /api/v1/tickets/{code}/validacion y GET /validar/{code}: públicos, sin datos
  del comprador. Código inexistente 404. Cada ticket incluye validationUrl/qrValue
  estable para generar su QR en Angular. PDF e imagen visual del QR son frontend.
- FRONTEND_URL configura shareUrl (/attendee/event/{id}) y validationUrl
  (/validar/{code}); si está vacío se usa APP_URL. Banner siempre usa APP_URL.

Demo 4 pendiente: cola/turnos/anti-scalping, habilitar compras, aprobación/rechazo,
edición de rechazados y correo de confirmación. El reporte del organizador
propietario ya existía y se conserva; no se amplió con esos flujos.

## Operación, pruebas y despliegue

Setup y comandos completos en README. En Docker: migrate --seed es obligatorio
para los roles. INITIAL_ADMIN_NAME/EMAIL/PASSWORD privados crean el admin de forma
idempotente; repetir el seeder no cambia contraseñas. Con los tres vacíos se omite;
configuración parcial falla. El email se normaliza para poder iniciar sesión.

Render ejecuta config/route/view cache, migrate --force y db:seed --force.
Completar INITIAL_ADMIN_* en el panel privado; no inventar credenciales en fuentes.
La Épica 1 no agregó tablas; Demo 3 requiere cuatro migraciones 2026_10_25_* y
VenueSeeder (incluido en DatabaseSeeder). No usar migrate:fresh. La migración de
tickets individualiza ventas antiguas conservando ID/código original, importe y
stock; guarda snapshots y no elimina ventas al revertir columnas.

Servicio conocido: https://alpateck-backend.onrender.com. El 2026-10-25 se verificó
200 en /up y /api/events y un despliegue exitoso del commit b9e7446. Los cambios
de la Épica 1 y Demo 3 se entregan en la rama feat/demo_3. Main no se modificó;
publicar esa rama no la integra a main ni despliega Render automáticamente.
No confundir el servicio ya existente con esta nueva versión.

Verificación normal: `docker compose exec app php artisan test` y
`docker compose exec app php artisan route:list --path=api`. Todos los tests usan
SQLite :memory:; no ejecutar migraciones de tests contra la BD real. Para sesiones
con varias identidades en un test, limpiar guards y el token JWT cacheado al
simular otra petición; el proceso de PHPUnit reutiliza el contenedor.

Verificado el 2026-10-25 con PHP portátil 8.4.27 autorizado: 207 pruebas y 1040
aserciones aprobadas en SQLite. Los bloqueos concurrentes nativos siguen sin
verificarse en MySQL/PostgreSQL; no inferir esa cobertura de las pruebas SQLite.
Pint y sintaxis de 48 archivos PHP aprobados; 20 rutas API verificadas.
No se ejecutaron pruebas contra MySQL/PostgreSQL ni se desplegó esta versión.
Registros de entregas: EPIC1_IMPLEMENTATION.md y DEMO3_IMPLEMENTATION.md;
consultarlos solo para revisar el alcance y los archivos de cada entrega.

Pendientes generales fuera del alcance: CORS explícito, rate limiting y el SQL
documental antiguo. No instalar paquetes nuevos, tocar producción ni realizar
operaciones destructivas/Git de escritura sin las autorizaciones de AGENTS.md.
