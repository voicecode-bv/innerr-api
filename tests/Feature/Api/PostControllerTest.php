<?php

use App\Models\Circle;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Person;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

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
                'likes_count', 'comments_count', 'comments',
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
    expect($post->thumbnail_small_url)->toStartWith("users/{$user->id}/posts/thumbnails/");
    Storage::disk('public')->assertExists($post->thumbnail_small_url);

    [$width, $height] = getimagesize(Storage::disk('public')->path($post->thumbnail_small_url));
    expect($width)->toBe(150)->and($height)->toBe(150);
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

it('extracts EXIF metadata when uploading a JPEG', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $fixture = new UploadedFile(
        __DIR__.'/../../fixtures/photo-with-exif.jpg',
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $response = $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => $fixture,
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    expect($response->json('data.taken_at'))->not->toBeNull()
        ->and($response->json('data.latitude'))->toEqualWithDelta(48.858331, 0.0001)
        ->and($response->json('data.longitude'))->toEqualWithDelta(2.294497, 0.0001);

    $post = Post::first();
    expect($post->taken_at->format('Y-m-d H:i:s'))->toBe('2024-06-15 14:30:00')
        ->and($post->latitude)->toEqualWithDelta(48.858331, 0.0001)
        ->and($post->longitude)->toEqualWithDelta(2.294497, 0.0001);
});

it('stores null EXIF fields when the image has no EXIF', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $fixture = new UploadedFile(
        __DIR__.'/../../fixtures/photo-without-exif.jpg',
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => $fixture,
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.taken_at', null)
        ->assertJsonPath('data.latitude', null)
        ->assertJsonPath('data.longitude', null);

    $post = Post::first();
    expect($post->taken_at)->toBeNull()
        ->and($post->coordinates)->toBeNull();
});

it('stores client-supplied coordinates when the cropped file has no EXIF', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $fixture = new UploadedFile(
        __DIR__.'/../../fixtures/photo-without-exif.jpg',
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $this->actingAs($user)
        ->post('/api/posts', [
            'media' => $fixture,
            'circle_ids' => [$circle->id],
            'taken_at' => '2024-06-15T14:30:00Z',
            'latitude' => 52.370216,
            'longitude' => 4.895168,
        ])
        ->assertCreated()
        ->assertJsonPath('data.latitude', 52.370216)
        ->assertJsonPath('data.longitude', 4.895168);

    $post = Post::first();
    expect($post->taken_at->format('Y-m-d H:i'))->toBe('2024-06-15 14:30')
        ->and($post->latitude)->toEqualWithDelta(52.370216, 0.0001)
        ->and($post->longitude)->toEqualWithDelta(4.895168, 0.0001);
});

it('lets client-supplied EXIF override what would be extracted from the file', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    // Use the EXIF fixture but client overrides — client values must win.
    $fixture = new UploadedFile(
        __DIR__.'/../../fixtures/photo-with-exif.jpg',
        'photo.jpg',
        'image/jpeg',
        null,
        true,
    );

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => $fixture,
            'circle_ids' => [$circle->id],
            'taken_at' => '2023-01-01T12:00:00Z',
            'latitude' => 52.370216,
            'longitude' => 4.895168,
        ])
        ->assertCreated()
        ->assertJsonPath('data.latitude', 52.370216)
        ->assertJsonPath('data.longitude', 4.895168);

    $post = Post::first();
    expect($post->taken_at->format('Y-m-d'))->toBe('2023-01-01')
        ->and($post->latitude)->toEqualWithDelta(52.370216, 0.0001);
});

it('rejects out-of-range EXIF values', function (array $data, string $errorField) {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $payload = array_merge([
        'media' => UploadedFile::fake()->image('photo.jpg'),
        'circle_ids' => [$circle->id],
    ], $data);

    $this->actingAs($user)
        ->postJson('/api/posts', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'latitude > 90' => [['latitude' => 91, 'longitude' => 0], 'latitude'],
    'latitude < -90' => [['latitude' => -91, 'longitude' => 0], 'latitude'],
    'longitude > 180' => [['latitude' => 0, 'longitude' => 181], 'longitude'],
    'taken_at in future' => [['taken_at' => '2099-01-01T00:00:00Z'], 'taken_at'],
    'taken_at too old' => [['taken_at' => '1980-01-01T00:00:00Z'], 'taken_at'],
    'lat without lng' => [['latitude' => 12.34], 'longitude'],
    'lng without lat' => [['longitude' => 56.78], 'longitude'],
]);

it('finds posts within a radius using PostGIS ST_DWithin', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    // Eiffel Tower
    $near = Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => new Point(48.858331, 2.294497, Srid::WGS84->value),
    ]);

    // Amsterdam, ~430 km away
    Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => new Point(52.370216, 4.895168, Srid::WGS84->value),
    ]);

    $center = new Point(48.858000, 2.294000, Srid::WGS84->value);

    $found = Post::query()
        ->whereDistanceSphere('coordinates', $center, '<=', 5000) // 5km radius
        ->pluck('id')
        ->all();

    expect($found)->toBe([$near->id]);
});

