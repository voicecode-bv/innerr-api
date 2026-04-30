<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Illuminate\Database\QueryException;

it('returns 501 stubs for the not-yet-implemented IAP webhooks', function (string $route) {
    $this->postJson($route)->assertStatus(501);
})->with([
    '/api/webhooks/subscriptions/apple',
    '/api/webhooks/subscriptions/google',
]);

it('enforces unique external_event_id at the database level', function () {
    Plan::factory()->free()->create();
    $sub = Subscription::factory()->active()->create();

    SubscriptionEvent::factory()->create([
        'subscription_id' => $sub->id,
        'channel' => $sub->channel,
        'external_event_id' => 'evt_dedupe_test',
    ]);

    expect(fn () => SubscriptionEvent::factory()->create([
        'subscription_id' => $sub->id,
        'channel' => $sub->channel,
        'external_event_id' => 'evt_dedupe_test',
    ]))->toThrow(QueryException::class);
});
