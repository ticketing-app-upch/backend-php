# Entrega backend de la Épica 1

Fecha: 2026-10-25. Demos 1 y 2 en conjunto. Alcance autorizado: solo backend.
Base de los cambios: main b9e7446; rama de entrega feat/demo_3, junto a Demo 3.
No se integró a main ni se desplegó esta versión en Render.

## Cumplimiento

| Historia | Backend entregado |
|---|---|
| HU-01 Registro | Registro de asistentes/organizadores, documentos únicos, contraseña con hash, cuenta activa y admin únicamente por seeding |
| HU-02 Login | JWT con rol; errores genéricos 401; cuenta inactiva con contraseña correcta 403 con el mensaje requerido |
| HU-03 Logout | Endpoint protegido que invalida solo el JWT presentado para cualquiera de los tres roles |
| HU-04 Expiración | 5 minutos por defecto, configurable; tokens expirados rechazados con 401 JSON |
| HU-05 Acceso por rol | Rutas administrativas protegidas por JWT y ADMIN; 401 sin autenticación, 403 por rol incorrecto |
| HU-06 T&C | Booleano true obligatorio en registro y fecha de aceptación persistida |
| HU-07 Listado | CLIENT/ORGANIZER, nombre/email/rol/estado, filtros por rol y estado; sin ADMIN ni operaciones de alta/eliminación |
| HU-08 Estado | Activar/desactivar de forma idempotente; proteger administradores; conservar datos y JWT previos hasta expirar; impedir nuevas publicaciones de organizadores inactivos |

Los requisitos de pantallas, guards, interceptor y limpieza del navegador siguen
siendo responsabilidad del frontend, excluido expresamente por el usuario.

## Verificaciones realizadas

- Suite completa PHPUnit: **170 pruebas, 787 aserciones, todas aprobadas**.
- PHP 8.4.27 portátil, autorizado por el usuario; PHPUnit 12.5.35; SQLite :memory:.
- Dependencias instaladas desde composer.lock: sin actualizar composer.json/lock.
- Pint aprobado sobre todos los archivos PHP agregados/modificados.
- route:list confirma 10 rutas API y middleware auth:api/role:ADMIN en administración.
- git diff --check sin errores de espacios.
- La prueba antigua de catálogo tenía una fecha ya pasada: ahora fija su reloj.
- Pruebas de errores de unicidad simulan MySQL/PostgreSQL; no sustituyen una
  ejecución de integración nativa en esos motores. No se conectó a las bases reales.

## Publicación pendiente

El servicio Render existente no incluye estos cambios hasta publicarlos y
desplegarlos con autorización. Configurar en privado INITIAL_ADMIN_NAME,
INITIAL_ADMIN_EMAIL e INITIAL_ADMIN_PASSWORD, y verificar JWT_TTL=5 en Render.
El arranque ejecutará DatabaseSeeder de forma idempotente. No hay migraciones
nuevas; no usar migrate:fresh ni borrar datos. No se crearon credenciales reales.

## Archivos cambiados

| Archivo | Cambio |
|---|---|
| .env.example | TTL y configuración MySQL local coherente con Compose |
| AGENTS.md | Guía breve y lectura selectiva del contexto |
| README.md | Contratos de login, logout, administración, sesiones y despliegue |
| app/Http/Controllers/AuthController.php | Inactivo 403, logout y detección de duplicados PostgreSQL |
| app/Http/Controllers/EventController.php | Prohibir publicación por organizadores inactivos y formato Pint |
| app/Http/Controllers/AdminUserController.php | Nuevo: listado y asignación del estado |
| app/Http/Requests/Admin/IndexUserRequest.php | Nuevo: validación de filtros |
| app/Http/Requests/Admin/UpdateUserStatusRequest.php | Nuevo: validación de booleano estricto |
| app/Http/Resources/AdminUserResource.php | Nuevo: salida administrativa explícita |
| app/Http/Middleware/Authenticate.php | Eliminado: se usa el middleware estándar, conservando JWT hasta expirar |
| bin/deploy.sh | Ejecutar DatabaseSeeder, incluyendo admin configurado |
| bootstrap/app.php | Retirar alias del middleware de bloqueo inmediato |
| config/jwt.php | TTL de 5 minutos y formato Pint |
| database/seeders/AdminUserSeeder.php | Normalizar email para login y seeding idempotente |
| render.yaml | TTL y variables privadas del administrador |
| routes/api.php | Nuevas rutas de logout y administración |
| tests/Feature/AdminUserManagementTest.php | Nuevo: permisos, filtros, estados, datos y sesiones |
| tests/Feature/LogoutTest.php | Nuevo: revocación y sesiones de los tres roles |
| tests/Feature/AdminUserSeederTest.php | Normalización y login del administrador sembrado |
| tests/Feature/IndexEventTest.php | Reloj fijo para escenarios con fechas fijas |
| tests/Feature/LoginTest.php | Inactivo 403 y contraseña incorrecta genérica |
| tests/Feature/RegisterTest.php | Unicidad concurrente MySQL/PostgreSQL y rollback |
| tests/Feature/SessionSecurityTest.php | Sesiones vigentes tras desactivar y TTL configurable |
| tests/Feature/StoreEventTest.php | Organizador inactivo no publica con token anterior |
| docs/PROJECT_CONTEXT.md | Nuevo: resumen general para próximas sesiones de IA |
| docs/EPIC1_IMPLEMENTATION.md | Nuevo: alcance, validación y registro de esta entrega |
