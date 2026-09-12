<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['code' => 'INVALID_JSON', 'message' => 'El cuerpo debe ser JSON válido.'], 400);
    }
    return $data;
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'mysql',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'ticketing_platform',
    );
    try {
        $connection = new PDO($dsn, getenv('DB_USER') ?: 'ticketing', getenv('DB_PASSWORD') ?: 'ticketing', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $connection;
    } catch (PDOException $error) {
        respond(['code' => 'DATABASE_UNAVAILABLE', 'message' => 'No se pudo conectar con MySQL.'], 503);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api#', '', $path) ?: '/';

if ($method === 'GET' && $path === '/health') {
    try {
        db()->query('SELECT 1');
        respond(['status' => 'ok', 'database' => 'ok']);
    } catch (Throwable) {
        respond(['status' => 'error', 'database' => 'unavailable'], 503);
    }
}

if ($method === 'POST' && $path === '/auth/login') {
    $body = requestBody();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    if ($email === '' || $password === '') {
        respond(['code' => 'INVALID_CREDENTIALS', 'message' => 'Correo y contraseña son obligatorios.'], 422);
    }

    $query = db()->prepare(
        'SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.password_hash, u.estado, r.nombre AS role_name
         FROM user_account u JOIN role r ON r.id_rol = u.id_rol WHERE u.correo = :email LIMIT 1',
    );
    $query->execute(['email' => $email]);
    $user = $query->fetch();
    if (!$user || $user['estado'] !== 'activo' || !password_verify($password, $user['password_hash'])) {
        respond(['code' => 'INVALID_CREDENTIALS', 'message' => 'Credenciales inválidas.'], 401);
    }

    $roles = ['Administrador' => 'ADMIN', 'Organizador' => 'ORGANIZER', 'Asistente' => 'CLIENT'];
    $role = $roles[$user['role_name']] ?? null;
    if ($role === null) {
        respond(['code' => 'INVALID_ROLE', 'message' => 'El rol del usuario no está configurado.'], 500);
    }

    // Token temporal de desarrollo. Se reemplazará por JWT antes de producción.
    $token = base64_encode(json_encode(['sub' => (string) $user['id_usuario'], 'role' => $role, 'exp' => time() + 3600]));
    respond([
        'token' => $token,
        'user' => [
            'id' => (string) $user['id_usuario'],
            'fullName' => trim($user['nombres'] . ' ' . $user['apellidos']),
            'email' => $user['correo'],
            'role' => $role,
        ],
    ]);
}

if ($method === 'GET' && $path === '/events') {
    $query = db()->prepare(
        "SELECT e.id_evento, e.titulo, e.descripcion, e.estado, e.fecha_inicio, e.fecha_publicacion,
                e.imagen_url, e.max_per_order, c.nombre AS category, o.id_organizador AS organizer_id,
                v.nombre AS venue, d.nombre AS city
         FROM event_catalog e
         JOIN category_event c ON c.id_categoria = e.id_categoria
         JOIN organizer_profile o ON o.id_organizador = e.id_organizador
         LEFT JOIN venue v ON v.id_recinto = e.id_recinto
         LEFT JOIN district d ON d.id_distrito = v.id_distrito
         WHERE e.estado IN ('PUBLICADO', 'AGOTADO')
           AND (:category = '' OR c.nombre = :category)
           AND (:search = '' OR LOWER(CONCAT(e.titulo, ' ', e.descripcion, ' ', COALESCE(v.nombre, ''), ' ', COALESCE(d.nombre, ''))) LIKE CONCAT('%', LOWER(:search), '%'))
         ORDER BY e.fecha_inicio ASC",
    );
    $query->execute([
        'category' => trim((string) ($_GET['category'] ?? '')),
        'search' => trim((string) ($_GET['search'] ?? '')),
    ]);
    $events = $query->fetchAll();

    $zones = db()->prepare(
        'SELECT id_zona AS id, id_evento, nombre AS name, precio_base AS price,
                aforo_maximo AS capacity, aforo_maximo - aforo_disponible AS sold
         FROM event_zone WHERE id_evento = :event_id AND estado = \'ACTIVA\' ORDER BY id_zona',
    );
    $result = [];
    foreach ($events as $event) {
        $zones->execute(['event_id' => $event['id_evento']]);
        $result[] = [
            'id' => (string) $event['id_evento'],
            'name' => $event['titulo'],
            'description' => $event['descripcion'],
            'category' => strtoupper($event['category']),
            'status' => $event['estado'],
            'venue' => $event['venue'] ?? '',
            'city' => $event['city'] ?? '',
            'startsAt' => $event['fecha_inicio'],
            'publishedAt' => $event['fecha_publicacion'],
            'imageUrl' => $event['imagen_url'] ?? '',
            'organizerId' => (string) $event['organizer_id'],
            'maxPerOrder' => (int) $event['max_per_order'],
            'zones' => array_map(static function (array $zone): array {
                return [
                    'id' => (string) $zone['id'],
                    'name' => $zone['name'],
                    'price' => (float) $zone['price'],
                    'capacity' => (int) $zone['capacity'],
                    'sold' => (int) $zone['sold'],
                ];
            }, $zones->fetchAll()),
        ];
    }
    respond($result);
}

respond(['code' => 'NOT_FOUND', 'message' => 'Endpoint no encontrado.'], 404);