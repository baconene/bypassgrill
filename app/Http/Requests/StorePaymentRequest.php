<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest {
    public function authorize(): bool {
        return auth()->check() && auth()->user()->can('process payments');
    }
    public function rules(): array {
        return [
            'client_id' => 'nullable|string|max:100',
            'order_id'          => 'required|integer|exists:orders,id',
            'amount'            => 'required|numeric|min:0.01',
            'payment_tender_id' => 'required|exists:payment_tenders,id',
            'reference'         => 'nullable|string|max:100',
            'notes'             => 'nullable|string|max:500',
        ];
    }
}
