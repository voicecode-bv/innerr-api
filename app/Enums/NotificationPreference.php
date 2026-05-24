<?php

namespace App\Enums;

enum NotificationPreference: string
{
    case PostLiked = 'post_liked';
    case PostCommented = 'post_commented';
    case CommentReplied = 'comment_replied';
    case CommentLiked = 'comment_liked';
    case NewCirclePost = 'new_circle_post';
    case PostTagged = 'post_tagged';
    case CircleInvitationReceived = 'circle_invitation_received';
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
            self::PostLiked->value => true,
            self::PostCommented->value => true,
            self::CommentReplied->value => true,
            self::CommentLiked->value => true,
            self::NewCirclePost->value => true,
            self::PostTagged->value => true,
            self::CircleInvitationReceived->value => true,
            self::CircleInvitationAccepted->value => true,
            self::CircleOwnershipTransferRequested->value => true,
            self::CircleOwnershipTransferAccepted->value => true,
            self::CircleOwnershipTransferDeclined->value => true,
        ];
    }
}
