Compra confirmada

Evento: {{ $eventName }}
Fecha y hora: {{ $eventDate }} (hora de Lima)
Lugar: {{ $eventVenue }}
Entradas: {{ $quantity }}

@foreach ($lines as $line)
{{ $line['zoneName'] }}: {{ $line['quantity'] }}
@endforeach

Consulta tus entradas en Mis entradas.
