<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\VirtualQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class VirtualQueueTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    private const SERVICE = 'http://queue.example.test';

    private Configuration $signing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $this->signing = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($private), InMemory::plainText($public));
        config(['ticketing.virtual_queue' => [
            'url' => self::SERVICE,
            'service_token' => Str::random(48),
            'pass_public_key' => base64_encode($public),
        ]]);
        Http::preventStrayRequests();
    }

    public function test_owner_can_enable_sales_once_and_clients_can_queue_before_that(): void
    {
        $owner = $this->actor('ORGANIZER');
        $client = $this->actor();
        $event = $this->publishedEvent(['queue_enabled' => true, 'organizer_id' => $owner->id]);
        $path = '/api/v1/eventos/'.$event->id;
        Http::fake([
            self::SERVICE.'/v1/turns/join' => Http::response(['status' => 'WAITING', 'salesEnabled' => false]),
            self::SERVICE.'/v1/turns/advance' => Http::response([]),
        ]);

        $this->postJson($path.'/cola')->assertUnauthorized();
        $this->postJson($path.'/cola', [], $this->headersFor($owner))->assertForbidden();
        $this->postJson($path.'/cola', ['userId' => '9999'], $this->headersFor($client))
            ->assertOk()->assertJsonPath('status', 'WAITING')
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => $request->url() === self::SERVICE.'/v1/turns/join'
            && $request['userId'] === (string) $client->id
            && $request['salesEnabled'] === false);

        $this->patchJson($path.'/habilitar-compras', [], $this->headersFor($this->actor('ORGANIZER')))->assertForbidden();
        $this->patchJson($path.'/habilitar-compras', [], $this->headersFor($owner))
            ->assertOk()->assertJsonPath('purchasesEnabled', true)->assertJsonPath('name', $event->title);
        $this->patchJson($path.'/habilitar-compras', [], $this->headersFor($owner))->assertConflict();
        $this->getJson('/api/events/'.$event->id)->assertOk()->assertJsonPath('purchasesEnabled', true);
    }

    public function test_both_purchase_routes_reject_disabled_missing_wrong_and_expired_passes(): void
    {
        $event = $this->publishedEvent(['queue_enabled' => true]);
        $buyer = $this->actor();
        $order = ['eventId' => $event->id, 'items' => [['zoneId' => $event->zones->first()->id, 'quantity' => 1]]];
        $legacy = ['items' => $order['items'], 'payment' => $this->payment()];
        $legacyPath = '/api/events/'.$event->id.'/tickets';

        $this->postJson('/api/orders', $order, $this->headersFor($buyer))->assertForbidden()
            ->assertJsonPath('message', VirtualQueue::PURCHASES_DISABLED);
        $this->postJson($legacyPath, $legacy, $this->headersFor($buyer))->assertForbidden();
        $event->forceFill(['purchases_enabled_at' => now()])->save();
        $this->postJson('/api/orders', $order, $this->headersFor($buyer))->assertForbidden();
        $this->postJson($legacyPath, $legacy + ['queuePass' => '!.!.!'], $this->headersFor($buyer))->assertForbidden();
        $wrongUser = $this->pass($event, $this->actor()->id, (string) Str::uuid());
        $this->postJson('/api/orders', $order + ['queuePass' => $wrongUser], $this->headersFor($buyer))->assertForbidden();
        $wrongEvent = $this->pass($this->publishedEvent(), $buyer->id, (string) Str::uuid());
        $this->postJson($legacyPath, $legacy + ['queuePass' => $wrongEvent], $this->headersFor($buyer))->assertForbidden();
        $expired = $this->pass($event, $buyer->id, (string) Str::uuid(), -180);
        $this->postJson($legacyPath, $legacy + ['queuePass' => $expired], $this->headersFor($buyer))->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ticket_orders', 0);
        $this->assertSame(100, $event->zones->first()->fresh()->available_capacity);
    }

    public function test_confirmed_purchase_consumes_the_pass_even_if_the_service_goes_down(): void
    {
        $event = $this->publishedEvent(['queue_enabled' => true]);
        $event->forceFill(['purchases_enabled_at' => now()])->save();
        $buyer = $this->actor();
        $turnId = (string) Str::uuid();
        $path = '/api/v1/eventos/'.$event->id;
        $payload = [
            'eventId' => $event->id,
            'items' => [['zoneId' => $event->zones->first()->id, 'quantity' => 1]],
            'expectedTotal' => 50,
            'queuePass' => $this->pass($event, $buyer->id, $turnId),
            'idempotencyKey' => 'queue-checkout',
        ];
        Http::fake([
            self::SERVICE.'/v1/turns/validate' => Http::response(['valid' => true]),
            self::SERVICE.'/v1/turns/complete' => Http::sequence()->pushStatus(503)->push(['recorded' => true]),
            self::SERVICE.'/v1/turns/status' => Http::response(['status' => 'COMPLETED']),
        ]);

        $this->postJson('/api/orders', $payload + ['paymentResult' => 'DECLINED'], $this->headersFor($buyer))->assertStatus(402);
        $this->assertDatabaseCount('queue_purchases', 0);
        $order = $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertCreated()
            ->assertJsonPath('fee', 0)->assertJsonPath('total', 50)->assertJsonCount(1, 'tickets');
        $this->assertDatabaseHas('queue_purchases', ['turn_id' => $turnId, 'synced_at' => null]);
        $this->postJson('/api/orders', $payload, $this->headersFor($buyer))->assertOk()->assertExactJson($order->json());
        $this->postJson('/api/orders', array_replace($payload, ['idempotencyKey' => 'another-order']), $this->headersFor($buyer))
            ->assertForbidden();
        $this->assertDatabaseCount('tickets', 1);
        $this->getJson($path.'/cola/estado', $this->headersFor($buyer))->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->assertDatabaseMissing('queue_purchases', ['turn_id' => $turnId, 'synced_at' => null]);
    }

    private function pass(Event $event, int $userId, string $turnId, int $issuedOffset = 0): string
    {
        $issuedAt = now()->addSeconds($issuedOffset)->toDateTimeImmutable();

        return $this->signing->builder()->issuedBy('alpateck-virtual-queue')->permittedFor('alpateck-purchase')
            ->relatedTo((string) $userId)->identifiedBy($turnId)->withClaim('eventId', (string) $event->id)
            ->issuedAt($issuedAt)->expiresAt($issuedAt->modify('+180 seconds'))
            ->getToken($this->signing->signer(), $this->signing->signingKey())->toString();
    }
}
