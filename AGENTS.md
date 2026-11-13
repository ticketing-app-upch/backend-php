# AGENTS.md — backend-php

## Entrada rápida

Actualizado el 13/11/2026 para `feature/revision-eventos`, commits `932f669`
y `cb1556f`. Describe código implementado; verificar por separado merge,
despliegue y aceptación de QA. README y documentos Demo 3 pueden conservar
reglas anteriores: para los flujos actualizados consultar las secciones de
Demo 4 de este archivo y contrastar con código/migraciones de la rama actual.

Lee `docs/PROJECT_CONTEXT.md` una vez al iniciar. Contiene el mapa del proyecto,
los contratos y los límites del alcance. Después abre solo el controlador,
request, resource, modelo y pruebas del flujo solicitado. No releas todo el
repositorio ni todo el backlog para cada tarea. Verifica `git status`/`git diff`
antes de editar y actualiza el contexto cuando cambie el comportamiento.

## Arquitectura y convenciones

- API REST AlpaTeck: PHP ^8.3, Laravel 13 (lock: 13.31.0), JWT Auth 2.9.3.
- MySQL 8 en Docker local; PostgreSQL gestionado en Render.
- Patrón: Route → FormRequest → Controller → Resource; negocio en controladores.
  No agregar servicios, repositorios, DTOs ni otras capas sin necesidad acordada.
  Excepción ya incorporada: `app/Queries/MarketDashboardQuery.php` centraliza
  filtros y consultas agregadas de HU-30; reutilizarla para ese flujo.
- Requests en `app/Http/Requests/<Dominio>/`: validación de formato. Duplicados
  se comprueban en el controlador y con índices únicos en la BD.
- `422` para formato inválido; `409` para duplicados y conflictos de negocio
  como reserva de recinto o revisión ya resuelta. Conservar esta diferencia.
- Resources mapean snake_case a camelCase con campos explícitos. Nunca exponer
  passwords, role_id ni secretos. Solo AdminUserResource expone `active` para
  gestión de usuarios; las respuestas de autenticación no lo exponen.
- Modelos usan atributos PHP `#[Fillable]`/`#[Hidden]`; no `$request->all()`.
- Roles por nombre: CLIENT, ORGANIZER, ADMIN; no IDs fijos. ADMIN no se registra
  públicamente. Contraseñas pasan en texto plano al cast `hashed` del modelo.
- Seeders idempotentes. Nuevas tablas usan migraciones con fecha real; no
  reescribir migraciones aplicadas para alterar una BD existente.

## Contratos que deben preservarse

- Rutas existentes `/api/auth/*` y `/api/events*`; administración de usuarios en
  `/api/v1/admin/usuarios*` y sus aliases `/api/admin/users*`, siempre solo ADMIN.
- Login: credenciales incorrectas 401; cuenta inactiva con contraseña correcta
  403 y mensaje de desactivación. JWT contiene `sub` y `role`, sin datos personales.
- HU-04: JWT_TTL en minutos, predeterminado 5. Se valida `exp` en el backend.
- HU-08: desactivar NO revoca tokens abiertos; siguen hasta expirar. No agregar
  un chequeo global de `active`. Crear/reenviar eventos y comprar exigen cuenta activa.
- Administración solo ADMIN; no crear/eliminar usuarios ni cambiar cuentas ADMIN.
- Logout invalida solo el JWT presentado mediante la blacklist compartida.
- Antes de cambiar rutas, payloads o códigos existentes, advertir el impacto en
  el frontend. Revisar README para el contrato completo.
- Demo 3: contrato Angular `demo3` documentado en docs/FRONTEND_COMPATIBILITY.md.
  POST /api/events admite EventItem JSON con imágenes JPEG Base64 y el multipart
  anterior con venueId/banner/venueZoneId. Ahora ambos exigen dos banners;
  multipart usa banner y secondaryBanner, JSON bannerUrl y secondaryBannerUrl.
  Los aforos se validan contra el recinto. Ambos contratos exigen al menos
  24 horas entre eventos del mismo recinto, en creación y reenvío; exactamente
  24 horas se permite. Sustituye la separación por día calendario; acuerdo del
  equipo confirmado el 11/11/2026. El filtro date del catálogo sigue usando Lima.
  GET /api/events responde al frontend; `?contract=legacy` conserva el formato previo.
  Los endpoints v1 de eventos/favoritos mantienen su formato anterior.
  Desde Demo 4 se crea pendiente y ADMIN publica; solo un rechazado puede
  corregirse según HU-25. Solo se eliminan eventos sin historial.
