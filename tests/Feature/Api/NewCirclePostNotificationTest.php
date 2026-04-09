<?php

use App\Models\Circle;
use App\Models\User;
use App\Notifications\NewCirclePost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('notifies circle members when a new post is created', function () {
    Notification::fake();
    Storage::fake('public');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $this->actingAs($owner)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    Notification::assertSentTo($member, NewCirclePost::class);
});

it('notifies the circle owner when a member creates a post', function () {
    Notification::fake();
    Storage::fake('public');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $this->actingAs($member)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    Notification::assertSentTo($owner, NewCirclePost::class);
});

it('does not notify the poster', function () {
    Notification::fake();
    Storage::fake('public');

    $user = User::factory()->create();
    $circle = Circle::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    Notification::assertNotSentTo($user, NewCirclePost::class);
});

it('does not send duplicate notifications for users in multiple circles', function () {
    Notification::fake();
    Storage::fake('public');

    $poster = User::factory()->create();
    $member = User::factory()->create();
    $circle1 = Circle::factory()->for($poster)->create();
    $circle2 = Circle::factory()->for($poster)->create();
    $circle1->members()->attach($member);
    $circle2->members()->attach($member);

    $this->actingAs($poster)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle1->id, $circle2->id],
        ])
        ->assertCreated();

    Notification::assertSentToTimes($member, NewCirclePost::class, 1);
});

it('notifies members across multiple circles without duplicates', function () {
    Notification::fake();
    Storage::fake('public');

    $poster = User::factory()->create();
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $circle1 = Circle::factory()->for($poster)->create();
    $circle2 = Circle::factory()->for($poster)->create();
    $circle1->members()->attach([$memberA->id, $memberB->id]);
    $circle2->members()->attach($memberA);

    $this->actingAs($poster)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle1->id, $circle2->id],
        ])
        ->assertCreated();

    Notification::assertSentToTimes($memberA, NewCirclePost::class, 1);
    Notification::assertSentToTimes($memberB, NewCirclePost::class, 1);
});
