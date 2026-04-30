<?php

namespace App\Services\Subscriptions\Dto;

use App\Models\User;

final readonly class VerifyPurchaseRequest
{
    public function __construct(
        public User $user,
        public string $token,
        public ?string $productId = null,
        /** @var array<string, mixed> */
        public array $context = [],
    ) {}
}
