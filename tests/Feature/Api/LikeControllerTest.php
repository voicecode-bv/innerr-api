<?php

use App\Models\Post;
use App\Models\User;

it('cannot like own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/posts/{$post->id}/like")
        ->assertForbidden();

    $this->assertDatabaseMissing('likes', [
        'likeable_id' => $post->id,
        'likeable_type' => Post::class,
    ]);
});

it('can like another users post', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/like")
        ->assertCreated()
        ->assertJsonPath('liked', true)
        ->assertJsonPath('likes_count', 1);
});
