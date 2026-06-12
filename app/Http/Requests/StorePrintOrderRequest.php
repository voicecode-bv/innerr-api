<?php

namespace App\Http\Requests;

use App\Models\PrintdealProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrintOrderRequest extends FormRequest
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
        $products = config('print.products');
        $product = $products[$this->input('product')] ?? null;
        $sizes = $this->offering()?->sizes ?? [];

        return [
            'product' => ['required', 'string', Rule::in(array_keys($products))],

            'photos' => [
                'required', 'array',
                'min:'.($product['min_photos'] ?? 1),
                'max:'.($product['max_photos'] ?? 50),
            ],
            'photos.*.post_id' => ['required', 'uuid'],
            'photos.*.media_id' => ['required', 'uuid'],

            'options' => ['sometimes', 'array'],
            'options.size' => [
                Rule::requiredIf($sizes !== []),
                Rule::in($sizes),
            ],

            'shipping_address' => ['required', 'array'],
            'shipping_address.firstName' => ['required', 'string', 'max:100'],
            'shipping_address.lastName' => ['required', 'string', 'max:100'],
            'shipping_address.street' => ['required', 'string', 'max:200'],
            'shipping_address.houseNumber' => ['required', 'string', 'max:20'],
            'shipping_address.houseNumberAddition' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shipping_address.postalCode' => ['required', 'string', 'max:20'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.country' => [
                'required', Rule::in(config('print.shipping_countries')),
            ],

            'redirect_url' => ['required', 'url', 'max:2048'],
        ];
    }

    /**
     * The admin-configured offering that currently backs the requested app
     * product. Memoized: rules() and the controller both need it.
     */
    public function offering(): ?PrintdealProduct
    {
        return once(fn (): ?PrintdealProduct => PrintdealProduct::offeredFor(
            (string) $this->input('product'),
        ));
    }

    /**
     * Strip unknown keys from the address so the stored snapshot only ever
     * contains fields the Printdeal order schema accepts.
     *
     * @return array<string, string>
     */
    public function shippingAddress(): array
    {
        $address = $this->validated('shipping_address');

        return array_filter([
            'firstName' => $address['firstName'],
            'lastName' => $address['lastName'],
            'street' => $address['street'],
            'houseNumber' => $address['houseNumber'],
            'houseNumberAddition' => $address['houseNumberAddition'] ?? null,
            'postalCode' => $address['postalCode'],
            'city' => $address['city'],
            'country' => $address['country'],
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
