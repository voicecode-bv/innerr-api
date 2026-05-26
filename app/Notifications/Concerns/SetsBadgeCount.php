<?php

namespace App\Notifications\Concerns;

use NotificationChannels\Fcm\FcmMessage;

trait SetsBadgeCount
{
    /**
     * Add the recipient's unread-notification count to an FCM message so the OS
     * can render it on the app icon badge.
     *
     * iOS reads `apns.payload.aps.badge` and renders the number on the app icon
     * automatically. Android icon-badge counts are launcher-dependent, so
     * `android.notification.notification_count` is best-effort.
     *
     * The `database` and `fcm` channels are dispatched as independent queued
     * jobs with no guaranteed order, so the freshly created database
     * notification may not be persisted yet when this runs. We therefore count
     * the *other* unread notifications and add one for the notification being
     * delivered. This assumes the notification also persists to the `database`
     * channel, which every push-capable notification in this app does.
     */
    protected function withBadgeCount(FcmMessage $message, object $notifiable): FcmMessage
    {
        if (! method_exists($notifiable, 'unreadNotifications')) {
            return $message;
        }

        $unreadCount = $notifiable->unreadNotifications()
            ->when($this->id, fn ($query) => $query->whereKeyNot($this->id))
            ->count() + 1;

        return $message
            ->ios(['payload' => ['aps' => ['badge' => $unreadCount]]])
            ->android(['notification' => ['notification_count' => $unreadCount]]);
    }
}
