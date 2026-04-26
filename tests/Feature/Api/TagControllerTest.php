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

it('requires authentication to list tags', function () {
    $this->getJson('/api/tags')->assertUnauthorized();
});

it('creates a tag for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/tags', ['name' => 'travel'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'travel')
        ->assertJsonPath('data.usage_count', 0);

    expect(Tag::where('user_id', $user->id)->where('name', 'travel')->exists())->toBeTrue();
});

it('rejects duplicate tag names for the same user', function () {
    $user = User::factory()->create();
    Tag::factory()->for($user)->create(['name' => 'travel']);

    $this->actingAs($user)
        ->postJson('/api/tags', ['name' => 'travel'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
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
        ->assertJsonPath('data.name', 'new');
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
