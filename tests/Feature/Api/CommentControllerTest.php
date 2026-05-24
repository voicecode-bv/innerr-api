<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentReplied;
use App\Notifications\PostCommented;
use Illuminate\Support\Facades\Notification;

it('returns paginated comments for a post, oldest first', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->create();
    shareCircle($post, $viewer, $author);

    $oldest = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
        'created_at' => now()->subMinutes(10),
    ]);
    $newest = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
        'created_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $oldest->id)
        ->assertJsonPath('data.1.id', $newest->id)
        ->assertJsonStructure([
            'data' => [
                ['id', 'is_visible', 'body', 'created_at', 'user' => ['id']],
            ],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('paginates comments at 20 per page', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->create();
    shareCircle($post, $viewer, $author);

    Comment::factory()->count(25)->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
    ]);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments?page=2")
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('requires authentication to list comments', function () {
    $post = Post::factory()->create();

    $this->getJson("/api/posts/{$post->id}/comments")
        ->assertUnauthorized();
});

it('returns not found when listing comments for a non-existent post', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/posts/99999/comments')
        ->assertNotFound();
});

it('reflects is_liked on listed comments for the authenticated user', function () {
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->create();
    shareCircle($post, $viewer, $other, $author);

    $comment = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
    ]);
    $comment->likes()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', true);

    $this->actingAs($other)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', false);
});

it('hides comments from authors not sharing a circle with the viewer (v2 client)', function () {
    $viewer = User::factory()->create();
    $insider = User::factory()->create();
    $outsider = User::factory()->create();
    $post = Post::factory()->create();

    // Viewer en insider delen circle A; outsider zit in circle B.
    shareCircle($post, $viewer, $insider);
    shareCircle($post, $outsider);

    $visibleComment = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $insider->id,
        'body' => 'Hello from circle A',
        'created_at' => now()->subMinute(),
    ]);
    $hiddenComment = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $outsider->id,
        'body' => 'Secret from circle B',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($viewer)
        ->withHeaders(['X-App-Version' => '1.1.0'])
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk();

    $response->assertJsonPath('data.0.id', $visibleComment->id);
    $response->assertJsonPath('data.0.is_visible', true);
    $response->assertJsonPath('data.0.body', 'Hello from circle A');

    $response->assertJsonPath('data.1.id', $hiddenComment->id);
    $response->assertJsonPath('data.1.is_visible', false);
    $response->assertJsonMissingPath('data.1.body');
    $response->assertJsonMissingPath('data.1.user');
    $response->assertJsonMissingPath('data.1.likes_count');
});

it('keeps the total count accurate including hidden comments (v2 client)', function () {
    $viewer = User::factory()->create();
    $insider = User::factory()->create();
    $outsider = User::factory()->create();
    $post = Post::factory()->create();

    shareCircle($post, $viewer, $insider);
    shareCircle($post, $outsider);

    Comment::factory()->count(3)->create(['post_id' => $post->id, 'user_id' => $insider->id]);
    Comment::factory()->count(2)->create(['post_id' => $post->id, 'user_id' => $outsider->id]);

    $this->actingAs($viewer)
        ->withHeaders(['X-App-Version' => '1.1.0'])
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('meta.total', 5);
});

it('silently filters hidden comments without placeholders for old clients (no X-App-Version)', function () {
    $viewer = User::factory()->create();
    $insider = User::factory()->create();
    $outsider = User::factory()->create();
    $post = Post::factory()->create();

    shareCircle($post, $viewer, $insider);
    shareCircle($post, $outsider);

    $visibleComment = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $insider->id,
        'body' => 'Hello from circle A',
        'created_at' => now()->subMinute(),
    ]);
    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $outsider->id,
        'body' => 'Secret from circle B',
        'created_at' => now(),
    ]);

    // Geen X-App-Version header → oude SPA pad. Verborgen comments worden
    // weggefiltered op query-niveau, geen is_visible:false placeholders die
    // de oude client niet zou kunnen renderen.
    $response = $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visibleComment->id)
        ->assertJsonPath('data.0.body', 'Hello from circle A')
        ->assertJsonPath('meta.total', 1);

    // Een oude client mag op user.username e.d. accessen zonder crash.
    expect($response->json('data.0.user'))->toBeArray();
});

