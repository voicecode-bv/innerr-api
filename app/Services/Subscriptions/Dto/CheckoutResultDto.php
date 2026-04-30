<?php

namespace App\Services\Subscriptions\Dto;

final readonly class CheckoutResultDto
{
    public function __construct(
        public string $checkoutUrl,
        public ?string $channelCustomerId = null,
        public ?string $externalReference = null,
    ) {}
}
