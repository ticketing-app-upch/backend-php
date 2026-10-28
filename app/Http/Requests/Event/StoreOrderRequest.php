<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'eventId' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*.zoneId' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:6'],
            'expectedTotal' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'paymentMethod' => ['sometimes', 'in:CARD,WALLET'],
            'paymentResult' => ['sometimes', 'in:APPROVED,DECLINED'],
            'idempotencyKey' => ['sometimes', 'string', 'min:1', 'max:128'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty() && array_sum(array_column($this->input('items'), 'quantity')) > 6) {
                $validator->errors()->add('items', 'El máximo es de 6 entradas por compra entre todas las zonas.');
            }
        });
    }
}
