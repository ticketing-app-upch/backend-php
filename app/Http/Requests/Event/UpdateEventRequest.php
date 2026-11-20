<?php

namespace App\Http\Requests\Event;

use App\Models\Event;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends StoreEventRequest
{
    public function authorize(): bool
    {
        $event = Event::find($this->route('id'));

        abort_unless($event, 404, 'Evento no encontrado.');

        $user = $this->user('api');

        abort_unless(
            $user !== null
                && $user->role->name === 'ORGANIZER'
                && (string) $event->organizer_id === (string) $user->id,
            403,
            'No tienes permiso para editar este evento.',
        );

        abort_unless(
            $user->active,
            403,
            'Tu cuenta está desactivada. Contacta al administrador',
        );

        abort_unless(
            $event->status === Event::STATUS_REJECTED,
            403,
            'Solo se pueden editar eventos rechazados.',
        );

        return true;
    }

    public function rules(): array
    {
        $rules = parent::rules();

        if ($this->usesFrontendContract()) {
            $rules['id'] = [
                'nullable',
                'string',
                Rule::in([(string) $this->route('id')]),
            ];

            $rules['status'] = [
                'sometimes',
                'string',
                Rule::in([
                    'PUBLICADO',
                    'RECHAZADO',
                    'PENDIENTE',
                    'PENDIENTE_APROBACION',
                ]),
            ];

            $rules['publishedAt'] = [
                'sometimes',
                'nullable',
                'date',
            ];
        }

        return $rules;
    }
}
