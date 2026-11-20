<?php

// Prueba HTTP manual contra el entorno MySQL de QA. No ejecuta migraciones ni borrados.

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$checks = 0;

function smokeCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function smokeClient(?string $token = null)
{
    $client = Http::baseUrl('http://127.0.0.1:8000')->acceptJson()->timeout(30);

    return $token === null ? $client : $client->withToken($token);
}

function smokeActor(string $role): array
{
    $roleId = Role::where('name', $role)->value('id');
    smokeCheck($roleId !== null, 'Falta RoleSeeder en QA.');
    $password = 'Qa!A1'.bin2hex(random_bytes(12));
    $user = User::factory()->create([
        'role_id' => $roleId, 'active' => true,
        'full_name' => 'QA HTTP '.$role,
        'email' => 'qa-http-'.bin2hex(random_bytes(10)).'@example.test',
        'password' => $password,
    ]);
    if ($role === 'ORGANIZER') {
        $user->organizerProfile()->create([
            'tax_id' => '20'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT),
        ]);
    }
    $login = smokeClient()->post('/api/auth/login', [
        'email' => $user->email, 'password' => $password,
    ]);
    smokeCheck($login->status() === 200 && is_string($login->json('token')), 'Falló el login HTTP de QA.');

    return ['user' => $user, 'token' => $login->json('token')];
}

function smokeUpload(string $path, array $fields, string $first, string $second, string $token, bool $invalid = false)
{
    return smokeClient($token)
        ->attach('banner', $first, 'banner.jpg', ['Content-Type' => 'image/jpeg'])
        ->attach('secondaryBanner', $second, $invalid ? 'invalid.txt' : 'secondary.jpg', [
            'Content-Type' => $invalid ? 'text/plain' : 'image/jpeg',
        ])
        ->post($path, $fields);
}

