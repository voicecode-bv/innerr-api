<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

it('admin column defaults to false', function () {
    $user = User::factory()->create();

    expect($user->fresh()->admin)->toBeFalse();
});

it('admin factory state sets admin to true', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->fresh()->admin)->toBeTrue();
});

it('non-admin cannot access the Filament panel', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getDefaultPanel()))->toBeFalse();
});

it('admin can access the Filament panel', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->canAccessPanel(Filament::getDefaultPanel()))->toBeTrue();
});

it('non-admin is denied by the Horizon gate', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('admin is allowed by the Horizon gate', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});

it('non-admin is denied by the Telescope gate', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewTelescope'))->toBeFalse();
});

it('admin is allowed by the Telescope gate', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('viewTelescope'))->toBeTrue();
});
