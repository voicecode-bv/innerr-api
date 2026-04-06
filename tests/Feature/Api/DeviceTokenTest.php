<?php

use App\Models\User;

it('stores the fcm token for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/device-token', ['token' => 'fcm-test-token-123'])
        ->assertNoContent();

    expect($user->fresh()->fcm_token)->toBe('fcm-test-token-123');
});

it('overwrites an existing fcm token', function () {
    $user = User::factory()->create(['fcm_token' => 'old-token']);

    $this->actingAs($user)
        ->postJson('/api/device-token', ['token' => 'new-token'])
        ->assertNoContent();

    expect($user->fresh()->fcm_token)->toBe('new-token');
});

it('validates the token is required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/device-token', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

it('requires authentication', function () {
    $this->postJson('/api/device-token', ['token' => 'some-token'])
        ->assertUnauthorized();
});
