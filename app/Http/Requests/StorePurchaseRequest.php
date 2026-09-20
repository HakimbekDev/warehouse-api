<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'storage_id' => ['required', 'integer', 'exists:storages,id'],
            'purchased_at' => ['nullable', 'date'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.purchase_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