- Compras: pago simulado, máximo 6 entre todas las zonas y un Ticket por entrada.
  Bloquear usuario, evento y filas de zonas en orden estable; guardar snapshots
  inmutables. Revalidar vigencia del evento después de obtener su bloqueo.
- /api/orders agrupa tickets, calcula comisión del 6% en centavos e idempotencia
  persistente por usuario. Reintento idéntico 200; clave con otro payload 409.
  Cancelación solo ADMIN: bloquear orden, evento y zonas; restaurar stock una
  vez y rechazar validación pública. Conservar órdenes, tickets y primer agotamiento.
  El endpoint anterior de compra mantiene su pago/importe sin comisión.
- Catálogo: 15 recintos y 50 zonas en database/data/frontend-venues.json. No borrar
  recintos históricos ni cambiar IDs/nombres de zonas que tengan ventas.
- Cotización Demo 3: /api/v1/dynamic-price devuelve precio base de BD. También
  /pricing/api/v1/dynamic-price; el puerto local 8000 sirve el mismo backend.
  No implementar reglas dinámicas de Demo 4 ni confiar en precios del cliente.
- Banners en event_banners (DB); el catálogo nunca debe cargar su contenido.
- Registro conserva detalles opcionales del perfil en JSON con lista explícita
  de campos; nunca persistir roles/verificationStatus enviados dentro del perfil.
- Detalle histórico de contratos/migraciones Demo 3: docs/DEMO3_IMPLEMENTATION.md.
  La restricción anterior de no agregar aprobación/edición de rechazados quedó
  sustituida por HU-23/24/25 implementadas. Cola, anti-scalping, correos y
  habilitación de compras no se implementaron como parte de este avance.

## Avance de HU y mapa para revisión

### HU-07 y HU-09

- HU-07 ya existía y se conservó: GET /api/v1/admin/usuarios, solo ADMIN,
  lista CLIENT/ORGANIZER con nombre, correo, rol y estado; filtros role/active,
  excluye ADMIN y no incorpora creación/eliminación administrativa de usuarios.
- HU-09 conserva filtros, zonas, precios, disponibilidad, favorito y shareUrl.
  GET /api/events solo incluye PUBLISHED futuros; pendientes/rechazados se
  excluyen y aprobar incorpora el evento vigente. El detalle público exige
  PUBLISHED. Menús, botones y presentación Angular requieren integración visual.

### HU-10, HU-11, HU-23, HU-24 y HU-25

- Ciclo: crear → PENDING_APPROVAL → PUBLISHED o REJECTED; corregir REJECTED
  lo devuelve a PENDING_APPROVAL. DRAFT se mantiene para compatibilidad.
- Solo ORGANIZER activo crea; edición solo dueño activo de REJECTED (otros
  actores/estados: 403). Mantener ID; impedir reescritura si hay órdenes/tickets
  o stock alterado. Validar fecha futura, recinto/zonas y precios positivos.
- Ambos banners obligatorios: JPEG real, máximo 2 MiB, mínimo 1920 x 480,
  proporción 4:1. JSON exige imágenes distintas. Datos de recinto no editables;
  dueño desde JWT. queueEnabled es opcional, boolean y false por defecto.
- Pendientes/publicados reservan el recinto; rechazados no bloquean fecha.
  EventController::persistEvent comparte creación/edición; transacción con
  bloqueo de organizador, recintos anterior/nuevo ordenados y evento al editar.
  Comprobar conflictos con lectura bloqueante para evitar snapshots obsoletos
  en MySQL REPEATABLE-READ. Evento, zonas y dos banners son atómicos.
- Crear deja published_at null. ADMIN aprueba/rechaza solo pendientes futuros;
  rechazo requiere motivo no vacío de máximo 2000 caracteres. Registrar
  reviewed_at/reviewed_by desde servidor. Reenviar limpia revisión/publicación
  anteriores; esos campos no constituyen un historial completo de revisiones.
- Pendiente con starts_at <= now se muestra VENCIDO, sin nuevo estado persistido;
  desaparece de la bandeja ADMIN. Revalidar fecha tras bloqueos: revisión vencida
  409 “El evento ya venció”; ya revisado 409, desconocido 404, sin JWT 401/rol 403.
- HU-11: listado propio, vacío si no tiene eventos; displayStatus y motivo de
  rechazo para dueño/ADMIN. canEdit solo para dueño activo de REJECTED. Reporte
  de ventas solo dueño de PUBLISHED. HU-22 no está implementada aquí.
- ORGANIZER: GET /api/v1/eventos/mios; GET/PUT /api/v1/eventos/{id};
  alias de edición PUT/PATCH /api/events/{id}. Multipart editado mediante POST
  con _method=PUT; JSON usa PUT. Conservar formatos legacy/EventItem existentes.
