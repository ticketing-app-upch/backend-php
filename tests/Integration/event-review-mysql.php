<?php

// Ejecución manual exclusiva del contenedor ticketing_qa_app.
// No lo descubre PHPUnit y no elimina datos ni ejecuta migraciones.

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function qaHttp(array $request): array
{
    $path = $request['path'];
    if (! str_starts_with($path, '/api/') || str_contains($path, "\r") || str_contains($path, "\n")) {
        throw new RuntimeException('Ruta HTTP de QA no permitida.');
    }

    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Connection: close'];
    if (isset($request['token'])) {
        $headers[] = 'Authorization: Bearer '.$request['token'];
    }

    $context = stream_context_create(['http' => [
        'method' => $request['method'],
        'header' => implode("\r\n", $headers),
        'content' => json_encode($request['json'] ?? [], JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 35,
    ]]);
    $raw = @file_get_contents('http://127.0.0.1:8000'.$path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($raw === false || $status === 0) {
        throw new RuntimeException('La API HTTP de QA no respondió.');
    }

    return ['status' => $status, 'body' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

if (($argv[1] ?? '') === 'http-worker') {
    try {
        $request = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
        echo json_encode(qaHttp($request), JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$checks = 0;

function qaCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function qaRequest(string $method, string $path, array $payload, string $token): array
{
    return ['method' => $method, 'path' => $path, 'json' => $payload, 'token' => $token];
}

function qaActor(string $roleName, string $run): array
{
    $roleId = Role::where('name', $roleName)->value('id');
    qaCheck($roleId !== null, 'Falta ejecutar RoleSeeder en QA.');
    $password = 'Qa!A1'.bin2hex(random_bytes(12));
    $user = User::factory()->create([
        'role_id' => $roleId,
        'active' => true,
        'full_name' => 'QA '.$roleName,
        'email' => 'qa-'.$run.'-'.bin2hex(random_bytes(5)).'@example.test',
        'password' => $password,
    ]);
    if ($roleName === 'ORGANIZER') {
        $user->organizerProfile()->create([
            'tax_id' => '20'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT),
        ]);
    }
    $login = qaHttp([
        'method' => 'POST', 'path' => '/api/auth/login',
        'json' => ['email' => $user->email, 'password' => $password],
    ]);
    qaCheck($login['status'] === 200, 'Falló el login HTTP de una cuenta ficticia de QA.');
    qaCheck(is_string($login['body']['token'] ?? null), 'El login no devolvió JWT.');

    return ['user' => $user, 'token' => $login['body']['token']];
}

function qaPayload(Venue $venue, CarbonImmutable $date, string $title): array
{
    global $root;
    $bytes = file_get_contents($root.'/tests/Fixtures/banner.jpg');

    return [
        'id' => '', 'name' => $title,
        'description' => 'Evento ficticio para verificar carreras HTTP y MySQL.',
        'category' => 'CONCIERTO', 'status' => 'PUBLICADO',
        'venue' => $venue->name, 'city' => $venue->city,
        'venueCapacity' => $venue->capacity, 'maxPerOrder' => 6,
        'startsAt' => $date->toIso8601String(), 'queueEnabled' => false,
        'bannerUrl' => 'data:image/jpeg;base64,'.base64_encode($bytes),
        'secondaryBannerUrl' => 'data:image/jpeg;base64,'.base64_encode($bytes."\nqa-secondary"),
        'zones' => $venue->zones->map(fn ($zone, $index) => [
            'id' => 'qa-zone-'.$index, 'name' => $zone->name,
            'capacity' => $zone->capacity, 'sold' => 0, 'price' => 50,
        ])->all(),
    ];
}

function qaRace(string $label, array $requests, string $table, int $id): array
{
    global $root;
    qaCheck(count($requests) === 2, 'La carrera debe tener dos peticiones.');
    qaCheck(in_array($table, ['venues', 'users'], true), 'Barrera SQL no permitida.');
    $connection = DB::connection();
    $processes = [];
    $files = [];

    try {
        $connection->beginTransaction();
        qaCheck(
            $connection->table($table)->where('id', $id)->lockForUpdate()->first() !== null,
            'No existe el registro usado como barrera.',
        );

        foreach ($requests as $request) {
            $file = tempnam(sys_get_temp_dir(), 'ticketing-qa-');
            if ($file === false) {
                throw new RuntimeException('No se pudo preparar una petición temporal.');
            }
            $files[] = $file;
            chmod($file, 0600);
            file_put_contents($file, json_encode($request, JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, __FILE__, 'http-worker', $file], $root);
            $process->setTimeout(40);
            $process->start();
            $processes[] = $process;
        }

        // La conexión de control retiene la fila. Ambas peticiones deben llegar
        // realmente a SELECT ... FOR UPDATE antes de liberar la barrera.
        $deadline = microtime(true) + 15;
        $observed = 0;
        $peak = 0;
        $lastSessions = [];
        do {
            $sessions = $connection->select('SHOW FULL PROCESSLIST');
            $lastSessions = array_map(
                fn ($session) => array_change_key_case((array) $session, CASE_LOWER),
                $sessions,
            );
            $observed = count(array_filter($lastSessions, function ($session) use ($table) {
                $sql = strtolower((string) ($session['info'] ?? ''));

                return str_contains($sql, 'for update')
                    && preg_match('/\b'.preg_quote($table, '/').'\b/', $sql);
            }));
            $peak = max($peak, $observed);
            if ($observed >= 2) {
                break;
            }
            if (! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        if ($observed < 2) {
            echo 'DIAGNOSTICO: máximo de consultas bloqueantes detectadas = '.$peak.PHP_EOL;
            foreach ($lastSessions as $session) {
                $sql = (string) ($session['info'] ?? '');
                // No imprimir literales SQL, credenciales ni tokens.
                $sql = preg_replace("/'[^']*'/", "'?'", $sql);
                $sql = substr(preg_replace('/\\s+/', ' ', $sql), 0, 240);
                echo 'Sesion '.($session['id'] ?? '?')
                    .'; comando '.($session['command'] ?? '?')
                    .'; estado '.($session['state'] ?? '')
                    .'; segundos '.($session['time'] ?? '?')
                    .'; SQL '.$sql.PHP_EOL;
            }

            // Liberar la barrera para recoger la respuesta de ambos clientes.
            $connection->rollBack();
            foreach ($processes as $index => $process) {
                try {
                    $process->wait();
                    $result = json_decode($process->getOutput(), true);
                    echo 'Cliente '.($index + 1).': HTTP '.($result['status'] ?? 'sin respuesta JSON')
                        .'; mensaje '.substr((string) ($result['body']['message'] ?? ''), 0, 180).PHP_EOL;
                    if (! $process->isSuccessful()) {
                        echo 'Error cliente: '.substr($process->getErrorOutput(), 0, 240).PHP_EOL;
                    }
                } catch (Throwable $exception) {
                    echo 'Cliente '.($index + 1).': no terminó dentro del plazo.'.PHP_EOL;
                }
            }
            qaCheck(false, $label.': no se pudo demostrar el solapamiento; revisar el diagnóstico anterior.');
        }

        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            qaCheck($process->isSuccessful(), $label.': falló un cliente HTTP de prueba.');
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        echo $label.': barrera concurrente verificada; HTTP '
            .implode(', ', array_column($results, 'status')).PHP_EOL;

        return $results;
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

function qaStatuses(array $results): array
{
    $statuses = array_column($results, 'status');
    sort($statuses);

    return $statuses;
}

function qaShape(Event $event, Venue $venue): void
{
    $event->load('zones');
    qaCheck($event->zones->count() === $venue->zones->count(), 'Cantidad de zonas incorrecta.');
    qaCheck($event->banner()->count() === 1, 'El evento debe tener un único registro de banners.');
    foreach ($event->zones as $zone) {
        $template = $venue->zones->firstWhere('id', $zone->venue_zone_id);
        qaCheck($template !== null && $template->capacity === $zone->capacity,
            'Una zona no coincide con el recinto.');
        qaCheck($zone->available_capacity === $zone->capacity, 'La revisión alteró el stock.');
    }
}

function qaCreateRejected(array $owner, array $admin, Venue $venue, CarbonImmutable $date, string $name): Event
{
    $created = qaHttp(qaRequest('POST', '/api/events', qaPayload($venue, $date, $name), $owner['token']));
    qaCheck($created['status'] === 201, 'No se pudo preparar el evento de QA.');
    $id = $created['body']['id'];
    $review = qaHttp(qaRequest('PATCH', '/api/v1/admin/eventos/'.$id.'/estado', [
        'status' => Event::STATUS_REJECTED, 'rejectionReason' => 'Corrección ficticia para QA.',
    ], $admin['token']));
    qaCheck($review['status'] === 200, 'No se pudo rechazar el evento de preparación.');

    return Event::findOrFail($id);
}

try {
    qaCheck(app()->environment('testing'), 'Solo se permite APP_ENV=testing.');
    qaCheck(config('database.default') === 'mysql', 'Esta prueba requiere MySQL real.');
    qaCheck(config('database.connections.mysql.host') === 'ticketing_qa_mysql', 'Host distinto del MySQL aislado.');
    qaCheck(DB::connection()->getDatabaseName() === 'ticketing_qa', 'Base distinta de ticketing_qa.');
    $info = DB::selectOne('SELECT VERSION() AS version, @@transaction_isolation AS isolation_level');
    echo 'MySQL '.$info->version.'; aislamiento '.$info->isolation_level.PHP_EOL;

    $venue = Venue::with('zones')->whereNotNull('slug')->orderBy('id')->firstOrFail();
    $base = CarbonImmutable::now('UTC')->addDays(30)->startOfDay();
    // Buscar un intervalo libre permite repetir sin borrar datos de ejecuciones anteriores.
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        if (! Event::where('venue_id', $venue->id)->whereBetween('starts_at', [
            $base->subDays(1), $base->addDays(12),
        ])->exists()) {
            break;
        }
        $base = $base->addDays(20);
    }
    qaCheck($attempt < 1000, 'No se encontró un intervalo de QA libre.');
    $run = bin2hex(random_bytes(5));
    $owners = [qaActor('ORGANIZER', $run), qaActor('ORGANIZER', $run)];
    $admins = [qaActor('ADMIN', $run), qaActor('ADMIN', $run)];

    $payload = qaPayload($venue, $base, 'QA carrera de creación '.$run);
    $results = qaRace('1. Dos creaciones del mismo recinto y fecha', [
        qaRequest('POST', '/api/events', $payload, $owners[0]['token']),
        qaRequest('POST', '/api/events', $payload, $owners[1]['token']),
    ], 'venues', $venue->id);
    qaCheck(qaStatuses($results) === [201, 409], 'La creación debe responder una vez 201 y una vez 409.');
    $winner = $results[0]['status'] === 201 ? 0 : 1;
    $created = Event::findOrFail($results[$winner]['body']['id']);
    qaCheck($created->organizer_id === $owners[$winner]['user']->id, 'Dueño de creación incorrecto.');
    qaCheck($created->status === Event::STATUS_PENDING_APPROVAL, 'La creación debe quedar pendiente.');
    qaCheck(Event::where('venue_id', $venue->id)->where('starts_at', $base)->count() === 1,
        'Se duplicó la reserva de recinto.');
    qaShape($created, $venue);

    $results = qaRace('2. Aprobar y rechazar simultáneamente', [
        qaRequest('PATCH', '/api/v1/admin/eventos/'.$created->id.'/estado', [
            'status' => Event::STATUS_PUBLISHED,
        ], $admins[0]['token']),
        qaRequest('PATCH', '/api/v1/admin/eventos/'.$created->id.'/estado', [
            'status' => Event::STATUS_REJECTED, 'rejectionReason' => 'Rechazo concurrente de QA.',
        ], $admins[1]['token']),
    ], 'venues', $venue->id);
    qaCheck(qaStatuses($results) === [200, 409], 'Solo una revisión puede completarse.');
    $winner = $results[0]['status'] === 200 ? 0 : 1;
    $created->refresh();
    qaCheck($created->reviewed_by === $admins[$winner]['user']->id, 'El revisor no coincide con la petición ganadora.');
    qaCheck($created->status === ($winner === 0 ? Event::STATUS_PUBLISHED : Event::STATUS_REJECTED),
        'El estado no corresponde a la revisión ganadora.');
    qaCheck($winner === 0 ? $created->published_at !== null : $created->published_at === null,
        'Fecha de publicación inconsistente.');
    qaShape($created, $venue);

    $editDate = $base->addDays(3);
    $edited = qaCreateRejected($owners[0], $admins[0], $venue, $editDate, 'QA doble reenvío '.$run);
    $editA = qaPayload($venue, $editDate, 'QA corrección A '.$run);
    $editA['id'] = (string) $edited->id;
    $editA['status'] = 'RECHAZADO';
    $editB = array_replace($editA, ['name' => 'QA corrección B '.$run]);
    $results = qaRace('3. Dos reenvíos del mismo evento', [
        qaRequest('PUT', '/api/v1/eventos/'.$edited->id, $editA, $owners[0]['token']),
        qaRequest('PUT', '/api/v1/eventos/'.$edited->id, $editB, $owners[0]['token']),
    ], 'users', $owners[0]['user']->id);
    qaCheck(qaStatuses($results) === [200, 403], 'Solo un reenvío debe completarse.');
    $winner = $results[0]['status'] === 200 ? 0 : 1;
    $edited->refresh();
    qaCheck($edited->title === ($winner === 0 ? $editA['name'] : $editB['name']), 'Se perdió la corrección ganadora.');
    qaCheck($edited->status === Event::STATUS_PENDING_APPROVAL, 'El reenvío no quedó pendiente.');
    qaCheck($edited->reviewed_at === null && $edited->reviewed_by === null
        && $edited->rejection_reason === null && $edited->published_at === null,
        'El reenvío conserva campos de revisión anteriores.');
    qaShape($edited, $venue);

    $contested = qaCreateRejected($owners[0], $admins[0], $venue, $base->addDays(6), 'QA cambio de fecha '.$run);
    $targetDate = $base->addDays(8);
    $edit = qaPayload($venue, $targetDate, 'QA reenvío en conflicto '.$run);
    $edit['id'] = (string) $contested->id;
    $edit['status'] = 'RECHAZADO';
    $new = qaPayload($venue, $targetDate, 'QA creación en conflicto '.$run);
    $results = qaRace('4. Reenvío frente a una creación del mismo horario', [
        qaRequest('PUT', '/api/v1/eventos/'.$contested->id, $edit, $owners[0]['token']),
        qaRequest('POST', '/api/events', $new, $owners[1]['token']),
    ], 'venues', $venue->id);
    qaCheck(in_array(qaStatuses($results), [[200, 409], [201, 409]], true),
        'Creación y reenvío no pueden reservar ambos el mismo horario.');
    qaCheck(Event::where('venue_id', $venue->id)->where('starts_at', $targetDate)
        ->whereIn('status', [Event::STATUS_PENDING_APPROVAL, Event::STATUS_PUBLISHED])->count() === 1,
        'Debe existir una sola reserva en el horario disputado.');
    $contested->refresh();
    qaCheck($results[0]['status'] === 200
        ? $contested->status === Event::STATUS_PENDING_APPROVAL && $contested->starts_at->equalTo($targetDate)
        : $contested->status === Event::STATUS_REJECTED && $contested->starts_at->equalTo($base->addDays(6)),
        'El reenvío perdedor no conservó su estado y fecha.');
    qaShape($contested, $venue);

    echo 'PASS: 4 carreras con barrera SQL verificadas; '.$checks.' comprobaciones.'.PHP_EOL;
    echo 'Solo se crearon datos ficticios en ticketing_qa. No se ejecutaron borrados.'.PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
