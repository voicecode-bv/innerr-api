<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    Plan::factory()->free()->create();
});

it('falls back to the default plan when user has no subscription', function () {
    $user = User::factory()->create();

    expect($user->currentPlan()->slug)->toBe('free')
        ->and($user->isOnPaidPlan())->toBeFalse();
});

it('resolves to the active subscription plan', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->for($plus)->active()->create();

    expect($user->currentPlan()->slug)->toBe('plus')
        ->and($user->isOnPaidPlan())->toBeTrue();
});

it('resolves to in_grace subscription plan', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->for($plus)->inGrace()->create();

    expect($user->currentPlan()->slug)->toBe('plus');
});

it('falls back to free when subscription expired', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->for($plus)->expired()->create();

    expect($user->currentPlan()->slug)->toBe('free');
});

it('uses the most recent active subscription when multiple exist', function () {
    $plus = Plan::factory()->plus()->create();
    $pro = Plan::factory()->pro()->create();
    $user = User::factory()->create();

    Subscription::factory()->for($user)->for($plus)->active()->create([
        'current_period_end' => now()->addDays(10),
    ]);
    Subscription::factory()->for($user)->for($pro)->active()->create([
        'current_period_end' => now()->addDays(30),
    ]);

    expect($user->currentPlan()->slug)->toBe('pro');
});

it('reports correct entitlement based on plan', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->for($plus)->active()->create();

    expect($user->hasEntitlement('storage_100gb'))->toBeTrue()
        ->and($user->hasEntitlement('storage_1tb'))->toBeFalse();
});

it('matches free entitlement for the default plan', function () {
    $user = User::factory()->create();

    expect($user->hasEntitlement('storage_1gb'))->toBeTrue()
        ->and($user->hasEntitlement('storage_100gb'))->toBeFalse();
});

it('flushes plan cache on subscription save', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();

    expect($user->currentPlan()->slug)->toBe('free');

    Subscription::factory()->for($user)->for($plus)->active()->create();

    $user = $user->fresh();
    expect($user->currentPlan()->slug)->toBe('plus');
});

it('flushes plan cache when subscription transitions to expired', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    $sub = Subscription::factory()->for($user)->for($plus)->active()->create();

    expect($user->fresh()->currentPlan()->slug)->toBe('plus');

    $sub->update(['status' => SubscriptionStatus::Expired]);

    expect($user->fresh()->currentPlan()->slug)->toBe('free');
});
