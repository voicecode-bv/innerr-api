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
