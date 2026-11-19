<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\AntiScalping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class AntiScalpingTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    private const SERVICE = 'http://queue.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        config(['ticketing.virtual_queue' => [
            'url' => self::SERVICE,
            'service_token' => Str::random(48),
        ]]);
        Http::preventStrayRequests();
    }

    private function order(Event $event, array $overrides = []): array
    {
        return array_replace([
            'eventId' => (string) $event->id,
            'items' => [['zoneId' => (string) $event->zones->first()->id, 'quantity' => 2]],
            'timeElapsedSeconds' => 45,
        ], $overrides);
    }

    public function test_block_response_aborts_with_429_and_creates_no_order(): void
    {
        Http::fake([
            self::SERVICE.'/api/v1/risk-score' => Http::response(['risk_score' => 85, 'action' => 'BLOCK']),
        ]);

        $this->postJson('/api/orders', $this->order($this->publishedEvent()), $this->headersFor($this->actor()))
            ->assertStatus(429)
            ->assertJsonPath('message', AntiScalping::BLOCKED_MESSAGE);

        $this->assertDatabaseCount('ticket_orders', 0);
        Http::assertSent(fn ($request) => $request->url() === self::SERVICE.'/api/v1/risk-score'
            && $request['time_elapsed_seconds'] === 45.0
            && $request['tickets_requested'] === 2
            && $request['account_age_hours'] >= 0);
    }

    public function test_allow_response_completes_the_purchase(): void
    {
        Http::fake([
            self::SERVICE.'/api/v1/risk-score' => Http::response(['risk_score' => 15, 'action' => 'ALLOW']),
        ]);

        $this->postJson('/api/orders', $this->order($this->publishedEvent()), $this->headersFor($this->actor()))
            ->assertCreated();

        $this->assertDatabaseCount('ticket_orders', 1);
    }

    public function test_legacy_purchase_route_also_blocks_on_risk(): void
    {
        Http::fake([
            self::SERVICE.'/api/v1/risk-score' => Http::response(['risk_score' => 99, 'action' => 'BLOCK']),
        ]);

        $event = $this->publishedEvent();
        $this->postJson('/api/events/'.$event->id.'/tickets', [
            'items' => [['zoneId' => $event->zones->first()->id, 'quantity' => 1]],
            'payment' => $this->payment(),
            'timeElapsedSeconds' => 1,
        ], $this->headersFor($this->actor()))
            ->assertStatus(429)
            ->assertJsonPath('message', AntiScalping::BLOCKED_MESSAGE);

        $this->assertDatabaseCount('ticket_orders', 0);
    }

    public function test_engine_outage_fails_open(): void
    {
        Http::fake([
            self::SERVICE.'/api/v1/risk-score' => Http::response('', 503),
        ]);

        $this->postJson('/api/orders', $this->order($this->publishedEvent()), $this->headersFor($this->actor()))
            ->assertCreated();

        $this->assertDatabaseCount('ticket_orders', 1);
    }

    public function test_connection_exception_fails_open(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Render cold start');
        });

        $this->postJson('/api/orders', $this->order($this->publishedEvent()), $this->headersFor($this->actor()))
            ->assertCreated();

        $this->assertDatabaseCount('ticket_orders', 1);
    }

    public function test_missing_time_field_defaults_to_zero_and_still_checks(): void
    {
        Http::fake([
            self::SERVICE.'/api/v1/risk-score' => Http::response(['risk_score' => 70, 'action' => 'ALLOW']),
        ]);

        $payload = $this->order($this->publishedEvent());
        unset($payload['timeElapsedSeconds']);

        $this->postJson('/api/orders', $payload, $this->headersFor($this->actor()))->assertCreated();
        Http::assertSent(fn ($request) => $request['time_elapsed_seconds'] === 0.0);
    }

    public function test_unconfigured_environment_skips_the_engine(): void
    {
        config(['ticketing.virtual_queue.url' => null, 'ticketing.virtual_queue.service_token' => null]);

        $this->postJson('/api/orders', $this->order($this->publishedEvent()), $this->headersFor($this->actor()))
            ->assertCreated();

        Http::assertNothingSent();
    }
}
