<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lists the authenticated user\'s tags ordered by usage_count desc', function () {
    $user = User::factory()->create();
    $rare = Tag::factory()->for($user)->create(['name' => 'rare', 'usage_count' => 1]);
    $popular = Tag::factory()->for($user)->create(['name' => 'popular', 'usage_count' => 10]);
    Tag::factory()->create(['name' => 'someone-elses']);

    $response = $this->actingAs($user)
        ->getJson('/api/tags')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$popular->id, $rare->id]);
});

it('includes the type field on listed tags', function () {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'travel']);
    Tag::factory()->for($user)->person()->create(['name' => 'Sarah']);

    $response = $this->actingAs($user)
        ->getJson('/api/tags')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $byName = collect($response->json('data'))->keyBy('name');

    expect($byName['travel']['type'])->toBe('tag');
    expect($byName['Sarah']['type'])->toBe('person');
});

it('filters listed tags by type', function () {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'travel']);
    $person = Tag::factory()->for($user)->person()->create(['name' => 'Sarah']);

    $response = $this->actingAs($user)
        ->getJson('/api/tags?type=person')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($person->id);
    expect($response->json('data.0.type'))->toBe('person');
});

it('rejects an invalid type filter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/tags?type=bogus')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('requires authentication to list tags', function () {
    $this->getJson('/api/tags')->assertUnauthorized();
});

it('creates a tag for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', ['name' => 'travel'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'travel')
        ->assertJsonPath('data.type', 'tag')
        ->assertJsonPath('data.usage_count', 0);

    expect(Tag::where('user_id', $user->id)->where('name', 'travel')->where('type', 'tag')->exists())->toBeTrue();
});

it('creates a person for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', ['type' => 'person', 'name' => 'Sarah'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Sarah')
        ->assertJsonPath('data.type', 'person');

    expect(Tag::where('user_id', $user->id)->where('name', 'Sarah')->where('type', 'person')->exists())->toBeTrue();
});

it('rejects an invalid type when creating a tag', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', ['type' => 'bogus', 'name' => 'travel'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('rejects duplicate tag names for the same user and type', function () {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'travel']);

    $this->actingAs($user)
        ->postJson('/api/tags', ['name' => 'travel'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('allows the same name across different types', function () {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'Alex']);

    $this->actingAs($user)
        ->postJson('/api/tags', ['type' => 'person', 'name' => 'Alex'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'person');
});

it('allows two different users to create tags with the same name', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Tag::factory()->for($alice)->create(['name' => 'travel']);

    $this->actingAs($bob)
        ->postJson('/api/tags', ['name' => 'travel'])
        ->assertCreated();
});

it('updates a tag the user owns', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'old']);

    $this->actingAs($user)
        ->putJson("/api/tags/{$tag->id}", ['name' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.name', 'new')
        ->assertJsonPath('data.type', 'tag');
});

it('forbids updating a tag owned by another user', function () {
    $tag = Tag::factory()->create();

    $this->actingAs(User::factory()->create())
        ->putJson("/api/tags/{$tag->id}", ['name' => 'hijacked'])
        ->assertForbidden();
});

it('deletes a tag the user owns', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create();

    $this->actingAs($user)
        ->deleteJson("/api/tags/{$tag->id}")
        ->assertNoContent();

    expect(Tag::find($tag->id))->toBeNull();
});

it('forbids deleting a tag owned by another user', function () {
    $tag = Tag::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/tags/{$tag->id}")
        ->assertForbidden();
});

it('stores a birthdate when creating a person', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', [
            'type' => 'person',
            'name' => 'Sarah',
            'birthdate' => '1990-05-12',
        ])
        ->assertCreated()
        ->assertJsonPath('data.birthdate', '1990-05-12');

    expect(Tag::firstWhere('name', 'Sarah')->birthdate->toDateString())->toBe('1990-05-12');
});

it('rejects a birthdate on a regular tag when creating', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', [
            'name' => 'travel',
            'birthdate' => '1990-05-12',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('birthdate');
});

