<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'zoneId' => ['required_without:items', 'prohibits:items', 'integer', 'min:1'],
            'quantity' => ['required_with:zoneId', 'prohibits:items', 'integer', 'min:1', 'max:6'],
            'items' => ['required_without:zoneId', 'prohibits:zoneId,quantity', 'array', 'min:1', 'max:6'],
            'items.*.zoneId' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:6'],
            'payment' => ['required', 'array'],
            'payment.cardNumber' => ['required', 'string', 'regex:/\A[0-9]{13,19}\z/'],
            'payment.cvv' => ['required', 'string', 'regex:/\A[0-9]{3,4}\z/'],
            'payment.expiresAt' => ['required', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->has('items')
                && array_sum(array_column($this->input('items'), 'quantity')) > 6) {
                $validator->errors()->add('items', 'El máximo es de 6 entradas por compra entre todas las zonas.');
            }
        });
    }
}
