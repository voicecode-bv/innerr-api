<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Plan::factory()->free()->create();

    Route::middleware(['auth:sanctum', 'entitlement:storage_100gb'])
        ->get('/__test/gated', fn () => response()->json(['ok' => true]))
        ->name('test.gated');
});

it('returns 402 when user lacks the required entitlement', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/__test/gated')
        ->assertStatus(402)
        ->assertJsonPath('required_entitlement', 'storage_100gb');
});

it('passes through when user has the required entitlement', function () {
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    Subscription::factory()->for($user)->for($plus)->active()->create();

    Sanctum::actingAs($user);

    $this->getJson('/__test/gated')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/__test/gated')->assertUnauthorized();
});
