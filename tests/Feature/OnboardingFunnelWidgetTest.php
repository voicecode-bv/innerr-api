<?php

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Filament\Widgets\OnboardingFunnel;
use App\Models\OnboardingStep;
use App\Models\User;
use Livewire\Livewire;

it('has a label for every onboarding step', function (OnboardingStepEnum $step) {
    expect($step->label())->toBeString()->not->toBeEmpty();
})->with(OnboardingStepEnum::cases());

it('renders the funnel with a stat for every onboarding step', function () {
    $user = User::factory()->create();

    foreach (OnboardingStepEnum::cases() as $step) {
        OnboardingStep::factory()->for($user)->create(['step' => $step]);
    }

    Livewire::test(OnboardingFunnel::class)
        ->assertOk()
        ->assertSee(OnboardingStepEnum::AddChildren->label())
        ->assertSee(OnboardingStepEnum::FirstMoment->label());
});
