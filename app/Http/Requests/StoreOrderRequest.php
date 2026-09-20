<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'ordered_at' => ['nullable', 'date'],

            // No batch_id here on purpose — the backend picks the batches.
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
