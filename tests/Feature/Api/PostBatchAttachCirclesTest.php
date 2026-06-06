<?php

use App\Models\Circle;
use App\Models\Post;
use App\Models\User;

it('adds the user\'s own posts to circles', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->count(2)->create(['user_id' => $user->id]);
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts/batch/circles', [
            'post_ids' => $posts->pluck('id')->all(),
            'circle_ids' => [$circle->id],
        ])
        ->assertSuccessful()
        ->assertJsonPath('updated_count', 2);

    foreach ($posts as $post) {
        expect($post->circles()->whereKey($circle->id)->exists())->toBeTrue();
    }
});

it('attaches circles without detaching the ones a post already has', function () {
    $user = User::factory()->create();
    $existingCircle = Circle::factory()->create(['user_id' => $user->id]);
    $newCircle = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create(['user_id' => $user->id]);
    $post->circles()->attach($existingCircle->id);

    $this->actingAs($user)
        ->postJson('/api/posts/batch/circles', [
            'post_ids' => [$post->id],
            'circle_ids' => [$newCircle->id],
        ])
        ->assertSuccessful();

    expect($post->circles()->pluck('circles.id')->all())
        ->toContain($existingCircle->id, $newCircle->id);
});

it('silently skips posts the user does not own', function () {
    $user = User::factory()->create();
    $ownPost = Post::factory()->create(['user_id' => $user->id]);
    $otherPost = Post::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts/batch/circles', [
            'post_ids' => [$ownPost->id, $otherPost->id],
            'circle_ids' => [$circle->id],
        ])
        ->assertSuccessful()
        ->assertJsonPath('updated_count', 1);

    expect($otherPost->circles()->whereKey($circle->id)->exists())->toBeFalse();
});

it('rejects circles the user cannot access', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    $foreignCircle = Circle::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/posts/batch/circles', [
            'post_ids' => [$post->id],
            'circle_ids' => [$foreignCircle->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('circle_ids.0');
});

it('requires authentication', function () {
    $this->postJson('/api/posts/batch/circles', [
        'post_ids' => [],
        'circle_ids' => [],
    ])->assertUnauthorized();
});
