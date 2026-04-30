<?php

namespace App\Services\Subscriptions\Dto;

use App\Models\Price;
use App\Models\User;

final readonly class CreateCheckoutRequest
{
    public function __construct(
        public User $user,
        public Price $price,
        public string $redirectUrl,
        /** @var array<string, mixed> */
        public array $context = [],
    ) {}
}
