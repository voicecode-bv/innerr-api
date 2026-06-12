<?php

namespace Database\Factories;

use App\Enums\PrintOrderStatus;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintOrder>
 */
class PrintOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'shipping_address' => [
                'firstName' => $this->faker->firstName(),
                'lastName' => $this->faker->lastName(),
                'street' => $this->faker->streetName(),
                'houseNumber' => (string) $this->faker->buildingNumber(),
                'postalCode' => '1234AB',
                'city' => $this->faker->city(),
                'country' => 'NL',
            ],
            'amount_minor' => 2495,
            'currency' => 'EUR',
            'status' => PrintOrderStatus::PendingPayment,
        ];
    }

    /** One album line item, mirroring how the controller creates orders. */
    public function withItem(): static
    {
        return $this->has(PrintOrderItem::factory(), 'items');
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PrintOrderStatus::Paid,
            'mollie_payment_id' => 'tr_'.$this->faker->lexify('??????????'),
        ]);
    }

    public function submitted(): static
    {
        return $this->paid()->state(fn (): array => [
            'status' => PrintOrderStatus::Submitted,
            'printdeal_order_id' => $this->faker->uuid(),
            'printdeal_order_number' => 'DDB'.$this->faker->numerify('##########'),
        ]);
    }
}
