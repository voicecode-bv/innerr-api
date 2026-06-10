<?php

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Models\OnboardingStep;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns null for onboarding_step when no steps were tracked', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    Sanctum::actingAs($user);

    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.onboarding_step', null);
});

it('returns the furthest completed onboarding step', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    // Deliberately tracked out of timestamp order: the resume point must rank
    // by flow position, not by when the row was written.
    OnboardingStep::factory()->for($user)->create([
        'step' => OnboardingStepEnum::AddChildren,
        'completed_at' => now()->subMinutes(5),
    ]);
    OnboardingStep::factory()->for($user)->create([
        'step' => OnboardingStepEnum::Intro,
        'completed_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.onboarding_step', 'add_children');
});

it('returns null for onboarding_step once the user is onboarded', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    OnboardingStep::factory()->for($user)->create([
        'step' => OnboardingStepEnum::Notifications,
        'completed_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.onboarding_step', null);
});

it('never exposes onboarding_step on other users', function () {
    $other = User::factory()->create(['onboarded_at' => null]);
    OnboardingStep::factory()->for($other)->create([
        'step' => OnboardingStepEnum::Intro,
        'completed_at' => now(),
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/profiles/{$other->username}")
        ->assertOk()
        ->assertJsonMissingPath('data.onboarding_step');
});
