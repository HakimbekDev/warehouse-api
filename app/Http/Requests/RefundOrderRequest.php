<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'refunded_at' => ['nullable', 'date'],

            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