it('shows comments to a circle owner who is not in the circle_user pivot (v2 client)', function () {
    // Reproductie van het productiescenario: de viewer is owner van de circle
    // (circles.user_id), niet geattached via circle_user. Zonder de fix
    // verschijnen comments van members als "verborgen" voor de owner.
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $post = Post::factory()->create();
    shareCircleOwnedBy($post, $owner, $member);

    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $member->id,
        'body' => 'Hi from member',
    ]);

    $this->actingAs($owner)
        ->withHeaders(['X-App-Version' => '1.1.0'])
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_visible', true)
        ->assertJsonPath('data.0.body', 'Hi from member');
});

it('shows a member the comments of the circle owner (v2 client)', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $post = Post::factory()->create();
    shareCircleOwnedBy($post, $owner, $member);

    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $owner->id,
        'body' => 'Hi from owner',
    ]);

    $this->actingAs($member)
        ->withHeaders(['X-App-Version' => '1.1.0'])
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_visible', true)
        ->assertJsonPath('data.0.body', 'Hi from owner');
});

it('shows comments to a circle owner who is not in the circle_user pivot (old client)', function () {
    // Zelfde scenario maar zonder X-App-Version: oude SPA pad. Zonder de fix
    // ziet de viewer alleen eigen reacties; dit dekt precies de productiebug.
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $post = Post::factory()->create();
    shareCircleOwnedBy($post, $owner, $member);

    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $member->id,
        'body' => 'Hi from member',
    ]);

    $this->actingAs($owner)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Hi from member');
});

it('shows a member the comments of the circle owner (old client)', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $post = Post::factory()->create();
    shareCircleOwnedBy($post, $owner, $member);

    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $owner->id,
        'body' => 'Hi from owner',
    ]);

    $this->actingAs($member)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Hi from owner');
});

it('treats clients with X-App-Version below the v2 threshold as old clients', function () {
    $viewer = User::factory()->create();
    $insider = User::factory()->create();
    $outsider = User::factory()->create();
    $post = Post::factory()->create();

    shareCircle($post, $viewer, $insider);
    shareCircle($post, $outsider);

    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $insider->id]);
    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $outsider->id]);

    $this->actingAs($viewer)
        ->withHeaders(['X-App-Version' => '1.0.9'])
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('blocks comment listing when viewer has no shared circle with the post', function () {
    $viewer = User::factory()->create();
    $post = Post::factory()->create();
    // Post zit alleen in een circle waar de viewer niet in zit.
    shareCircle($post, User::factory()->create());

    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $viewer->id,
        'body' => 'My own thought',
    ]);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertForbidden();
});

it('shows all comments when the post is in a single shared circle', function () {
    $viewer = User::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $post = Post::factory()->create();
    shareCircle($post, $viewer, $alice, $bob);

    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $alice->id]);
    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $bob->id]);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_visible', true)
        ->assertJsonPath('data.1.is_visible', true);
});

it('shows a comment when the author shares at least one of multiple post circles with the viewer', function () {
    $viewer = User::factory()->create();
    $author = User::factory()->create();
    $post = Post::factory()->create();

    // Post zit in 2 circles; viewer en author hebben er één gedeeld, één niet.
    shareCircle($post, $viewer, $author); // gedeelde circle
    shareCircle($post, $viewer);          // alleen viewer
    shareCircle($post, $author);          // alleen author

    Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
        'body' => 'Shared circle wins',
    ]);

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_visible', true)
        ->assertJsonPath('data.0.body', 'Shared circle wins');
});

