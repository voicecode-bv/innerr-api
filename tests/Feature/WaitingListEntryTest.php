<?php

use App\Models\WaitingListEntry;

it('stores a waiting list entry', function () {
    $response = $this->postJson(route('api.waiting-list.store'), [
        'email' => 'test@example.com',
    ]);

    $response->assertCreated();
    expect(WaitingListEntry::where('email', 'test@example.com')->exists())->toBeTrue();
});

it('requires a valid email', function () {
    $this->postJson(route('api.waiting-list.store'), ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejects duplicate emails', function () {
    WaitingListEntry::create(['email' => 'dup@example.com']);

    $this->postJson(route('api.waiting-list.store'), ['email' => 'dup@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('returns the waiting list signup count', function () {
    WaitingListEntry::create(['email' => 'a@example.com']);
    WaitingListEntry::create(['email' => 'b@example.com']);
    WaitingListEntry::create(['email' => 'c@example.com']);

    $this->getJson(route('api.waiting-list.count'))
        ->assertOk()
        ->assertExactJson(['count' => 3]);
});

it('exposes the waiting list count without authentication', function () {
    $this->getJson(route('api.waiting-list.count'))
        ->assertOk()
        ->assertExactJson(['count' => 0]);
});
