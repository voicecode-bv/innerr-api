<?php

namespace Database\Factories;

use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintOrderItem>
 */
class PrintOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'print_order_id' => PrintOrder::factory(),
            'app_product' => 'album',
            'name' => ['en-EN' => 'Photo album', 'nl-NL' => 'Fotoalbum'],
            'printdeal_sku' => $this->faker->uuid(),
            'printdeal_attributes' => [
                ['attribute' => 'Format', 'value' => 'A4'],
                ['attribute' => 'Printing Colors', 'value' => '4/4 Full Color'],
            ],
            'options' => null,
            'photos' => [
                [
                    'post_id' => $this->faker->uuid(),
                    'media_id' => $this->faker->uuid(),
                    'path' => 'users/'.$this->faker->uuid().'/photo.jpg',
                ],
            ],
            'amount_minor' => 2495,
        ];
    }

    public function tshirt(string $size = 'M'): static
    {
        return $this->state(fn (): array => [
            'app_product' => 'tshirt',
            'name' => ['en-EN' => 'Basic T-shirt', 'nl-NL' => 'Basic T-shirt'],
            'options' => ['Size' => $size],
            'amount_minor' => 1995,
        ]);
    }
}
