<?php

use App\Http\Middleware\TrackLastActivity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('records the last activity on an authenticated request', function () {
    $user = User::factory()->create(['last_activity_at' => null]);

    $this->actingAs($user)->getJson(route('api.auth.me'))->assertOk();

    expect($user->refresh()->last_activity_at)->not->toBeNull()
        ->and($user->last_activity_at->timestamp)->toBe(now()->timestamp);
});

it('does not touch updated_at when recording activity', function () {
    $user = User::factory()->create(['last_activity_at' => null]);
    $updatedAt = $user->updated_at;

    $this->travel(1)->hours();

    $this->actingAs($user)->getJson(route('api.auth.me'))->assertOk();

    expect($user->refresh()->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

it('writes at most once per throttle window', function () {
    $user = User::factory()->create(['last_activity_at' => null]);

    $this->actingAs($user)->getJson(route('api.auth.me'))->assertOk();
    $firstSeenAt = $user->refresh()->last_activity_at;

    $this->travel(TrackLastActivity::THROTTLE_SECONDS - 10)->seconds();
    $this->actingAs($user)->getJson(route('api.auth.me'))->assertOk();

    expect($user->refresh()->last_activity_at->timestamp)->toBe($firstSeenAt->timestamp);
});

it('writes again once the throttle window has passed', function () {
    $user = User::factory()->create(['last_activity_at' => null]);

    $this->actingAs($user)->getJson(route('api.auth.me'))->assertOk();
    $firstSeenAt = $user->refresh()->last_activity_at;

    $this->travel(TrackLastActivity::THROTTLE_SECONDS + 10)->seconds();
    $this->actingAs($user)->getJson(route('api.auth.me'))->assertOk();

    expect($user->refresh()->last_activity_at->timestamp)->toBeGreaterThan($firstSeenAt->timestamp);
});

it('leaves guests alone', function () {
    $this->getJson(route('api.auth.me'))->assertUnauthorized();

    expect(User::query()->whereNotNull('last_activity_at')->exists())->toBeFalse();
});

it('scopes the throttle per user', function () {
    $first = User::factory()->create(['last_activity_at' => null]);
    $second = User::factory()->create(['last_activity_at' => null]);

    $this->actingAs($first)->getJson(route('api.auth.me'))->assertOk();
    $this->actingAs($second)->getJson(route('api.auth.me'))->assertOk();

    expect($first->refresh()->last_activity_at)->not->toBeNull()
        ->and($second->refresh()->last_activity_at)->not->toBeNull()
        ->and(Cache::has(TrackLastActivity::cacheKey($first->id)))->toBeTrue();
});
