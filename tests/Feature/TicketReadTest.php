<?php

namespace Tests\Feature;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsDemoThreeFixtures;
use Tests\TestCase;

class TicketReadTest extends TestCase
{
    use BuildsDemoThreeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_list_and_detail_are_owned_and_public_confirmation_contains_no_buyer_data(): void
    {
        $event = $this->publishedEvent();
        $buyer = $this->actor();
        $other = $this->actor();
        $purchase = $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $event->zones->first()->id, 'quantity' => 3, 'payment' => $this->payment(),
        ], $this->headersFor($buyer))->assertCreated();
        $ticket = $purchase->json('tickets.0');
        $this->getJson('/api/v1/tickets', $this->headersFor($buyer))->assertOk()->assertJsonCount(3);
        $this->getJson('/api/v1/tickets/'.$ticket['id'], $this->headersFor($buyer))
            ->assertOk()->assertJsonPath('code', $ticket['code']);
        $this->getJson('/api/v1/tickets', $this->headersFor($other))->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/tickets/'.$ticket['id'], $this->headersFor($other))->assertNotFound();
        $this->getJson('/api/v1/tickets/'.$ticket['code'].'/validacion')
            ->assertOk()->assertJsonPath('message', 'Confirmación de compra')
            ->assertJsonPath('ticket.quantity', 1)->assertJsonPath('ticket.code', $ticket['code'])
            ->assertJsonMissingPath('ticket.email')->assertJsonMissingPath('ticket.user_id')
            ->assertJsonMissingPath('ticket.payment');
        $this->getJson('/validar/'.$ticket['code'])->assertOk()->assertJsonPath('ticket.code', $ticket['code']);
    }

    public function test_ticket_snapshots_remain_stable_when_related_rows_change(): void
    {
        $event = $this->publishedEvent();
        $zone = $event->zones->first();
        $buyer = $this->actor();
        $purchase = $this->postJson('/api/events/'.$event->id.'/tickets', [
            'zoneId' => $zone->id, 'quantity' => 1, 'payment' => $this->payment(),
        ], $this->headersFor($buyer))->assertCreated();
        $ticket = $purchase->json('tickets.0');
        DB::table('events')->where('id', $event->id)->update(['title' => 'Otro título', 'starts_at' => now()->addYears(2)]);
        DB::table('event_zones')->where('id', $zone->id)->update(['name' => 'Otra zona', 'base_price' => 999]);
        $this->getJson('/api/v1/tickets/'.$ticket['id'], $this->headersFor($buyer))
            ->assertOk()->assertJsonPath('event.title', $ticket['event']['title'])
            ->assertJsonPath('event.startsAt', $ticket['event']['startsAt'])
            ->assertJsonPath('zone.name', $ticket['zone']['name'])->assertJsonPath('unitPrice', 50);

        $this->expectException(\LogicException::class);
        Ticket::findOrFail($ticket['id'])->update(['unit_price' => 999]);
    }

    public function test_unknown_public_code_returns_404_and_private_list_requires_client_role(): void
    {
        $this->getJson('/api/v1/tickets/missing/validacion')->assertNotFound()
            ->assertExactJson(['message' => 'Ticket no encontrado']);
        $this->getJson('/validar/missing')->assertNotFound();
        $this->getJson('/api/v1/tickets')->assertUnauthorized();
        foreach (['ORGANIZER', 'ADMIN'] as $role) {
            $this->getJson('/api/v1/tickets', $this->headersFor($this->actor($role)))->assertForbidden();
        }
    }
}
