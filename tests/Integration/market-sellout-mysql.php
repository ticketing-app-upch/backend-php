<?php

// Ejecución manual exclusiva del contenedor ticketing_qa_app.
// No lo descubre PHPUnit y no elimina datos ni ejecuta migraciones.

use App\Models\Event;
use App\Models\Role;
use App\Models\TicketOrder;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    if ($roleName === 'CLIENT') {
        $user->clientProfile()->create([
            'doc_number' => 'QA-'.$run.'-'.$user->id,
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

function qaRace(string $label, array $requests, string $table, int $id): array
{
    global $root;
    qaCheck(count($requests) === 2, 'La carrera debe tener dos peticiones.');
    qaCheck(in_array($table, ['events'], true), 'Barrera SQL no permitida.');
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

function marketQaEvent(Venue $venue, array $owner, array $capacities, string $run): Event
{
    $event = Event::create([
        'organizer_id' => $owner['user']->id, 'venue_id' => $venue->id,
        'title' => 'QA mercado '.$run, 'description' => 'Evento ficticio para verificar agotamiento concurrente.',
        'category' => 'CONCIERTO', 'venue_name' => $venue->name, 'venue_address' => $venue->address,
        'venue_capacity' => array_sum($capacities), 'starts_at' => CarbonImmutable::now('UTC')->addMonths(2),
        'published_at' => CarbonImmutable::now('UTC')->subHours(4),
        'status' => 'PUBLISHED', 'queue_enabled' => false, 'max_per_order' => 6,
    ]);
    foreach ($capacities as $index => $capacity) {
        $event->zones()->create([
            'name' => 'Zona QA '.($index + 1), 'capacity' => $capacity,
            'available_capacity' => $capacity, 'base_price' => 50,
        ]);
    }

    return $event;
}

function marketQaPurchase(Event $event, int $zoneId, array $buyer, bool $legacy = false): array
{
    $payload = ['items' => [['zoneId' => $zoneId, 'quantity' => 1]]];
    if ($legacy) {
        $path = '/api/events/'.$event->id.'/tickets';
        $payload['payment'] = [
            'cardNumber' => '4111111111111111', 'cvv' => '123',
            'expiresAt' => CarbonImmutable::now('UTC')->addYear()->toDateString(),
        ];
    } else {
        $path = '/api/orders';
        $payload['eventId'] = $event->id;
    }

    return qaRequest('POST', $path, $payload, $buyer['token']);
}

function marketQaVerify(Event $event, int $expectedTickets): void
{
    $event->refresh();
    qaCheck($event->first_sold_out_at !== null, 'Falta registrar el primer agotamiento.');
    qaCheck($event->published_at !== null && $event->first_sold_out_at->greaterThanOrEqualTo($event->published_at), 'Fecha de agotamiento anterior a la publicación.');
    $zones = $event->zones()->get();
    qaCheck((int) $zones->sum('available_capacity') === 0, 'El evento debe estar agotado.');
    qaCheck($zones->every(fn ($zone) => $zone->available_capacity >= 0), 'Se detectó stock negativo.');
    $sold = DB::table('tickets as t')->join('ticket_orders as o', 'o.id', '=', 't.ticket_order_id')
        ->where('o.event_id', $event->id)->where('o.status', 'CONFIRMADA')->sum('t.quantity');
    qaCheck((int) $sold === $expectedTickets, 'Cantidad de tickets confirmados inconsistente.');
}

try {
    qaCheck(app()->environment('testing'), 'Solo se permite APP_ENV=testing.');
    qaCheck(config('database.default') === 'mysql', 'Esta prueba requiere MySQL real.');
    qaCheck(config('database.connections.mysql.host') === 'ticketing_qa_mysql', 'Host distinto del MySQL aislado.');
    qaCheck(DB::connection()->getDatabaseName() === 'ticketing_qa', 'Base distinta de ticketing_qa.');
    qaCheck(Schema::hasColumn('events', 'first_sold_out_at'), 'Falta aplicar la migración de agotamiento en QA.');
    $info = DB::selectOne('SELECT VERSION() AS version, @@transaction_isolation AS isolation_level');
    echo 'MySQL '.$info->version.'; aislamiento '.$info->isolation_level.PHP_EOL;
    $run = bin2hex(random_bytes(6));
    $owner = qaActor('ORGANIZER', $run);
    $admin = qaActor('ADMIN', $run);
    $buyers = [qaActor('CLIENT', $run), qaActor('CLIENT', $run)];
    $venue = Venue::create(['name' => 'QA mercado concurrencia '.$run, 'address' => 'Recinto ficticio QA', 'capacity' => 2]);

    // Fixtures controlados: se preparan directamente en la base aislada.
    // Todas las operaciones que se disputan stock usan HTTP real.
    $last = marketQaEvent($venue, $owner, [1], $run.'-last');
    $zone = $last->zones()->firstOrFail();
    $results = qaRace('1. Dos compradores por la última entrada', [
        marketQaPurchase($last, $zone->id, $buyers[0]),
        marketQaPurchase($last, $zone->id, $buyers[1]),
    ], 'events', $last->id);
    qaCheck(qaStatuses($results) === [201, 409], 'Solo un comprador puede adquirir la última entrada.');
    marketQaVerify($last, 1);
    qaCheck(TicketOrder::where('event_id', $last->id)->count() === 1, 'La compra fallida dejó una orden.');

    $multi = marketQaEvent($venue, $owner, [1, 1], $run.'-multi');
    [$first, $second] = $multi->zones()->orderBy('id')->get();
    $results = qaRace('2. Últimas entradas de zonas diferentes por ambas APIs', [
        marketQaPurchase($multi, $first->id, $buyers[0]),
        marketQaPurchase($multi, $second->id, $buyers[1], true),
    ], 'events', $multi->id);
    qaCheck(qaStatuses($results) === [201, 201], 'Ambas zonas deben venderse sin perder el hito.');
    marketQaVerify($multi, 2);

    $cancel = marketQaEvent($venue, $owner, [1], $run.'-cancel');
    $zone = $cancel->zones()->firstOrFail();
    $initial = qaHttp(marketQaPurchase($cancel, $zone->id, $buyers[0]));
    qaCheck($initial['status'] === 201, 'No se pudo preparar la compra para cancelar.');
    $cancel->refresh();
    $originalMilestone = $cancel->first_sold_out_at?->toDateTimeString();
    qaCheck($originalMilestone !== null, 'La compra inicial no registró agotamiento.');
    $results = qaRace('3. Cancelación frente a una nueva compra', [
        qaRequest('PATCH', '/api/orders/'.$initial['body']['id'], ['status' => 'CANCELADA'], $admin['token']),
        marketQaPurchase($cancel, $zone->id, $buyers[1]),
    ], 'events', $cancel->id);
    qaCheck($results[0]['status'] === 200, 'La cancelación debe completarse.');
    qaCheck(in_array($results[1]['status'], [201, 409], true), 'Respuesta inesperada de la compra concurrente.');
    if ($results[1]['status'] === 409) {
        qaCheck((int) $cancel->zones()->sum('available_capacity') === 1, 'La cancelación no restituyó stock.');
        $retry = qaHttp(marketQaPurchase($cancel, $zone->id, $buyers[1]));
        qaCheck($retry['status'] === 201, 'No se pudo comprar después de la cancelación.');
    }
    marketQaVerify($cancel, 1);
    qaCheck($cancel->first_sold_out_at?->toDateTimeString() === $originalMilestone, 'Se sobrescribió el primer agotamiento.');
    qaCheck(TicketOrder::where('event_id', $cancel->id)->where('status', 'CANCELADA')->count() === 1, 'Falta la orden cancelada.');
    qaCheck(TicketOrder::where('event_id', $cancel->id)->count() === 2, 'Hay órdenes duplicadas o parciales.');

    $market = qaHttp(qaRequest('GET', '/api/v1/dashboard/mercado?venueId='.$venue->id, [], $owner['token']));
    qaCheck($market['status'] === 200, 'El dashboard HTTP no respondió correctamente sobre MySQL.');
    qaCheck(($market['body']['summary']['eventCount'] ?? null) === 3, 'Cantidad de eventos incorrecta.');
    qaCheck(($market['body']['summary']['ticketsSold'] ?? null) === 4, 'El dashboard contó ventas canceladas o perdió ventas.');
    qaCheck((float) ($market['body']['summary']['revenue'] ?? -1) === 200.0, 'Ingresos agregados incorrectos.');
    qaCheck((float) ($market['body']['summary']['serviceFees'] ?? -1) === 9.0, 'Comisiones agregadas incorrectas.');
    qaCheck(($market['body']['sellOutTime']['sampleSize'] ?? null) === 3, 'Muestra de agotamiento incorrecta.');
    qaCheck(($market['body']['sellOutTime']['available'] ?? null) === true, 'El promedio no está disponible.');
    qaCheck((float) ($market['body']['sellOutTime']['averageHours'] ?? -1) >= 4.0, 'Duración de agotamiento incorrecta.');
    echo 'PASS: 3 carreras con barrera SQL y dashboard HTTP sobre MySQL; '.$checks.' comprobaciones.'.PHP_EOL;
    echo 'Solo datos ficticios en ticketing_qa. No se ejecutaron borrados ni migraciones.'.PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
