<?php

use App\Models\Circle;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('can show a post with relations', function () {
    $post = Post::factory()->create();
    Comment::factory()->count(2)->create(['post_id' => $post->id]);
    Like::factory()->count(3)->for($post, 'likeable')->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $post->id)
        ->assertJsonPath('data.likes_count', 3)
        ->assertJsonPath('data.comments_count', 2)
        ->assertJsonStructure([
            'data' => [
                'id', 'media_url', 'media_type', 'caption', 'location',
                'user' => ['id', 'name', 'username', 'avatar'],
                'likes_count', 'comments_count', 'comments', 'likes',
            ],
        ]);
});

it('includes is_liked on comments', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);
    $unlikedComment = Comment::factory()->create(['post_id' => $post->id]);
    Like::factory()->for($comment, 'likeable')->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson("/api/posts/{$post->id}")
        ->assertSuccessful();

    $comments = collect($response->json('data.comments'));
    $liked = $comments->firstWhere('id', $comment->id);
    $notLiked = $comments->firstWhere('id', $unlikedComment->id);

    expect($liked['is_liked'])->toBeTrue()
        ->and($liked['likes_count'])->toBe(1)
        ->and($notLiked['is_liked'])->toBeFalse()
        ->and($notLiked['likes_count'])->toBe(0);
});

it('returns comments ordered newest to oldest', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    $oldest = Comment::factory()->create(['post_id' => $post->id, 'created_at' => now()->subHours(3)]);
    $middle = Comment::factory()->create(['post_id' => $post->id, 'created_at' => now()->subHours(2)]);
    $newest = Comment::factory()->create(['post_id' => $post->id, 'created_at' => now()->subHour()]);

    $ids = $this->actingAs($user)
        ->getJson("/api/posts/{$post->id}")
        ->assertSuccessful()
        ->json('data.comments.*.id');

    expect($ids)->toBe([$newest->id, $middle->id, $oldest->id]);
});

it('returns not found for non-existent post', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/posts/99999')
        ->assertNotFound();
});

it('requires authentication to show a post', function () {
    $post = Post::factory()->create();

    $this->getJson("/api/posts/{$post->id}")
        ->assertUnauthorized();
});

it('can store a post with an image and circles', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'caption' => 'My first post',
            'location' => 'Amsterdam',
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.caption', 'My first post')
        ->assertJsonPath('data.location', 'Amsterdam')
        ->assertJsonPath('data.media_type', 'image')
        ->assertJsonPath('data.user.id', $user->id);

    $this->assertDatabaseHas('posts', [
        'user_id' => $user->id,
        'caption' => 'My first post',
        'media_type' => 'image',
    ]);

    $post = Post::first();
    expect($post->circles)->toHaveCount(1)
        ->and($post->circles->first()->id)->toBe($circle->id);

    Storage::disk('public')->assertExists($post->media_url);
    Storage::disk('public')->assertExists("users/{$user->id}/originals/posts/".basename($post->media_url));
    expect($post->media_url)->toStartWith("users/{$user->id}/posts/");
    expect($post->thumbnail_url)->toStartWith("users/{$user->id}/posts/thumbnails/");
    Storage::disk('public')->assertExists($post->thumbnail_url);
});

it('can store a post with a video', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.media_type', 'video');
});

it('can store a post with multiple circles', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circles = Circle::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => $circles->pluck('id')->all(),
        ])
        ->assertCreated();

    expect(Post::first()->circles)->toHaveCount(3);
});

it('cannot store a post without circle_ids', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create())
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('circle_ids');
});

it('can store a post as a circle member', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create();
    $circle->members()->attach($user);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();
});

it('cannot store a post in a circle the user is not a member of', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $otherCircle = Circle::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$otherCircle->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('circle_ids.0');
});

it('validates store post fields', function (array $data, string $errorField) {
    Storage::fake('public');

    $this->actingAs(User::factory()->create())
        ->postJson('/api/posts', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing media' => [['caption' => 'Hello'], 'media'],
    'invalid media type' => [['media' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')], 'media'],
    'caption too long' => [['media' => UploadedFile::fake()->image('photo.jpg'), 'caption' => str_repeat('a', 2201)], 'caption'],
    'location too long' => [['media' => UploadedFile::fake()->image('photo.jpg'), 'location' => str_repeat('a', 256)], 'location'],
    'missing circle_ids' => [['media' => UploadedFile::fake()->image('photo.jpg')], 'circle_ids'],
    'empty circle_ids' => [['media' => UploadedFile::fake()->image('photo.jpg'), 'circle_ids' => []], 'circle_ids'],
]);

it('requires authentication to store a post', function () {
    $this->postJson('/api/posts', [])
        ->assertUnauthorized();
});

it('can delete own post', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'media_url' => 'posts/photo.jpg',
    ]);
    Storage::disk('public')->put('posts/photo.jpg', 'fake-image');

    $this->actingAs($user)
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    Storage::disk('public')->assertMissing('posts/photo.jpg');
});

it('removes comments and notifications when deleting a post', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'media_url' => 'posts/photo.jpg',
    ]);
    $otherPost = Post::factory()->create();
    Storage::disk('public')->put('posts/photo.jpg', 'fake-image');

    Comment::factory()->count(2)->create(['post_id' => $post->id]);
    Comment::factory()->create(['post_id' => $otherPost->id]);

    $user->notifications()->createMany([
        ['id' => (string) Str::uuid(), 'type' => 'post-liked', 'data' => ['post_id' => $post->id]],
        ['id' => (string) Str::uuid(), 'type' => 'post-commented', 'data' => ['post_id' => $post->id, 'comment_id' => 1]],
        ['id' => (string) Str::uuid(), 'type' => 'post-liked', 'data' => ['post_id' => $otherPost->id]],
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    expect(Comment::where('post_id', $post->id)->count())->toBe(0)
        ->and(Comment::where('post_id', $otherPost->id)->count())->toBe(1)
        ->and($user->notifications()->where('data->post_id', $post->id)->count())->toBe(0)
        ->and($user->notifications()->where('data->post_id', $otherPost->id)->count())->toBe(1);
});

it('cannot delete another users post', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/posts/{$post->id}")
        ->assertForbidden();
});

it('requires authentication to delete a post', function () {
    $post = Post::factory()->create();

    $this->deleteJson("/api/posts/{$post->id}")
        ->assertUnauthorized();
});

it('converts heic uploads to jpeg', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $imagick = new Imagick;
    $imagick->newImage(100, 100, new ImagickPixel('red'));
    $imagick->setImageFormat('png');
    $heicPath = tempnam(sys_get_temp_dir(), 'heic_').'.heic';
    $imagick->writeImage('png:'.$heicPath);
    $imagick->destroy();

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => new UploadedFile($heicPath, 'photo.heic', 'image/heic', test: true),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.media_type', 'image');

    $post = Post::first();
    $storedFile = last(explode('/', $post->media_url));

    expect($storedFile)->toEndWith('.jpg');
    Storage::disk('public')->assertExists($post->media_url);
    Storage::disk('public')->assertExists("users/{$user->id}/originals/posts/{$storedFile}");
});