it('can store a comment on a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    shareCircle($post, $user);

    $this->actingAs($user)
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Great post!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Great post!')
        ->assertJsonStructure([
            'data' => [
                'id', 'body', 'created_at', 'updated_at',
                'user' => ['id', 'name', 'username', 'avatar'],
            ],
        ]);

    $this->assertDatabaseHas('comments', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Great post!',
    ]);
});

it('validates comment body is required', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/comments", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('validates comment body max length', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('requires authentication to store a comment', function () {
    $post = Post::factory()->create();

    $this->postJson("/api/posts/{$post->id}/comments", ['body' => 'Hello'])
        ->assertUnauthorized();
});

it('throttles comment creation', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    shareCircle($post, $user);

    $this->actingAs($user);

    foreach (range(1, 30) as $i) {
        $this->postJson("/api/posts/{$post->id}/comments", ['body' => "Comment {$i}"])
            ->assertCreated();
    }

    $this->postJson("/api/posts/{$post->id}/comments", ['body' => 'Too many'])
        ->assertStatus(429);
});

it('returns not found for commenting on non-existent post', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/posts/99999/comments', ['body' => 'Hello'])
        ->assertNotFound();
});

it('can delete own comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
});

it('post owner can delete any comment on their post', function () {
    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $comment = Comment::factory()->create(['post_id' => $post->id]);

    $this->actingAs($postOwner)
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
});

it('cannot delete another users comment on someone elses post', function () {
    $comment = Comment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertForbidden();
});

it('requires authentication to delete a comment', function () {
    $comment = Comment::factory()->create();

    $this->deleteJson("/api/comments/{$comment->id}")
        ->assertUnauthorized();
});

it('notifies the post owner when a comment is added', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $commenter = User::factory()->create();
    shareCircle($post, $commenter);

    $this->actingAs($commenter)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Hi!'])
        ->assertCreated();

    Notification::assertSentTo($postOwner, PostCommented::class);
});

it('does not notify the post owner when they comment on their own post', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);

    $this->actingAs($postOwner)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Self note'])
        ->assertCreated();

    Notification::assertNotSentTo($postOwner, PostCommented::class);
});

it('notifies prior commenters and the post owner, but not the new commenter', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $priorCommenter = User::factory()->create();
    $newCommenter = User::factory()->create();
    shareCircle($post, $priorCommenter, $newCommenter);

    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $priorCommenter->id]);

    $this->actingAs($newCommenter)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Joining the conversation'])
        ->assertCreated();

    Notification::assertSentTo($postOwner, PostCommented::class);
    Notification::assertSentTo($priorCommenter, CommentReplied::class);

    // De eigenaar krijgt nooit de deelnemer-notificatie (geen dubbele notificatie).
    Notification::assertNotSentTo($postOwner, CommentReplied::class);

    // De reageerder zelf krijgt niets.
    Notification::assertNotSentTo($newCommenter, CommentReplied::class);
    Notification::assertNotSentTo($newCommenter, PostCommented::class);
});

it('notifies prior commenters when the post owner replies in their own thread', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $priorCommenter = User::factory()->create();
    shareCircle($post, $priorCommenter);

    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $priorCommenter->id]);

    $this->actingAs($postOwner)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Thanks everyone!'])
        ->assertCreated();

    Notification::assertSentTo($priorCommenter, CommentReplied::class);
    Notification::assertNotSentTo($postOwner, CommentReplied::class);
    Notification::assertNotSentTo($postOwner, PostCommented::class);
});

it('does not notify a commenter about their own follow-up comment', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $commenter = User::factory()->create();
    shareCircle($post, $commenter);

    Comment::factory()->create(['post_id' => $post->id, 'user_id' => $commenter->id]);

    $this->actingAs($commenter)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Adding more'])
        ->assertCreated();

    Notification::assertNotSentTo($commenter, CommentReplied::class);
});