it('throttles post creation', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image("photo-{$i}.jpg"),
            'circle_ids' => [$circle->id],
        ])->assertCreated();
    }

    $this->postJson('/api/posts', [
        'media' => UploadedFile::fake()->image('photo-extra.jpg'),
        'circle_ids' => [$circle->id],
    ])->assertStatus(429);
});

it('can update caption on own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'caption' => 'Original caption',
    ]);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", ['caption' => 'Updated caption'])
        ->assertSuccessful()
        ->assertJsonPath('data.caption', 'Updated caption');

    expect($post->fresh()->caption)->toBe('Updated caption');
});

it('can clear caption on own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'caption' => 'Original caption',
    ]);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", ['caption' => null])
        ->assertSuccessful()
        ->assertJsonPath('data.caption', null);

    expect($post->fresh()->caption)->toBeNull();
});

it('can sync circles on own post', function () {
    $user = User::factory()->create();
    $originalCircle = Circle::factory()->create(['user_id' => $user->id]);
    $newCircle = Circle::factory()->create(['user_id' => $user->id]);

    $post = Post::factory()->create(['user_id' => $user->id]);
    $post->circles()->attach($originalCircle);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", ['circle_ids' => [$newCircle->id]])
        ->assertSuccessful()
        ->assertJsonPath('data.circles.0.id', $newCircle->id);

    expect($post->fresh()->circles->pluck('id')->all())->toBe([$newCircle->id]);
});

it('can update caption and circles together', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'caption' => 'Original',
    ]);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", [
            'caption' => 'Updated',
            'circle_ids' => [$circle->id],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.caption', 'Updated')
        ->assertJsonPath('data.circles.0.id', $circle->id);
});

it('can update a post as a circle member', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create();
    $circle->members()->attach($user);
    $post = Post::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", ['circle_ids' => [$circle->id]])
        ->assertSuccessful();
});

it('cannot update a post with circles the user does not belong to', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    $otherCircle = Circle::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", ['circle_ids' => [$otherCircle->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('circle_ids.0');
});

it('validates update post fields', function (array $data, string $errorField) {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'caption too long' => [['caption' => str_repeat('a', 2201)], 'caption'],
    'empty circle_ids' => [['circle_ids' => []], 'circle_ids'],
    'circle_ids not array' => [['circle_ids' => 1], 'circle_ids'],
]);

it('cannot update another users post', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->putJson("/api/posts/{$post->id}", ['caption' => 'Hijacked'])
        ->assertForbidden();
});

it('requires authentication to update a post', function () {
    $post = Post::factory()->create();

    $this->putJson("/api/posts/{$post->id}", ['caption' => 'x'])
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

    $countNotificationsForPost = fn (int $postId): int => $user->notifications()
        ->whereRaw("data::jsonb->>'post_id' = ?", [(string) $postId])
        ->count();

    expect(Comment::where('post_id', $post->id)->count())->toBe(0)
        ->and(Comment::where('post_id', $otherPost->id)->count())->toBe(1)
        ->and($countNotificationsForPost($post->id))->toBe(0)
        ->and($countNotificationsForPost($otherPost->id))->toBe(1);
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

it('attaches tags to a new post and increments usage_count', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $travel = Tag::factory()->for($user)->create(['name' => 'travel']);
    $food = Tag::factory()->for($user)->create(['name' => 'food']);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
            'tag_ids' => [$travel->id, $food->id],
        ])
        ->assertCreated();

    $post = Post::first();
    expect($post->tags()->pluck('tags.id')->all())->toEqualCanonicalizing([$travel->id, $food->id]);
    expect($travel->fresh()->usage_count)->toBe(1);
    expect($food->fresh()->usage_count)->toBe(1);
});

it('rejects tag_ids that belong to another user', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $otherUsersTag = Tag::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
            'tag_ids' => [$otherUsersTag->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tag_ids.0');
});

