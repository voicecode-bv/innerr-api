<?php

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Services\Subscriptions\SubscriptionStateMachine;

it('maps each event type to a target status', function (SubscriptionEventType $event, SubscriptionStatus $expected) {
    $machine = new SubscriptionStateMachine;

    expect($machine->targetFor($event))->toBe($expected);
})->with([
    'started → active' => [SubscriptionEventType::Started, SubscriptionStatus::Active],
    'renewed → active' => [SubscriptionEventType::Renewed, SubscriptionStatus::Active],
    'recovered → active' => [SubscriptionEventType::Recovered, SubscriptionStatus::Active],
    'resumed → active' => [SubscriptionEventType::Resumed, SubscriptionStatus::Active],
    'price_change → active' => [SubscriptionEventType::PriceChange, SubscriptionStatus::Active],
    'upgraded → active' => [SubscriptionEventType::Upgraded, SubscriptionStatus::Active],
    'downgraded → active' => [SubscriptionEventType::Downgraded, SubscriptionStatus::Active],
    'entered_grace → in_grace' => [SubscriptionEventType::EnteredGrace, SubscriptionStatus::InGrace],
    'paused → paused' => [SubscriptionEventType::Paused, SubscriptionStatus::Paused],
    'canceled → canceled' => [SubscriptionEventType::Canceled, SubscriptionStatus::Canceled],
    'expired → expired' => [SubscriptionEventType::Expired, SubscriptionStatus::Expired],
    'refunded → refunded' => [SubscriptionEventType::Refunded, SubscriptionStatus::Refunded],
]);
