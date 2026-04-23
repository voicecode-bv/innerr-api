<?php

use App\Models\Circle;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('can show a user profile', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $circle = Circle::factory()->for($viewer)->create();
    Post::factory()->count(3)->create(['user_id' => $user->id])->each(
        fn (Post $post) => $post->circles()->attach($circle),
    );

    $this->actingAs($viewer)
        ->getJson("/api/profiles/{$user->username}")
        ->assertSuccessful()
        ->assertJsonPath('data.username', $user->username)
        ->assertJsonPath('data.posts_count', 3)
        ->assertJsonStructure([
            'data' => ['id', 'name', 'username', 'avatar', 'bio', 'created_at', 'posts_count'],
        ])
        ->assertJsonMissing(['email']);
});

it('only counts posts shared with circles the viewer belongs to', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $sharedCircle = Circle::factory()->for($viewer)->create();
    $otherCircle = Circle::factory()->create();

    Post::factory()->count(2)->create(['user_id' => $user->id])->each(
        fn (Post $post) => $post->circles()->attach($sharedCircle),
    );
    Post::factory()->count(3)->create(['user_id' => $user->id])->each(
        fn (Post $post) => $post->circles()->attach($otherCircle),
    );

    $this->actingAs($viewer)
        ->getJson("/api/profiles/{$user->username}")
        ->assertOk()
        ->assertJsonPath('data.posts_count', 2);
});

it('counts all own posts when viewing own profile', function () {
    $user = User::factory()->create();
    Post::factory()->count(4)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/profiles/{$user->username}")
        ->assertOk()
        ->assertJsonPath('data.posts_count', 4);
});

it('returns not found for non-existent username', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/profiles/nonexistent')
        ->assertNotFound();
});

it('requires authentication to view a profile', function () {
    $user = User::factory()->create();

    $this->getJson("/api/profiles/{$user->username}")
        ->assertUnauthorized();
});

it('can list profile posts', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $circle = Circle::factory()->for($viewer)->create();
    Post::factory()->count(3)->create(['user_id' => $user->id])->each(
        fn (Post $post) => $post->circles()->attach($circle),
    );

    $this->actingAs($viewer)
        ->getJson("/api/profiles/{$user->username}/posts")
        ->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'media_url', 'media_type', 'caption', 'likes_count', 'comments_count'],
            ],
            'links',
            'meta',
        ]);
});

it('only lists profile posts shared with circles the viewer belongs to', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $sharedCircle = Circle::factory()->for($viewer)->create();
    $otherCircle = Circle::factory()->create();
    $memberCircle = Circle::factory()->create();
    $memberCircle->members()->attach($viewer);

    $shared = Post::factory()->create(['user_id' => $user->id]);
    $shared->circles()->attach($sharedCircle);

    $sharedAsMember = Post::factory()->create(['user_id' => $user->id]);
    $sharedAsMember->circles()->attach($memberCircle);

    $hidden = Post::factory()->create(['user_id' => $user->id]);
    $hidden->circles()->attach($otherCircle);

    $response = $this->actingAs($viewer)
        ->getJson("/api/profiles/{$user->username}/posts")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($shared->id, $sharedAsMember->id)->not->toContain($hidden->id);
});

it('uses the thumbnail url for profile posts when available', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $circle = Circle::factory()->for($viewer)->create();
    $withThumb = Post::factory()->create([
        'user_id' => $user->id,
        'media_url' => 'posts/full.jpg',
        'thumbnail_url' => 'posts/thumbnails/thumb.jpg',
    ]);
    $withThumb->circles()->attach($circle);
    $withoutThumb = Post::factory()->create([
        'user_id' => $user->id,
        'media_url' => 'posts/no-thumb.jpg',
        'thumbnail_url' => null,
    ]);
    $withoutThumb->circles()->attach($circle);

    $response = $this->actingAs($viewer)
        ->getJson("/api/profiles/{$user->username}/posts")
        ->assertSuccessful();

    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId[$withThumb->id]['media_url'])->toContain('posts/thumbnails/thumb.jpg')
        ->and($byId[$withoutThumb->id]['media_url'])->toContain('posts/no-thumb.jpg');
});

