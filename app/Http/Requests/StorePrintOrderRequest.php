<?php

namespace App\Http\Requests;

use App\Models\PrintdealProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        return [
            'items' => ['required', 'array', 'min:1', 'max:10'],
            // Deliberately not distinct: the same offering may appear twice
            // (two puzzles in different sizes, two mugs with other photos).
            'items.*.offering_id' => ['required', 'uuid'],
            'items.*.photos' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.photos.*.post_id' => ['required', 'uuid'],
            'items.*.photos.*.media_id' => ['required', 'uuid'],
            'items.*.options' => ['sometimes', 'array'],
            'items.*.options.*' => ['string', 'max:100'],

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

            // Opt-in: persist this address on the user for the next order.
            'save_address' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Per-item rules that depend on the offering: photo-count limits come
     * from the app-product config, and the options must match the offering's
     * user options exactly (every option chosen, every value allowed).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $offerings = $this->offerings();

                foreach ($this->input('items', []) as $index => $item) {
                    $offering = $offerings->get($item['offering_id'] ?? '');

                    if ($offering === null) {
                        continue; // The controller reports these as unavailable.
                    }

                    $config = config("print.products.{$offering->app_product}");
                    $photoCount = count($item['photos'] ?? []);

                    if ($config !== null && ($photoCount < $config['min_photos'] || $photoCount > $config['max_photos'])) {
                        $validator->errors()->add(
                            "items.{$index}.photos",
                            "This product needs between {$config['min_photos']} and {$config['max_photos']} photos.",
                        );
                    }

                    foreach ($offering->optionErrors($item['options'] ?? []) as $attribute => $message) {
                        $validator->errors()->add(
                            "items.{$index}.options.{$attribute}",
                            $message,
                        );
                    }
                }
            },
        ];
    }

    /**
     * The requested offerings, keyed by id. Memoized: the after-rules and
     * the controller both need them.
     *
     * @return Collection<string, PrintdealProduct>
     */
    public function offerings(): Collection
    {
        return once(fn (): Collection => PrintdealProduct::query()
            ->whereIn('id', collect($this->input('items', []))->pluck('offering_id')->filter())
            ->get()
            ->keyBy('id'));
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
