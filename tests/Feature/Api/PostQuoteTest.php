<?php

use App\Models\Circle;
use App\Models\Person;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores a quote post with text and author attributed to a tagged person', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $child = Person::factory()->create(['created_by_user_id' => $user->id]);
    $child->circles()->attach($circle->id);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('quote.jpg'),
            'type' => 'quote',
            'quote_text' => 'I want to be a dinosaur when I grow up.',
            'quote_author' => $child->name,
            'circle_ids' => [$circle->id],
            'person_ids' => [$child->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'quote')
        ->assertJsonPath('data.quote_text', 'I want to be a dinosaur when I grow up.')
        ->assertJsonPath('data.quote_author', $child->name);

    $post = Post::first();
    expect($post->type->value)->toBe('quote')
        ->and($post->quote_text)->toBe('I want to be a dinosaur when I grow up.')
        ->and($post->quote_author)->toBe($child->name)
        ->and($post->media_type)->toBe('image')
        ->and($post->persons()->pluck('people.id')->all())->toBe([$child->id]);
});

it('defaults to a media post when no type is sent', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('a.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'media')
        ->assertJsonPath('data.quote_text', null);

    expect(Post::first()->type->value)->toBe('media');
});

it('requires quote_text when the post is a quote', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('a.jpg'),
            'type' => 'quote',
            'circle_ids' => [$circle->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quote_text');
});

it('rejects an unknown post type', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('a.jpg'),
            'type' => 'banana',
            'circle_ids' => [$circle->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});
