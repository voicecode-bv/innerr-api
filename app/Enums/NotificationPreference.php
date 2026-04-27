<?php

namespace App\Enums;

enum NotificationPreference: string
{
    case PostLiked = 'post_liked';
    case PostCommented = 'post_commented';
    case CommentLiked = 'comment_liked';
    case CommentReplied = 'comment_replied';
    case NewCirclePost = 'new_circle_post';
    case PostTagged = 'post_tagged';
    case CircleInvitationAccepted = 'circle_invitation_accepted';
    case CircleOwnershipTransferRequested = 'circle_ownership_transfer_requested';
    case CircleOwnershipTransferAccepted = 'circle_ownership_transfer_accepted';
    case CircleOwnershipTransferDeclined = 'circle_ownership_transfer_declined';

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::PostLiked->value => false,
            self::PostCommented->value => true,
            self::CommentLiked->value => true,
            self::CommentReplied->value => true,
            self::NewCirclePost->value => true,
            self::PostTagged->value => true,
            self::CircleInvitationAccepted->value => true,
            self::CircleOwnershipTransferRequested->value => true,
            self::CircleOwnershipTransferAccepted->value => true,
            self::CircleOwnershipTransferDeclined->value => true,
        ];
    }
}
