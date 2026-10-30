# AGENTS.md — backend-php

## Entrada rápida

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
- Requests en `app/Http/Requests/<Dominio>/`: validación de formato. Duplicados
  se comprueban en el controlador y con índices únicos en la BD.
- `422` para formato inválido; `409` para duplicados. Conservar esta diferencia.
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
  un chequeo global de `active`. Publicar eventos y comprar exigen cuenta activa.
- Administración solo ADMIN; no crear/eliminar usuarios ni cambiar cuentas ADMIN.
- Logout invalida solo el JWT presentado mediante la blacklist compartida.
- Antes de cambiar rutas, payloads o códigos existentes, advertir el impacto en
  el frontend. Revisar README para el contrato completo.
- Demo 3: contrato Angular `demo3` documentado en docs/FRONTEND_COMPATIBILITY.md.
  POST /api/events admite EventItem JSON con imágenes JPEG Base64 y el multipart
  anterior con venueId/banner/venueZoneId. Los aforos se validan contra el recinto.
  JSON exige 24 horas entre eventos; multipart conserva el día calendario de Lima.
  GET /api/events responde al frontend; `?contract=legacy` conserva el formato previo.
  Los endpoints v1 de eventos/favoritos mantienen su formato anterior.
  Publicación directa e inmutabilidad. Solo se eliminan eventos sin historial.
- Compras: pago simulado, máximo 6 entre todas las zonas y un Ticket por entrada.
  Bloquear filas de zonas en orden estable y guardar snapshots inmutables.
- /api/orders agrupa tickets con precios finales sin comisión e idempotencia
  persistente por usuario. Reintento idéntico 200; clave con otro payload 409.
  Cancelación solo ADMIN: restaurar stock una vez y rechazar validación pública.
  Nuevas compras: fee y service_fee son 0; total = suma de entradas en centavos.
  Conservar importes/comisiones históricas y respuestas de reintentos previos.
  El endpoint anterior de compra mantiene su pago/importe sin comisión.
- Catálogo: 15 recintos y 50 zonas en database/data/frontend-venues.json. No borrar
  recintos históricos ni cambiar IDs/nombres de zonas que tengan ventas.
- Cotización Demo 3: /api/v1/dynamic-price devuelve precio base de BD. También
  /pricing/api/v1/dynamic-price; el puerto local 8000 sirve el mismo backend.
  No implementar reglas dinámicas de Demo 4 ni confiar en precios del cliente.
- Banners en event_banners (DB); el catálogo nunca debe cargar su contenido.
- Registro limitado a los campos confirmados por el usuario el 2026-10-29:
  fullName, email, password, role y acceptedTerms=true; CLIENT requiere
  profile.docNumber (DNI) y ORGANIZER organizer.displayName (empresa) y taxId (RUC).
  Ignorar campos adicionales; no guardar teléfono, país, género, marketingOptIn
  ni otros detalles del formulario. Respuestas de registro/login con lista
  explícita de esos campos; no devolver detalles históricos fuera del contrato.
  El nombre de empresa se guarda en organizer_profiles.details.displayName.
  Conservar columnas/datos históricos; no reescribir migraciones ni ampliar
  el registro al adaptar otros contratos del frontend.
- Detalle de contratos/migraciones Demo 3: docs/DEMO3_IMPLEMENTATION.md. No agregar
  cola, anti-scalping, correos ni aprobación/edición de rechazados (Demo 4).

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
efímera aislada, como las documentadas en docs/FRONTEND_COMPATIBILITY.md.
`vendor/` se instala desde composer.lock. No actualizar dependencias por rutina.
Verificación excepcional con PHP portátil solo cuando el usuario la autorice;
no convertirla en un nuevo requisito del proyecto ni versionar el runtime.

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
