<?php

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Models\OnboardingStep;
use App\Models\User;

it('requires authentication', function () {
    $this->postJson('/api/onboarding/steps', ['step' => 'intro'])
        ->assertUnauthorized();
});

it('records a completed step for the authenticated user', function () {
    $user = User::factory()->create();

    $this->freezeTime();

    $this->actingAs($user)
        ->postJson('/api/onboarding/steps', ['step' => 'intro'])
        ->assertNoContent();

    $step = OnboardingStep::query()
        ->where('user_id', $user->id)
        ->where('step', OnboardingStepEnum::Intro)
        ->sole();

    expect($step->completed_at->timestamp)->toBe(now()->timestamp);
});

it('is idempotent and keeps the original completed_at on re-post', function () {
    $user = User::factory()->create();

    $first = now()->subHour();
    $this->travelTo($first);

    $this->actingAs($user)
        ->postJson('/api/onboarding/steps', ['step' => 'first_circle'])
        ->assertNoContent();

    $this->travelTo(now()->addHour());

    $this->actingAs($user)
        ->postJson('/api/onboarding/steps', ['step' => 'first_circle'])
        ->assertNoContent();

    $rows = OnboardingStep::query()
        ->where('user_id', $user->id)
        ->where('step', OnboardingStepEnum::FirstCircle)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->completed_at->timestamp)->toBe($first->timestamp);
});

it('records the add_children step', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/onboarding/steps', ['step' => 'add_children'])
        ->assertNoContent();

    expect(
        OnboardingStep::query()
            ->where('user_id', $user->id)
            ->where('step', OnboardingStepEnum::AddChildren)
            ->exists()
    )->toBeTrue();
});

it('rejects an unknown step', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/onboarding/steps', ['step' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('step');
});

it('requires the step parameter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/onboarding/steps', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('step');
});

it('isolates steps per user', function () {
    [$alice, $bob] = User::factory()->count(2)->create();

    $this->actingAs($alice)
        ->postJson('/api/onboarding/steps', ['step' => 'notifications'])
        ->assertNoContent();

    expect(OnboardingStep::where('user_id', $alice->id)->count())->toBe(1);
    expect(OnboardingStep::where('user_id', $bob->id)->count())->toBe(0);
});