it('syncs tags on update and adjusts usage_count both ways', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();
    $kept = Tag::factory()->for($user)->create();
    $removed = Tag::factory()->for($user)->create();
    $added = Tag::factory()->for($user)->create();
    $post->syncTags([$kept->id, $removed->id]);

    expect($kept->fresh()->usage_count)->toBe(1);
    expect($removed->fresh()->usage_count)->toBe(1);

    $this->actingAs($user)
        ->putJson("/api/posts/{$post->id}", [
            'tag_ids' => [$kept->id, $added->id],
        ])
        ->assertOk();

    expect($post->tags()->pluck('tags.id')->all())->toEqualCanonicalizing([$kept->id, $added->id]);
    expect($kept->fresh()->usage_count)->toBe(1);
    expect($removed->fresh()->usage_count)->toBe(0);
    expect($added->fresh()->usage_count)->toBe(1);
});

it('attaches persons to a new post and increments usage_count', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->for($user)->create();
    $oma = Person::factory()->for($user, 'creator')->create();
    $opa = Person::factory()->for($user, 'creator')->create();
    $oma->circles()->attach($circle);
    $opa->circles()->attach($circle);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
            'person_ids' => [$oma->id, $opa->id],
        ])
        ->assertCreated();

    $post = Post::first();
    expect($post->persons()->pluck('people.id')->all())->toEqualCanonicalizing([$oma->id, $opa->id]);
    expect($oma->fresh()->usage_count)->toBe(1);
    expect($opa->fresh()->usage_count)->toBe(1);
});

it('rejects person_ids from a circle the post is not in', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circleA = Circle::factory()->for($user)->create();
    $circleB = Circle::factory()->for($user)->create();
    $person = Person::factory()->for($user, 'creator')->create();
    $person->circles()->attach($circleB);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circleA->id],
            'person_ids' => [$person->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('person_ids.0');
});

it('decrements usage_count for all persons when a post is deleted', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->for($user)->create();
    $person = Person::factory()->for($user, 'creator')->create();
    $person->circles()->attach($circle);
    $post = Post::factory()->for($user)->create();
    $post->syncPersons([$person->id]);

    expect($person->fresh()->usage_count)->toBe(1);

    $this->actingAs($user)
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    expect($person->fresh()->usage_count)->toBe(0);
});

it('decrements usage_count for all tags when a post is deleted', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();
    $tag = Tag::factory()->for($user)->create();
    $post->syncTags([$tag->id]);

    expect($tag->fresh()->usage_count)->toBe(1);

    $this->actingAs($user)
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    expect($tag->fresh()->usage_count)->toBe(0);
});

it('lets a tagged user untag themselves from a post', function () {
    $owner = User::factory()->create();
    $taggedUser = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($taggedUser);

    $person = Person::factory()->for($owner, 'creator')->linkedToUser($taggedUser)->create();
    $person->circles()->attach($circle);
    $otherPerson = Person::factory()->for($owner, 'creator')->create();
    $otherPerson->circles()->attach($circle);

    $post = Post::factory()->for($owner)->create();
    $post->circles()->attach($circle);
    $post->syncPersons([$person->id, $otherPerson->id]);

    $this->actingAs($taggedUser)
        ->deleteJson("/api/posts/{$post->id}/tagged-self")
        ->assertOk()
        ->assertJsonPath('data.id', $post->id)
        ->assertJsonCount(1, 'data.persons');

    expect($post->persons()->pluck('people.id')->all())->toEqual([$otherPerson->id]);
    expect($person->fresh()->usage_count)->toBe(0);
    expect($otherPerson->fresh()->usage_count)->toBe(1);
});

it('forbids untagging when the user is not tagged on the post', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $post = Post::factory()->for($owner)->create();

    $this->actingAs($stranger)
        ->deleteJson("/api/posts/{$post->id}/tagged-self")
        ->assertForbidden();
});

it('includes tags only for the post owner on show', function () {
    $owner = User::factory()->create();
    $post = Post::factory()->for($owner)->create();
    $tag = Tag::factory()->for($owner)->create(['name' => 'travel']);
    $post->syncTags([$tag->id]);

    $response = $this->actingAs($owner)
        ->getJson("/api/posts/{$post->id}")
        ->assertOk();

    $tags = collect($response->json('data.tags'))->keyBy('id');

    expect($tags[$tag->id])->toMatchArray(['id' => $tag->id, 'name' => 'travel']);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.tags');
});
