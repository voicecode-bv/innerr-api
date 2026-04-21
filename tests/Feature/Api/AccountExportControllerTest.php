<?php

use App\Jobs\ExportUserData;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('requires authentication', function () {
    $this->postJson('/api/account/export')->assertUnauthorized();
});

it('queues an export job for the authenticated user and returns 202', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/account/export')
        ->assertStatus(202);

    Queue::assertPushed(ExportUserData::class, fn ($job) => $job->user->is($user));
});

it('is rate limited to three requests per hour', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)->postJson('/api/account/export');
    }

    $this->actingAs($user)->postJson('/api/account/export')->assertStatus(429);
});
