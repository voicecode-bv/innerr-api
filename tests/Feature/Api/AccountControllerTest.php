<?php

use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
});

it('requires authentication', function () {
    $this->deleteJson('/api/account')->assertUnauthorized();
});

it('returns 204 and anonymizes the authenticated user', function () {
    $user = User::factory()->create([
        'name' => 'Alice Example',
        'username' => 'alice',
        'email' => 'alice@example.com',
        'bio' => 'Hello',
        'locale' => 'nl',
        'fcm_token' => 'token-abc',
        'device_info' => ['platform' => 'ios'],
        'default_circle_ids' => [1, 2],
    ]);

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    $user->refresh();

    expect($user->name)->toBe('Deleted user')
        ->and($user->username)->toStartWith('deleted_')
        ->and($user->email)->toStartWith('deleted_')
        ->and($user->email)->toEndWith('@deleted.local')
        ->and($user->bio)->toBeNull()
        ->and($user->fcm_token)->toBeNull()
        ->and($user->device_info)->toBeNull()
        ->and($user->default_circle_ids)->toBeNull()
        ->and($user->notification_preferences)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->anonymized_at)->not->toBeNull();
});

it('revokes all sanctum tokens for the user', function () {
    $user = User::factory()->create();
    $user->createToken('device-1');
    $user->createToken('device-2');

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('removes sessions, password reset tokens and notifications targeting or about the user', function () {
    $user = User::factory()->create(['email' => 'alice@example.com']);
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'session-a',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode('x'),
        'last_activity' => now()->timestamp,
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => 'alice@example.com',
        'token' => 'reset-token',
        'created_at' => now(),
    ]);

    // Notification received by the deleted user.
    $received = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $received,
        'type' => 'received-notification',
        'notifiable_type' => (new User)->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => json_encode(['foo' => 'bar']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Notification received by someone else, but about the deleted user (actor).
    $about = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $about,
        'type' => 'post-liked',
        'notifiable_type' => (new User)->getMorphClass(),
        'notifiable_id' => $other->id,
        'data' => json_encode(['user_id' => $user->id, 'user_name' => 'Alice']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Unrelated notification must stay.
    $unrelated = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $unrelated,
        'type' => 'post-liked',
        'notifiable_type' => (new User)->getMorphClass(),
        'notifiable_id' => $other->id,
        'data' => json_encode(['user_id' => $other->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', 'alice@example.com')->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $received)->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $about)->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $unrelated)->count())->toBe(1);
});

it('deletes circles owned by the user and detaches pivots', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $ownedCircle = Circle::factory()->for($user)->create();
    $ownedCircle->members()->attach($other);

    $otherCircle = Circle::factory()->for($other)->create();
    $otherCircle->members()->attach($user);

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect(Circle::find($ownedCircle->id))->toBeNull()
        ->and(Circle::find($otherCircle->id))->not->toBeNull()
        ->and(DB::table('circle_user')->where('circle_id', $otherCircle->id)->where('user_id', $user->id)->count())->toBe(0);
});

it('deletes circle invitations where the user is invitee or inviter', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $circle = Circle::factory()->for($other)->create();
    $asInvitee = CircleInvitation::factory()->create(['user_id' => $user->id, 'inviter_id' => $other->id, 'circle_id' => $circle->id]);
    $asInviter = CircleInvitation::factory()->create(['user_id' => $other->id, 'inviter_id' => $user->id, 'circle_id' => $circle->id]);
    $unrelated = CircleInvitation::factory()->create(['circle_id' => $circle->id]);

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect(CircleInvitation::find($asInvitee->id))->toBeNull()
        ->and(CircleInvitation::find($asInviter->id))->toBeNull()
        ->and(CircleInvitation::find($unrelated->id))->not->toBeNull();
});

it('deletes all posts by the user', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->count(3)->for($user)->create();

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect(Post::whereIn('id', $posts->pluck('id'))->count())->toBe(0);
});

it('deletes comments by the user but keeps other users comments', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherPost = Post::factory()->for($other)->create();

    $ownComment = Comment::factory()->create([
        'user_id' => $user->id,
        'post_id' => $otherPost->id,
    ]);
    $otherComment = Comment::factory()->create([
        'user_id' => $other->id,
        'post_id' => $otherPost->id,
    ]);

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect(Comment::find($ownComment->id))->toBeNull()
        ->and(Comment::find($otherComment->id))->not->toBeNull();
});

it('deletes the user storage directory', function () {
    $user = User::factory()->create();
    Storage::put("users/{$user->id}/avatars/a.jpg", 'x');
    Storage::put("users/{$user->id}/posts/b.jpg", 'y');
    Storage::put("users/{$user->id}/originals/posts/b.jpg", 'z');

    $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

    expect(Storage::exists("users/{$user->id}/avatars/a.jpg"))->toBeFalse()
        ->and(Storage::exists("users/{$user->id}/posts/b.jpg"))->toBeFalse()
        ->and(Storage::exists("users/{$user->id}/originals/posts/b.jpg"))->toBeFalse();
});

it('is rate limited to three requests per hour', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)->deleteJson('/api/account');
    }

    $this->actingAs($user)->deleteJson('/api/account')->assertStatus(429);
});