- ADMIN: GET /api/v1/admin/eventos?estado=pendiente, GET /api/v1/admin/eventos/{id}
  y PATCH /api/v1/admin/eventos/{id}/estado; payload status=PUBLISHED o REJECTED
  y rejectionReason en rechazo. Listado incluye organizador, recinto y cola;
  detalle agrega descripción, dos banners, mapa del recinto y precios por zona.
- Imágenes privadas: sufijos /banner y /secondary-banner sobre detalle propio
  o ADMIN, con JWT/permisos y Cache-Control private, no-store. Angular debe
  pedirlas con Authorization y usar Blob. Publicados conservan las rutas
  públicas /api/events/{id}/banner y /secondary-banner; no cargar Base64 en listas.
- Archivos: EventController, AdminEventController; requests Event/StoreEventRequest,
  Event/UpdateEventRequest, Admin/IndexEventReviewRequest, Admin/ReviewEventRequest;
  resources EventResource/AdminEventResource y modelo Event. Relaciones cargadas
  explícitamente; preservar consulta ligera de existencia de banners.
- Migración 2026_11_11_000001_add_review_fields_to_events_table.php:
  queue_enabled, rejection_reason, reviewed_at, reviewed_by; conserva datos.

### HU-30: mercado

- GET /api/v1/dashboard/mercado, auth:api + role:ORGANIZER (401 sin JWT;
  CLIENT/ADMIN 403). Agrega todos los organizadores, sin datos de compradores
  ni nombres de organizadores o eventos individuales; solo eventos PUBLISHED,
  incluidos pasados. No reutilizar el reporte privado de un único organizador.
- Archivos: Dashboard/MarketDashboardRequest → MarketDashboardController →
  app/Queries/MarketDashboardQuery.php. Filtros AND: dateFrom/dateTo YYYY-MM-DD
  inclusivos por fecha del evento en Lima; category sin distinguir mayúsculas,
  venueId existente, queueEnabled=0/1. No perder el filtro 0 por truthiness.
- Tickets vendidos: SUM(quantity) de órdenes CONFIRMADA o históricos sin orden;
  CANCELADA excluida. Capacidad agregada separada de tickets para evitar duplicar
  sumas por joins. revenue excluye service_fee; serviceFees se informa aparte.
- Devuelve summary, topVenues por eventos/tickets/ingresos, topCategories por
  tickets, venues, categories, categoryDistribution, monthlySales, salesHeatmap,
  sellOutTime y queueConversion. Top cinco con desempates estables.
- Ocupación: media de porcentajes por evento; ticket promedio: ingresos de
  entradas/cantidad de entradas. Tendencia y heatmap usan tickets.created_at
  en Lima dentro de la cohorte filtrada, no un segundo filtro por fecha de venta.
  Semana ISO lunes=1/domingo=7; meses/celdas sin ventas se omiten.
- Sin eventos: 200/empty=true/colecciones vacías; promedios sin observaciones null.
  Respuesta Cache-Control private, no-store. No inventar datos ni exponer PII.
- Event::recordFirstSoldOut se llama desde ambas APIs de compra tras descontar
  stock, en la misma transacción y con evento bloqueado. Consulta todas las zonas
  con bloqueo y registra first_sold_out_at solo la primera vez que se agotan.
  Campo fuera de Fillable y gestionado por servidor. Rollback revierte el hito;
  cancelación, reventa e idempotencia no cambian la primera fecha.
- sellOutTime: horas desde published_at al primer agotamiento; sampleSize y
  eventos sin medición. Excluir fechas incompletas, invertidas o agotamiento
  futuro; cero horas válido. No inferir hitos históricos a partir del stock.
- Migración 2026_11_11_000002_add_first_sold_out_at_to_events_table.php: timestamp
  nullable, cast datetime en Event. Aplicar migraciones al desplegar.
- Pendiente acordado para después: conversión de cola COMPLETED frente a EXPIRED
  y CANCELLED. queueConversion devuelve available=false, valores null y motivo.
  Requiere contrato/conteos por evento y retención de estados finales del servicio
  Python; acordar fórmula y periodo. Tickets vendidos no equivalen a turnos.
  No presentarlo como implementado ni como exclusión definitiva aprobada por QA.
- queueEnabled solo persiste configuración. No afirmar que este avance implementa
  turnos, purchase_pass, habilitación de ventas, anti-scalping o gráficos Angular.

## Desarrollo y verificación

Entorno soportado: Docker Compose. Desde un clon nuevo:

