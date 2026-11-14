<?php

namespace App\Mail;

use App\Models\TicketOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PurchaseConfirmed extends Mailable
{
    public function __construct(private TicketOrder $order) {}

    public static function sendAfterCommit(TicketOrder $order, string $email): void
    {
        DB::afterCommit(function () use ($order, $email): void {
            try {
                Mail::to($email)->send(new self($order));
            } catch (Throwable $exception) {
                Log::error('No se pudo enviar el correo de confirmación de compra.', [
                    'order_id' => $order->id,
                    'exception_type' => $exception::class,
                ]);
            }
        });
    }

    public function build(): self
    {
        return $this->subject('Compra confirmada')->text('mail.purchase-confirmed', [
            'eventName' => $this->order->event_name,
            'eventDate' => $this->order->event_starts_at->copy()->setTimezone(config('ticketing.venue_timezone'))->format('d/m/Y H:i'),
            'eventVenue' => $this->order->event_venue,
            'quantity' => array_sum(array_column($this->order->lines, 'quantity')),
            'lines' => $this->order->lines,
        ]);
    }
}
