<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyGooglePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'purchase_token' => ['required', 'string', 'max:4096'],
            'product_id' => ['required', 'string', 'max:255'],
        ];
    }
}
