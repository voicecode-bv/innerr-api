<?php

namespace App\Enums;

enum NotificationPreference: string
{
    case PostLiked = 'post_liked';
    case PostCommented = 'post_commented';
    case CommentLiked = 'comment_liked';
    case NewCirclePost = 'new_circle_post';
    case CircleInvitationAccepted = 'circle_invitation_accepted';

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::PostLiked->value => false,
            self::PostCommented->value => true,
            self::CommentLiked->value => true,
            self::NewCirclePost->value => true,
            self::CircleInvitationAccepted->value => true,
        ];
    }
}
