<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMollieCheckoutRequest extends FormRequest
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
            'price_id' => [
                'required', 'uuid',
                Rule::exists('prices', 'id')
                    ->where('channel', SubscriptionChannel::Mollie->value)
                    ->where('is_active', true),
            ],
            'redirect_url' => ['required', 'url', 'max:2048'],
        ];
    }
}
