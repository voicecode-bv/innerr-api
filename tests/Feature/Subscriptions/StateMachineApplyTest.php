<?php

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionStatusChanged;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionStateMachine;
use Illuminate\Support\Facades\Event;

it('transitions subscription status and dispatches event', function () {
    Event::fake([SubscriptionStatusChanged::class]);
    Plan::factory()->free()->create();
    $sub = Subscription::factory()->active()->create();

    $machine = new SubscriptionStateMachine;
    $machine->apply($sub, SubscriptionEventType::EnteredGrace);

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::InGrace);
    Event::assertDispatched(SubscriptionStatusChanged::class);
});

it('does not dispatch when status would not change', function () {
    Event::fake([SubscriptionStatusChanged::class]);
    Plan::factory()->free()->create();
    $sub = Subscription::factory()->active()->create();

    $machine = new SubscriptionStateMachine;
    $machine->apply($sub, SubscriptionEventType::Renewed);

    Event::assertNotDispatched(SubscriptionStatusChanged::class);
});

it('sets canceled_at and disables auto_renew on cancel transition', function () {
    Plan::factory()->free()->create();
    $sub = Subscription::factory()->active()->create();

    (new SubscriptionStateMachine)->apply($sub, SubscriptionEventType::Canceled);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Canceled)
        ->and($sub->auto_renew)->toBeFalse()
        ->and($sub->canceled_at)->not->toBeNull();
});

it('sets ended_at on refund transition', function () {
    Plan::factory()->free()->create();
    $sub = Subscription::factory()->active()->create();

    (new SubscriptionStateMachine)->apply($sub, SubscriptionEventType::Refunded);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Refunded)
        ->and($sub->ended_at)->not->toBeNull();
});
