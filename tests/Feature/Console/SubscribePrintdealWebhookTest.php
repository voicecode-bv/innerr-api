<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => 'secret-token',
    ]);
    config()->set('app.url', 'https://api.innerr.test');
    URL::forceRootUrl('https://api.innerr.test');
});

it('subscribes the tokenized webhook url', function () {
    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'webhook.printdeal.test/webhooks' => Http::response(['uuid' => 'wh-1'], 201),
    ]);
    Http::preventStrayRequests();

    $this->artisan('printdeal:subscribe-webhook')
        ->expectsOutputToContain('Subscribed to orderline.status.updated.')
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/webhooks')) {
            return true;
        }

        return $request['events'] === ['orderline.status.updated']
            && $request['url'] === 'https://api.innerr.test/api/webhooks/print/printdeal/secret-token';
    });
});

it('treats an existing subscription as success', function () {
    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'webhook.printdeal.test/webhooks' => Http::response(['message' => 'exists'], 409),
    ]);

    $this->artisan('printdeal:subscribe-webhook')
        ->expectsOutputToContain('Already subscribed')
        ->assertSuccessful();
});

it('refuses to run without a configured token', function () {
    config()->set('services.printdeal.webhook_token', null);

    $this->artisan('printdeal:subscribe-webhook')->assertFailed();
});
