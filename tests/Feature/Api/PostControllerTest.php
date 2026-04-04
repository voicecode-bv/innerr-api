<?php

use App\Models\Circle;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    Storage::disk('public')->assertExists('posts/'.last(explode('/', $post->media_url)));
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

it('cannot store a post with circles not owned by user', function () {
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
        'media_url' => Storage::disk('public')->url('posts/photo.jpg'),
    ]);
    Storage::disk('public')->put('posts/photo.jpg', 'fake-image');

    $this->actingAs($user)
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    Storage::disk('public')->assertMissing('posts/photo.jpg');
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
