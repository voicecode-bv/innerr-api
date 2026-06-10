<?php

use App\Models\AppRelease;

it('returns null fields when no release is configured', function () {
    $this->getJson('/api/app-version?platform=ios')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'latest_version' => null,
                'minimum_version' => null,
                'store_url' => null,
            ],
        ]);
});

it('returns the configured release for the requested platform', function () {
    AppRelease::factory()->create([
        'latest_version' => '2.1.0',
        'minimum_version' => '1.8.0',
        'store_url' => 'https://apps.apple.com/app/id123',
    ]);
    AppRelease::factory()->android()->create([
        'latest_version' => '2.0.5',
    ]);

    $this->getJson('/api/app-version?platform=ios')
        ->assertOk()
        ->assertJsonPath('data.latest_version', '2.1.0')
        ->assertJsonPath('data.minimum_version', '1.8.0')
        ->assertJsonPath('data.store_url', 'https://apps.apple.com/app/id123');

    $this->getJson('/api/app-version?platform=android')
        ->assertOk()
        ->assertJsonPath('data.latest_version', '2.0.5');
});

it('validates the platform parameter', function () {
    $this->getJson('/api/app-version')
        ->assertStatus(422)
        ->assertJsonValidationErrors('platform');

    $this->getJson('/api/app-version?platform=windows')
        ->assertStatus(422)
        ->assertJsonValidationErrors('platform');
});

it('is publicly reachable without authentication', function () {
    $this->getJson('/api/app-version?platform=ios')->assertOk();
});
