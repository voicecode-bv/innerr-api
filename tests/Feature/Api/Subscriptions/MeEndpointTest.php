<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Plan::factory()->free()->create();
});

it('rejects guests', function () {
    $this->getJson('/api/subscription/me')->assertUnauthorized();
});

it('returns the free plan for users without a subscription', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/subscription/me')
        ->assertOk()
        ->assertJsonPath('plan.slug', 'free')
        ->assertJsonPath('is_paid', false)
        ->assertJsonPath('subscription', null);
});

it('returns active subscription details for paid users', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->for($plus)->active()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/subscription/me')
        ->assertOk()
        ->assertJsonPath('plan.slug', 'plus')
        ->assertJsonPath('is_paid', true)
        ->assertJsonPath('subscription.status', 'active');
});
