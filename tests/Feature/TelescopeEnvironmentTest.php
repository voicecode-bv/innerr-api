<?php

use Laravel\Telescope\TelescopeServiceProvider;

test('telescope is not registered outside the local environment', function () {
    // The test suite runs in the "testing" environment (non-local), which
    // mirrors staging/production for this guarantee: Telescope must never
    // boot there, because it records requests, queries and credentials.
    expect(app()->environment('local'))->toBeFalse();

    expect(array_key_exists(TelescopeServiceProvider::class, app()->getLoadedProviders()))
        ->toBeFalse('Telescope package provider must not load outside local.');

    expect(array_key_exists(App\Providers\TelescopeServiceProvider::class, app()->getLoadedProviders()))
        ->toBeFalse('App Telescope provider must not load outside local.');
});
