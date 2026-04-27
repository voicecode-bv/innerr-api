<?php

use App\Enums\NotificationPreference;
use App\Models\User;

it('returns default preferences when none are set', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/notification-preferences')
        ->assertOk();

    expect($response->json('data'))->toBe(NotificationPreference::defaults());
});

it('returns stored preferences', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_liked'] = false;

    $user = User::factory()->create(['notification_preferences' => $preferences]);

    $response = $this->actingAs($user)
        ->getJson('/api/notification-preferences')
        ->assertOk();

    expect($response->json('data.post_liked'))->toBeFalse()
        ->and($response->json('data.post_commented'))->toBeTrue();
});

it('can update a single preference without affecting the others', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/notification-preferences', ['post_liked' => true])
        ->assertOk()
        ->assertJsonPath('data.post_liked', true)
        ->assertJsonPath('data.post_commented', NotificationPreference::defaults()['post_commented']);

    $stored = $user->fresh()->notification_preferences;
    expect($stored['post_liked'])->toBeTrue();

    foreach (NotificationPreference::defaults() as $key => $default) {
        if ($key === 'post_liked') {
            continue;
        }
        expect($stored[$key])->toBe($default);
    }
});

it('persists unknown keys verbatim so the client can introduce new preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/notification-preferences', [
            'post_liked' => false,
            'experimental_digest_email' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.experimental_digest_email', true)
        ->assertJsonPath('data.post_liked', false);

    $stored = $user->fresh()->notification_preferences;
    expect($stored['experimental_digest_email'])->toBeTrue()
        ->and($stored['post_liked'])->toBeFalse();
});

it('rejects an empty body', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/notification-preferences', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['preferences']);
});

it('rejects non-boolean values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/notification-preferences', ['post_liked' => 'yes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['post_liked']);
});

it('rejects malformed preference keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/notification-preferences', ['Post-Liked' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['Post-Liked']);
});

it('requires authentication', function () {
    $this->getJson('/api/notification-preferences')->assertUnauthorized();
    $this->putJson('/api/notification-preferences')->assertUnauthorized();
});
