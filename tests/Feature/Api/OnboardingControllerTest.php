<?php

use App\Models\User;

it('requires authentication', function () {
    $this->postJson('/api/onboarding/complete')->assertUnauthorized();
});

it('sets the onboarded_at timestamp for the authenticated user', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->freezeTime();

    $this->actingAs($user)
        ->postJson('/api/onboarding/complete')
        ->assertNoContent();

    expect($user->fresh()->onboarded_at->timestamp)->toBe(now()->timestamp);
});

it('updates the timestamp when called again', function () {
    $user = User::factory()->create(['onboarded_at' => now()->subDays(5)]);

    $this->freezeTime();

    $this->actingAs($user)
        ->postJson('/api/onboarding/complete')
        ->assertNoContent();

    expect($user->fresh()->onboarded_at->timestamp)->toBe(now()->timestamp);
});