it('paginates profile posts', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $circle = Circle::factory()->for($viewer)->create();
    Post::factory()->count(35)->create(['user_id' => $user->id])->each(
        fn (Post $post) => $post->circles()->attach($circle),
    );

    $this->actingAs($viewer)
        ->getJson("/api/profiles/{$user->username}/posts")
        ->assertSuccessful()
        ->assertJsonCount(30, 'data');
});

it('requires authentication to view profile posts', function () {
    $user = User::factory()->create();

    $this->getJson("/api/profiles/{$user->username}/posts")
        ->assertUnauthorized();
});

it('can update own profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', [
            'name' => 'Updated Name',
            'bio' => 'My new bio',
            'locale' => 'nl',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.bio', 'My new bio')
        ->assertJsonPath('data.locale', 'nl');

    expect($user->fresh())
        ->name->toBe('Updated Name')
        ->bio->toBe('My new bio')
        ->locale->toBe('nl');
});

it('can update username to a unique value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => 'newusername'])
        ->assertSuccessful()
        ->assertJsonPath('data.username', 'newusername');
});

it('cannot update username to an existing one', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => 'taken'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('allows keeping own username unchanged', function () {
    $user = User::factory()->create(['username' => 'myname']);

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => 'myname'])
        ->assertSuccessful();
});

it('normalizes username to lowercase on profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => 'NewName'])
        ->assertSuccessful()
        ->assertJsonPath('data.username', 'newname');
});

it('replaces spaces with dashes in username on profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => 'new name'])
        ->assertSuccessful()
        ->assertJsonPath('data.username', 'new-name');
});

it('strips invalid characters from username on profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => 'new_name!'])
        ->assertSuccessful()
        ->assertJsonPath('data.username', 'newname');
});

it('rejects username that is empty after normalization on profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/profile', ['username' => '!!!'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('requires authentication to update profile', function () {
    $this->putJson('/api/profile', ['name' => 'Test'])
        ->assertUnauthorized();
});

it('can upload an avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertOk()
        ->assertJsonStructure(['user' => ['avatar']]);

    expect($user->fresh()->avatar)->not->toBeNull()
        ->and($user->fresh()->avatar_thumbnail)->not->toBeNull();

    Storage::disk('public')->assertExists(
        str_replace(Storage::disk('public')->url(''), '', $user->fresh()->avatar),
    );
    Storage::disk('public')->assertExists($user->fresh()->avatar_thumbnail);

    [$width, $height] = getimagesize(Storage::disk('public')->path($user->fresh()->avatar_thumbnail));
    expect($width)->toBe(100)->and($height)->toBe(100);
});

it('deletes old avatar when uploading a new one', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    // Upload first avatar
    $this->actingAs($user)
        ->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ])
        ->assertOk();

    $firstAvatarPath = str_replace(Storage::disk('public')->url(''), '', $user->fresh()->avatar);

    // Upload second avatar
    $this->actingAs($user)
        ->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ])
        ->assertOk();

    Storage::disk('public')->assertMissing($firstAvatarPath);
    Storage::disk('public')->assertExists(
        str_replace(Storage::disk('public')->url(''), '', $user->fresh()->avatar),
    );
});

it('can delete avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    // Upload avatar first
    $this->actingAs($user)
        ->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertOk();

    $avatarPath = str_replace(Storage::disk('public')->url(''), '', $user->fresh()->avatar);
    $thumbnailPath = $user->fresh()->avatar_thumbnail;

    // Delete avatar
    $this->actingAs($user)
        ->deleteJson('/api/profile/avatar')
        ->assertOk()
        ->assertJsonPath('user.avatar', null);

    expect($user->fresh()->avatar)->toBeNull()
        ->and($user->fresh()->avatar_thumbnail)->toBeNull();
    Storage::disk('public')->assertMissing($avatarPath);
    Storage::disk('public')->assertMissing($thumbnailPath);
});

it('validates avatar must be an image', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('document.pdf', 100),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('avatar');
});

it('validates avatar is required for upload', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/profile/avatar', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('avatar');
});

it('requires authentication to upload avatar', function () {
    $this->postJson('/api/profile/avatar', [])
        ->assertUnauthorized();
});

it('requires authentication to delete avatar', function () {
    $this->deleteJson('/api/profile/avatar')
        ->assertUnauthorized();
});
