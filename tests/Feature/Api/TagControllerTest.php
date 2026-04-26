<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

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