En PowerShell, crear `.env` con `Copy-Item .env.example .env` solo si aún no
existe; en Bash usar `cp .env.example .env`. No sobrescribir claves existentes.
Docker Desktop debe estar iniciado con contenedores Linux. Después:

```bash
docker compose up -d --build
docker compose logs --tail 50 app
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
docker compose up -d --no-deps --force-recreate app
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
```

Generar claves solo en una instalación nueva. La recreación de `app` recarga
APP_KEY/JWT_SECRET en `env_file`; esperar a que termine Composer antes de Artisan.
Para entornos existentes: levantar servicios, conservar claves y ejecutar
`php artisan migrate --seed`; README detalla actualización, logs y diagnóstico.

API local `localhost:8080/api`; cotización base en `localhost:8000/api`;
MySQL `localhost:3307` desde el host y `db:3306` dentro de Docker. La suite usa
SQLite `:memory:`/RefreshDatabase, claves efímeras y caché array; nunca la BD
habitual ni Render. Las verificaciones concurrentes separadas requieren una BD
efímera aislada; ver scripts manuales de tests/Integration descritos abajo.
La documentación Demo 3 conserva valor histórico, no certifica el flujo nuevo.
`vendor/` se instala desde composer.lock. No actualizar dependencias por rutina.
Verificación excepcional con PHP portátil solo cuando el usuario la autorice;
no convertirla en un nuevo requisito del proyecto ni versionar el runtime.

### Evidencia y pruebas para localizar

Última suite completa verificada por el desarrollador el 11/11/2026 para el
avance de cb1556f: SQLite, 356 pruebas y 2058 aserciones. Pint: 13 archivos de
HU-30 aprobados; git diff --check sin errores. Son resultados históricos, no
una nueva ejecución por leer este archivo ni garantía de ausencia de errores.

- Revisión: EventReviewStateTest, EventReviewMigrationTest (DatabaseMigrations),
  AdminEventListingTest, AdminEventReviewTest, OrganizerEventAccessTest,
  OrganizerEventListTest, RejectedEventUpdateTest; regresión StoreEventTest,
  FrontendCompatibilityTest, PurchaseTicketTest y gestión de usuarios existente.
- Mercado: MarketDashboardAccessTest, MarketDashboardMetricsTest,
  MarketDashboardSellOutTest, EventSellOutTest. Cubren filtros, privacidad,
  totales, Lima, rankings, cancelaciones, idempotencia, hito y rollback.
- Manuales, no descubiertos por PHPUnit: tests/Integration/event-review-mysql.php
  (4 carreras/144 comprobaciones), event-review-http.php (32 comprobaciones),
  market-sellout-mysql.php (3 carreras y dashboard HTTP/67 comprobaciones).
- Ejecutados en MySQL 8.0.46 REPEATABLE-READ: APP_ENV=testing, DB ticketing_qa,
  host ticketing_qa_mysql, contenedor ticketing_qa_app, HTTP interno 8000 con
  PHP_CLI_SERVER_WORKERS=4. Contenedores separados; compose up no los crea.
  Verificar aislamiento/migraciones antes de ejecutar. No usar BD habitual ni
  Render ni retirar protecciones del script. Crean fixtures y no borran datos.
  CLIENT requiere clientProfile y ORGANIZER organizerProfile para login HTTP.
- No se ejecutó suite de este avance en PostgreSQL ni prueba de carga masiva.
  Integración visual Angular, aceptación QA, merge y despliegue siguen sujetos
  a verificación; no inferirlos del número de pruebas aprobadas.

## Límites y seguridad

- Inspeccionar el código relacionado antes de implementar; no inventar patrones.
- No modificar archivos fuera del alcance ni otros repositorios/frontend-angular
  sin instrucción explícita del usuario.
- No instalar dependencias nuevas ni introducir herramientas/servicios sin
  explicar la necesidad y obtener autorización explícita.
- `.env` está ignorado. `.env.example` lleva solo ejemplos y placeholders vacíos
  para secretos. JWT_SECRET se genera con Artisan; nunca compartirlo/versionarlo.
- No escribir credenciales ADMIN en fuentes; INITIAL_ADMIN_* son variables privadas.
- No ejecutar operaciones destructivas de BD/Docker, `down -v`, `system prune`,
  borrado de volúmenes ni cambios en producción sin autorización explícita.
- CORS usa el default de Laravel; no hay rate limiting explícito. Avisar antes
  de cambiar CORS. `docs/database/schema.sql` es antiguo: NO es fuente de verdad.
- Git de solo lectura permitido. add/commit/push/pull/merge/rebase/reset y cambios
  de ramas requieren autorización explícita; no publicar cambios por iniciativa.
- Al terminar: detallar archivos modificados, verificaciones y límites pendientes.
