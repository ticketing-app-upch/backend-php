<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class VirtualQueue
{
    public const INVALID_PASS = 'Tu pase de compra no es válido o ha vencido.';

    public const PURCHASES_DISABLED = 'Las compras de este evento aún no han sido habilitadas';

    /** Call while holding the event row lock. */
    public function turn(Event $event, string $action, ?string $userId = null, array $data = []): array
    {
        $this->syncPurchases($event);

        return $this->send($action, [
            'eventId' => (string) $event->id,
            'userId' => $userId,
            'salesEnabled' => $event->purchases_enabled_at !== null,
            'salesClosed' => ! $event->starts_at->isFuture() || $this->isSoldOut($event),
            ...$data,
        ]);
    }

    /** Call before pricing or changing stock, while holding the event row lock. */
    public function authorizePurchase(Event $event, string $userId, ?string $pass): ?array
    {
        if (! $event->queue_enabled) {
            return null;
        }

        abort_if($event->purchases_enabled_at === null, 403, self::PURCHASES_DISABLED);
        abort_unless($pass, 403, self::INVALID_PASS);

        $publicKey = base64_decode((string) config('ticketing.virtual_queue.pass_public_key'), true);
        $key = $publicKey ? openssl_pkey_get_public($publicKey) : false;
        $keyDetails = $key ? openssl_pkey_get_details($key) : false;
        abort_unless(
            $keyDetails && $keyDetails['type'] === OPENSSL_KEYTYPE_RSA && $keyDetails['bits'] >= 2048,
            503,
            'La cola virtual no está disponible.',
        );

        try {
            $token = (new Parser(new JoseEncoder))->parse($pass);
            $validSignature = (new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText($publicKey)));
        } catch (JwtException|\TypeError) {
            abort(403, self::INVALID_PASS);
        }

        abort_unless($token instanceof UnencryptedToken && $validSignature, 403, self::INVALID_PASS);

        $claims = $token->claims();
        $turnId = $claims->get('jti', '');
        $issued = $claims->get('iat');
        $expires = $claims->get('exp');

        abort_unless(
            $claims->get('iss') === 'alpateck-virtual-queue'
                && $claims->get('aud') === ['alpateck-purchase']
                && $claims->get('sub') === $userId
                && $claims->get('eventId') === (string) $event->id
                && is_string($turnId) && Str::isUuid($turnId)
                && $issued !== null && $issued->getTimestamp() <= now()->getTimestamp() && $expires !== null
                && $expires->getTimestamp() - $issued->getTimestamp() === 180
                && $expires > now()
                && ! DB::table('queue_purchases')->where('turn_id', $turnId)->lockForUpdate()->first(),
            403,
            self::INVALID_PASS,
        );

        $state = $this->turn($event, 'validate', $userId, ['turnId' => $turnId]);
        abort_unless(($state['valid'] ?? false) === true, 503, 'La cola virtual no está disponible.');

        return ['id' => $turnId, 'expires' => $expires];
    }

    /** Persist completion with the tickets so a lost response cannot reuse the pass. */
    public function recordPurchase(Event $event, int $orderId, array $turn): void
    {
        abort_if($turn['expires'] <= now() || ! $event->starts_at->isFuture(), 403, self::INVALID_PASS);

        DB::table('queue_purchases')->insert([
            'turn_id' => $turn['id'],
            'event_id' => $event->id,
            'ticket_order_id' => $orderId,
            'sold_out' => $this->isSoldOut($event),
        ]);
    }

    public function notifyCommittedChange(int $eventId, bool $advance = false): void
    {
        try {
            DB::transaction(function () use ($eventId, $advance) {
                $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
                if ($advance) {
                    $this->turn($event, 'advance');
                } else {
                    $this->syncPurchases($event);
                }
            }, 3);
        } catch (Throwable $exception) {
            // Queue requests retry committed completions and read current sales state.
            report($exception);
        }
    }

    public function conversion(Collection $eventIds): array
    {
        $completed = 0;
        $expired = 0;
        $cancelled = 0;

        foreach ($eventIds->chunk(100) as $batch) {
            $ids = $batch->values()->all();
            try {
                $response = $this->send('conversion', ['eventIds' => $ids]);
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 503) {
                    throw $exception;
                }

                return ['available' => false, 'completed' => null, 'expired' => null, 'cancelled' => null,
                    'conversionPercentage' => null, 'reason' => 'La cola virtual no está disponible.'];
            }

            if (! is_array($response['outcomes'] ?? null)) {
                return ['available' => false, 'completed' => null, 'expired' => null, 'cancelled' => null,
                    'conversionPercentage' => null, 'reason' => 'La cola virtual devolvió una respuesta incompleta.'];
            }
            $completed += DB::table('queue_purchases')->whereIn('event_id', $ids)->count();
            $outcomes = collect($response['outcomes'])->keyBy('turnId');
            foreach ($outcomes->keys()->chunk(500) as $turnIds) {
                $purchasedTurns = DB::table('queue_purchases')->whereIn('turn_id', $turnIds->all())->pluck('turn_id');
                $outcomes->forget($purchasedTurns->all());
            }
            $expired += $outcomes->where('status', 'EXPIRED')->count();
            $cancelled += $outcomes->where('status', 'CANCELLED')->count();
        }

        $total = $completed + $expired + $cancelled;

        return [
            'available' => $total > 0, 'completed' => $completed, 'expired' => $expired, 'cancelled' => $cancelled,
            'conversionPercentage' => $total > 0 ? round($completed * 100 / $total, 2) : null,
            'reason' => $total === 0 ? 'Todavía no hay turnos finalizados para los eventos seleccionados.' : null,
        ];
    }

    private function isSoldOut(Event $event): bool
    {
        return $event->zones()->where('available_capacity', '>', 0)->orderBy('id')->lockForUpdate()->first(['id']) === null;
    }

    private function syncPurchases(Event $event): void
    {
        $pending = DB::table('queue_purchases')->where('event_id', $event->id)
            ->whereNull('synced_at')->orderBy('ticket_order_id')->lockForUpdate()->get();

        foreach ($pending as $purchase) {
            $result = $this->send('complete', [
                'eventId' => (string) $event->id,
                'turnId' => $purchase->turn_id,
                'soldOut' => (bool) $purchase->sold_out,
            ]);
            abort_unless(($result['recorded'] ?? false) === true, 503, 'La cola virtual no está disponible.');

            DB::table('queue_purchases')->where('turn_id', $purchase->turn_id)->update(['synced_at' => now()]);
        }
    }

    private function send(string $action, array $payload): array
    {
        $url = config('ticketing.virtual_queue.url');
        $token = config('ticketing.virtual_queue.service_token');
        abort_unless($url && is_string($token) && strlen($token) >= 32, 503, 'La cola virtual no está disponible.');

        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()->connectTimeout(1)->timeout(2)
                ->post(rtrim($url, '/').'/v1/turns/'.$action, $payload);
        } catch (ConnectionException) {
            abort(503, 'La cola virtual no está disponible.');
        }

        if (! in_array($action, ['complete', 'conversion'], true) && in_array($response->status(), [403, 404, 409], true)) {
            abort($response->status(), (string) $response->json('message', 'No pudimos consultar tu turno.'));
        }

        abort_unless($response->successful() && is_array($response->json()), 503, 'La cola virtual no está disponible.');

        return $response->json();
    }
}
