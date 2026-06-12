<?php

namespace Database\Factories;

use App\Models\PrintdealProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintdealProduct>
 */
class PrintdealProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'sku' => $this->faker->uuid(),
            'name' => ['en-EN' => ucfirst($name), 'nl-NL' => ucfirst($name)],
            'synced_at' => now(),
            'enabled' => false,
            'currency' => 'EUR',
        ];
    }

    /** Fully configured to back an app product with a fixed selling price. */
    public function offered(string $appProduct, int $fixedPriceMinor = 2495): static
    {
        return $this->state(fn (): array => [
            'enabled' => true,
            'app_product' => $appProduct,
            'order_attributes' => [
                ['attribute' => 'Format', 'value' => 'A4'],
                ['attribute' => 'Printing Colors', 'value' => '4/4 Full Color'],
            ],
            'sizes' => $appProduct === 'tshirt' ? ['S', 'M', 'L', 'XL', 'XXL'] : null,
            'fixed_price_minor' => $fixedPriceMinor,
        ]);
    }

    public function delisted(): static
    {
        return $this->state(fn (): array => ['delisted_at' => now()]);
    }
}