it('rejects a future birthdate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', [
            'type' => 'person',
            'name' => 'Future',
            'birthdate' => now()->addYear()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('birthdate');
});

it('updates the birthdate on a person', function () {
    $user = User::factory()->create();
    $person = Tag::factory()->for($user)->person()->create(['name' => 'Sarah']);

    $this->actingAs($user)
        ->putJson("/api/tags/{$person->id}", ['name' => 'Sarah', 'birthdate' => '1991-06-13'])
        ->assertOk()
        ->assertJsonPath('data.birthdate', '1991-06-13');
});

it('clears the birthdate when sending null', function () {
    $user = User::factory()->create();
    $person = Tag::factory()->for($user)->person()->create([
        'name' => 'Sarah',
        'birthdate' => '1990-05-12',
    ]);

    $this->actingAs($user)
        ->putJson("/api/tags/{$person->id}", ['name' => 'Sarah', 'birthdate' => null])
        ->assertOk()
        ->assertJsonPath('data.birthdate', null);

    expect($person->fresh()->birthdate)->toBeNull();
});

it('rejects updating a birthdate on a regular tag', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'travel']);

    $this->actingAs($user)
        ->putJson("/api/tags/{$tag->id}", ['name' => 'travel', 'birthdate' => '1990-05-12'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('birthdate');
});

it('uploads an avatar for a person', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $person = Tag::factory()->for($user)->person()->create(['name' => 'Sarah']);

    $this->actingAs($user)
        ->postJson("/api/tags/{$person->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $person->id);

    $person->refresh();
    expect($person->avatar)->not->toBeNull()
        ->and($person->avatar_thumbnail)->not->toBeNull();

    Storage::disk('public')->assertExists($person->avatar_thumbnail);
});

it('rejects uploading an avatar to a regular tag', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create(['name' => 'travel']);

    $this->actingAs($user)
        ->postJson("/api/tags/{$tag->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('avatar');
});

it('forbids uploading an avatar to a person owned by another user', function () {
    Storage::fake('public');

    $person = Tag::factory()->person()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/tags/{$person->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertForbidden();
});

it('replaces an existing tag avatar when uploading a new one', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $person = Tag::factory()->for($user)->person()->create();

    $this->actingAs($user)
        ->postJson("/api/tags/{$person->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ])
        ->assertOk();

    $firstThumbnail = $person->fresh()->avatar_thumbnail;

    $this->actingAs($user)
        ->postJson("/api/tags/{$person->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ])
        ->assertOk();

    Storage::disk('public')->assertMissing($firstThumbnail);
    Storage::disk('public')->assertExists($person->fresh()->avatar_thumbnail);
});

it('deletes the avatar from a person', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $person = Tag::factory()->for($user)->person()->create();

    $this->actingAs($user)
        ->postJson("/api/tags/{$person->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertOk();

    $thumbnail = $person->fresh()->avatar_thumbnail;

    $this->actingAs($user)
        ->deleteJson("/api/tags/{$person->id}/avatar")
        ->assertOk()
        ->assertJsonPath('data.avatar', null)
        ->assertJsonPath('data.avatar_thumbnail', null);

    expect($person->fresh()->avatar)->toBeNull()
        ->and($person->fresh()->avatar_thumbnail)->toBeNull();
    Storage::disk('public')->assertMissing($thumbnail);
});

it('deletes the avatar files when a person is deleted', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $person = Tag::factory()->for($user)->person()->create();

    $this->actingAs($user)
        ->postJson("/api/tags/{$person->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ])
        ->assertOk();

    $thumbnail = $person->fresh()->avatar_thumbnail;

    $this->actingAs($user)
        ->deleteJson("/api/tags/{$person->id}")
        ->assertNoContent();

    Storage::disk('public')->assertMissing($thumbnail);
});

it('detaches the tag from posts when deleted', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->for($user)->create();
    $post = Post::factory()->for($user)->create();
    $post->syncTags([$tag->id]);

    $this->actingAs($user)
        ->deleteJson("/api/tags/{$tag->id}")
        ->assertNoContent();

    expect($post->tags()->count())->toBe(0);
});
