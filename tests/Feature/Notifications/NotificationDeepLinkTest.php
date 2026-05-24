<?php

use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\CircleOwnershipTransfer;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CircleInvitationAcceptedNotification;
use App\Notifications\CircleInvitationReceivedNotification;
use App\Notifications\CircleMemberInvitedByMemberNotification;
use App\Notifications\CircleOwnershipTransferAcceptedNotification;
use App\Notifications\CircleOwnershipTransferDeclinedNotification;
use App\Notifications\CircleOwnershipTransferRequestedNotification;
use App\Notifications\CommentLiked;
use App\Notifications\NewCirclePost;
use App\Notifications\PostCommented;
use App\Notifications\PostLiked;
use App\Notifications\PostTagged;
use Illuminate\Notifications\Notification;

/**
 * The native app reads the `data.link` key on a tapped push notification and
 * routes the SPA to that path, so every push must carry the deep link to its
 * relevant content.
 */
function fcmDeepLink(Notification $notification): ?string
{
    return $notification->toFcm(new User)->toArray()['data']['link'] ?? null;
}

it('deep-links post interactions to the post', function () {
    $user = new User(['name' => 'Alice']);
    $user->id = 1;

    $post = new Post;
    $post->id = 7;

    expect(fcmDeepLink(new PostLiked($user, $post)))->toBe('/posts/7')
        ->and(fcmDeepLink(new PostTagged($user, $post)))->toBe('/posts/7')
        ->and(fcmDeepLink(new NewCirclePost($user, $post)))->toBe('/posts/7');
});

it('deep-links comment notifications to the comment thread', function () {
    $user = new User(['name' => 'Alice']);
    $user->id = 1;

    $post = new Post;
    $post->id = 7;

    $comment = new Comment;
    $comment->id = 99;
    $comment->post_id = 7;
    $comment->body = 'Nice one!';

    expect(fcmDeepLink(new PostCommented($user, $post, $comment)))->toBe('/posts/7?comment=99')
        ->and(fcmDeepLink(new CommentLiked($user, $comment)))->toBe('/posts/7?comment=99');
});

it('deep-links actionable circle notifications to the notifications inbox', function () {
    $circle = new Circle;
    $circle->id = 3;
    $circle->name = 'Friends';

    $invitation = new CircleInvitation;
    $invitation->id = 11;
    $invitation->circle_id = 3;
    $invitation->setRelation('circle', $circle);

    $transfer = new CircleOwnershipTransfer;
    $transfer->id = 5;
    $transfer->circle_id = 3;
    $transfer->setRelation('circle', $circle);
    $transfer->setRelation('fromUser', new User(['name' => 'Bob']));

    expect(fcmDeepLink(new CircleInvitationReceivedNotification($invitation, 'Bob')))->toBe('/notifications')
        ->and(fcmDeepLink(new CircleOwnershipTransferRequestedNotification($transfer)))->toBe('/notifications');
});

it('deep-links informational circle notifications to the circle', function () {
    $circle = new Circle;
    $circle->id = 3;
    $circle->name = 'Friends';

    $invitation = new CircleInvitation;
    $invitation->id = 11;
    $invitation->circle_id = 3;
    $invitation->setRelation('circle', $circle);

    $transfer = new CircleOwnershipTransfer;
    $transfer->id = 5;
    $transfer->circle_id = 3;
    $transfer->setRelation('circle', $circle);
    $transfer->setRelation('fromUser', new User(['name' => 'Bob']));
    $transfer->setRelation('toUser', new User(['name' => 'Carol']));

    expect(fcmDeepLink(new CircleInvitationAcceptedNotification($invitation, 'Bob')))->toBe('/circles/3')
        ->and(fcmDeepLink(new CircleMemberInvitedByMemberNotification($invitation, 'Bob', 'Carol')))->toBe('/circles/3')
        ->and(fcmDeepLink(new CircleOwnershipTransferAcceptedNotification($transfer)))->toBe('/circles/3')
        ->and(fcmDeepLink(new CircleOwnershipTransferDeclinedNotification($transfer)))->toBe('/circles/3');
});
