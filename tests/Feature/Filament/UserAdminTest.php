<?php

use App\Enums\OnboardingStep;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\OnboardingStep as OnboardingStepModel;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows posts count and storage usage for each user', function () {
    $user = User::factory()->create(['storage_used_bytes' => 12_345_678]);
    Post::factory()->count(3)->for($user)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('posts_count', 3, $user)
        ->assertTableColumnStateSet('storage_used_bytes', 12_345_678, $user);
});

it('shows how many onboarding steps each user has completed', function () {
    $user = User::factory()->create();

    OnboardingStepModel::factory()->for($user)->create(['step' => OnboardingStep::Intro]);
    OnboardingStepModel::factory()->for($user)->create(['step' => OnboardingStep::FirstCircle]);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('onboarding_steps_count', 2, $user)
        ->assertTableColumnFormattedStateSet('onboarding_steps_count', '2/6', $user);
});

it('shows the furthest onboarding step each user has reached', function () {
    $user = User::factory()->create();

    OnboardingStepModel::factory()->for($user)->create(['step' => OnboardingStep::Intro]);
    OnboardingStepModel::factory()->for($user)->create(['step' => OnboardingStep::AddChildren]);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('current_onboarding_step', OnboardingStep::AddChildren, $user);
});

it('shows "Not started" when a user has no onboarding steps', function () {
    $user = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('current_onboarding_step', null, $user);
});

it('filters users by whether they completed onboarding', function () {
    $onboarded = User::factory()->create(['onboarded_at' => now()]);
    $notOnboarded = User::factory()->create(['onboarded_at' => null]);

    Livewire::test(ListUsers::class)
        ->filterTable('onboarded_at', true)
        ->assertCanSeeTableRecords([$onboarded])
        ->assertCanNotSeeTableRecords([$notOnboarded]);
});

it('renders the onboarding progress overview on the edit page', function () {
    $user = User::factory()->create();
    OnboardingStepModel::factory()->for($user)->create([
        'step' => OnboardingStep::Intro,
        'completed_at' => now(),
    ]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertSuccessful()
        ->assertSee('Onboarding')
        ->assertSee('1/6 completed');
});