try {
    smokeCheck(app()->environment('testing'), 'Solo se permite APP_ENV=testing.');
    smokeCheck(config('database.default') === 'mysql', 'Se requiere MySQL real.');
    smokeCheck(config('database.connections.mysql.host') === 'ticketing_qa_mysql', 'Host fuera del entorno QA.');
    smokeCheck(DB::connection()->getDatabaseName() === 'ticketing_qa', 'Base fuera del entorno QA.');

    $owner = smokeActor('ORGANIZER');
    $admin = smokeActor('ADMIN');
    $venue = Venue::with('zones')->whereNotNull('slug')->orderBy('id')->firstOrFail();
    $date = CarbonImmutable::now('UTC')->addDays(30)->startOfDay();
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        if (! Event::where('venue_id', $venue->id)->whereBetween('starts_at', [
            $date->subDays(1), $date->addDays(2),
        ])->exists()) {
            break;
        }
        $date = $date->addDays(20);
    }
    smokeCheck($attempt < 1000, 'No se encontró un horario de QA libre.');

    $fields = [
        'title' => 'QA HTTP multipart '.bin2hex(random_bytes(4)),
        'description' => 'Evento ficticio para verificar archivos mediante HTTP real.',
        'category' => 'CONCIERTO', 'venueId' => (string) $venue->id,
        'startsAt' => $date->toIso8601String(), 'queueEnabled' => '0',
    ];
    foreach ($venue->zones as $index => $zone) {
        $fields['zones['.$index.'][venueZoneId]'] = (string) $zone->id;
        $fields['zones['.$index.'][basePrice]'] = '50';
    }
    $bytes = file_get_contents($root.'/tests/Fixtures/banner.jpg');
    $secondary = $bytes."\nqa-http-secondary";

    $created = smokeUpload('/api/events', $fields, $bytes, $secondary, $owner['token']);
    smokeCheck($created->status() === 201, 'La creación multipart no devolvió 201; HTTP '.$created->status());
    smokeCheck($created->json('status') === Event::STATUS_PENDING_APPROVAL, 'Multipart debe crear pendiente.');
    $event = Event::findOrFail($created->json('id'));
    smokeCheck($event->organizer_id === $owner['user']->id, 'Dueño incorrecto.');
    smokeCheck(base64_decode($event->banner->content, true) === $bytes, 'El banner principal no se conservó.');
    smokeCheck(base64_decode($event->banner->secondary_content, true) === $secondary, 'El segundo banner no se conservó.');
    echo 'PASS: creación HTTP multipart con dos JPEG y estado pendiente.'.PHP_EOL;

    foreach (['banner' => $bytes, 'secondary-banner' => $secondary] as $image => $expected) {
        $privatePath = '/api/v1/eventos/'.$event->id.'/'.$image;
        $private = smokeClient($owner['token'])->get($privatePath);
        smokeCheck($private->status() === 200 && $private->body() === $expected, 'No se pudo recuperar una imagen privada.');
        smokeCheck(str_contains((string) $private->header('Cache-Control'), 'no-store'), 'La imagen privada permite almacenamiento en caché.');
        smokeCheck(smokeClient()->get($privatePath)->status() === 401, 'La imagen privada no exige JWT.');
        smokeCheck(smokeClient()->get('/api/events/'.$event->id.'/'.$image)->status() === 404, 'Se expuso públicamente una imagen pendiente.');
    }
    echo 'PASS: imágenes privadas, contenido exacto, JWT y caché.'.PHP_EOL;

    $rejected = smokeClient($admin['token'])->patch('/api/v1/admin/eventos/'.$event->id.'/estado', [
        'status' => Event::STATUS_REJECTED, 'rejectionReason' => 'Corrección de QA HTTP.',
    ]);
    smokeCheck($rejected->status() === 200, 'No se pudo rechazar el evento de QA.');

    $fields['_method'] = 'PUT';
    $fields['title'] = 'QA HTTP corregido '.bin2hex(random_bytes(4));
    $updatedBytes = $bytes."\nqa-http-updated";
    // En PHP los formularios de archivos usan POST con _method=PUT.
    // Las peticiones JSON de edición usan PUT directamente.
    $updated = smokeUpload('/api/v1/eventos/'.$event->id, $fields, $updatedBytes, $secondary, $owner['token']);
    smokeCheck($updated->status() === 200, 'El reenvío multipart con _method=PUT no devolvió 200; HTTP '.$updated->status());
    smokeCheck($updated->json('id') === (string) $event->id, 'El reenvío creó otro evento.');
    $event->refresh();
    smokeCheck($event->title === $fields['title'] && $event->status === Event::STATUS_PENDING_APPROVAL,
        'La corrección no quedó pendiente con el nuevo título.');
    smokeCheck($event->rejection_reason === null && $event->reviewed_by === null && $event->reviewed_at === null,
        'No se limpiaron los datos de revisión al reenviar.');
    smokeCheck(base64_decode($event->banner->content, true) === $updatedBytes, 'No se actualizó el JPEG.');
    smokeCheck($event->banner()->count() === 1, 'Se duplicó el registro de banners.');
    echo 'PASS: reenvío HTTP multipart mediante POST con _method=PUT.'.PHP_EOL;

    unset($fields['_method']);
    $fields['startsAt'] = $date->addDays(1)->toIso8601String();
    $count = Event::count();
    $invalid = smokeUpload('/api/events', $fields, $bytes, 'not-an-image', $owner['token'], true);
    smokeCheck($invalid->status() === 422, 'El archivo inválido debe responder 422.');
    smokeCheck($invalid->json('errors.secondaryBanner') !== null, 'El error debe identificar secondaryBanner.');
    smokeCheck(Event::count() === $count, 'La petición inválida creó un evento.');
    echo 'PASS: segundo archivo inválido rechazado sin crear evento.'.PHP_EOL;
    echo 'PASS: HTTP real de archivos; '.$checks.' comprobaciones. Solo datos ficticios en ticketing_qa.'.PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
