<?php

namespace App\Services\Printdeal;

/**
 * Helpers for building v2 attribute selections ([{attribute, value}, ...]).
 */
class PrintdealAttributes
{
    /**
     * v2 has no separate quantity field: it travels as the `quantity`
     * attribute. Adds it unless the given attributes already pin one.
     *
     * @param  array<int, array{attribute: string, value: mixed}>  $attributes
     * @return array<int, array{attribute: string, value: mixed}>
     */
    public static function withQuantity(array $attributes, int $quantity): array
    {
        $hasQuantity = collect($attributes)->contains(
            fn (array $attribute): bool => strcasecmp((string) $attribute['attribute'], 'quantity') === 0,
        );

        if (! $hasQuantity) {
            $attributes[] = ['attribute' => 'quantity', 'value' => (string) $quantity];
        }

        return $attributes;
    }
}
