<?php

use App\Models\User;

it('returns public service keys for authenticated user', function () {
    config()->set('services.mapbox.public_token', 'pk.mapbox-test-token');

    $this->actingAs(User::factory()->create())
        ->getJson('/api/service-keys')
        ->assertOk()
        ->assertExactJson([
            'mapbox' => [
                'public_token' => 'pk.mapbox-test-token',
            ],
        ]);
});

it('returns null values when keys are not configured', function () {
    config()->set('services.mapbox.public_token', null);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/service-keys')
        ->assertOk()
        ->assertJsonPath('mapbox.public_token', null);
});

it('requires authentication', function () {
    $this->getJson('/api/service-keys')
        ->assertUnauthorized();
});
