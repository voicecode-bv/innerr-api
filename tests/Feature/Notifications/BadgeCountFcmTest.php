<?php

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostLiked;
use Illuminate\Support\Str;

/**
 * Seed `$count` unread database notifications for the given user.
 */
function seedUnreadNotifications(User $user, int $count): void
{
    foreach (range(1, $count) as $i) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Seeded',
            'data' => ['i' => $i],
            'read_at' => null,
        ]);
    }
}

it('sets the icon badge to the unread count including the notification being sent', function () {
    $owner = User::factory()->create();
    seedUnreadNotifications($owner, 3);

    $liker = User::factory()->create(['name' => 'Alice']);
    $post = new Post;
    $post->id = 7;

    $notification = new PostLiked($liker, $post);
    // Mirror Laravel assigning a shared id across channels before delivery.
    $notification->id = (string) Str::uuid();

    $payload = $notification->toFcm($owner)->toArray();

    // 3 existing unread + this one = 4.
    expect($payload['apns']['payload']['aps']['badge'] ?? null)->toBe(4)
        ->and($payload['android']['notification']['notification_count'] ?? null)->toBe(4);
});

it('does not double-count when the database notification is already persisted', function () {
    $owner = User::factory()->create();
    seedUnreadNotifications($owner, 3);

    $liker = User::factory()->create(['name' => 'Alice']);
    $post = new Post;
    $post->id = 7;

    $notification = new PostLiked($liker, $post);
    $notification->id = (string) Str::uuid();

    // Simulate the `database` channel job having already persisted this
    // notification (channels are queued independently, order not guaranteed).
    $owner->notifications()->create([
        'id' => $notification->id,
        'type' => 'post-liked',
        'data' => [],
        'read_at' => null,
    ]);

    $payload = $notification->toFcm($owner)->toArray();

    // Still 4: the persisted row is excluded by id, then counted once via +1.
    expect($payload['apns']['payload']['aps']['badge'] ?? null)->toBe(4)
        ->and($payload['android']['notification']['notification_count'] ?? null)->toBe(4);
});
