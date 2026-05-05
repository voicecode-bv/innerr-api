<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Plan::factory()->free()->create();
});

it('rejects guests', function () {
    $this->getJson('/api/account/storage')->assertUnauthorized();
});

it('returns the free plan limit for users without a subscription', function () {
    $user = User::factory()->create(['storage_used_bytes' => 1500]);
    Sanctum::actingAs($user);

    $this->getJson('/api/account/storage')
        ->assertOk()
        ->assertExactJson([
            'used_bytes' => 1500,
            'limit_bytes' => 1 * 1024 * 1024 * 1024,
            'plan' => [
                'slug' => 'free',
                'name' => 'Free',
            ],
        ]);
});

it('returns the plus plan limit for users on the gezin plan', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create(['storage_used_bytes' => 2048]);
    Subscription::factory()->for($user)->for($plus)->active()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/account/storage')
        ->assertOk()
        ->assertJsonPath('used_bytes', 2048)
        ->assertJsonPath('limit_bytes', 100 * 1024 * 1024 * 1024)
        ->assertJsonPath('plan.slug', 'plus');
});

it('returns the pro plan limit for users on the familie+ plan', function () {
    $pro = Plan::factory()->pro()->create();
    $user = User::factory()->create(['storage_used_bytes' => 0]);
    Subscription::factory()->for($user)->for($pro)->active()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/account/storage')
        ->assertOk()
        ->assertJsonPath('used_bytes', 0)
        ->assertJsonPath('limit_bytes', 1024 * 1024 * 1024 * 1024)
        ->assertJsonPath('plan.slug', 'pro');
});
