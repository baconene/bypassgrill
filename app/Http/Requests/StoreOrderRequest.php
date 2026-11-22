<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'client_id' => 'nullable|string|max:100',
            'order_type' => 'required|in:dine_in,takeout,delivery',
            'table_number' => 'nullable|string',
            'customer_name' => 'nullable|string|max:100',
            'customer_contact' => 'nullable|string|max:50',
            'customer_address' => 'nullable|string|max:300',
            'notes' => 'nullable|string|max:500',
            'discount_amount' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.modifiers' => 'nullable|array',
            'items.*.modifiers.*' => 'integer|exists:modifiers,id',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Order must contain at least one item',
            'items.*.product_id.exists' => 'Selected product does not exist',
            'order_type.in' => 'Invalid order type',
        ];
    }
}
