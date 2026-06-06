<?php

namespace App\Actions;

use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a user account and everything attached to it.
 *
 * Most child records (posts, comments, likes, circles, subscriptions, …) are
 * removed by database `ON DELETE CASCADE` constraints, so we only clean up the
 * rows that aren't cascaded (auth tokens, sessions, password-reset tokens and
 * polymorphic notifications) before deleting the user. Finally the user's whole
 * media folder on object storage (`users/{id}`) is wiped.
 */
class DeleteUser
{
    public function __invoke(User $user): void
    {
        $userId = $user->id;

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();

            $user->deviceTokens()->delete();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            DB::table('notifications')
                ->where(function ($query) use ($user) {
                    $query->where(function ($q) use ($user) {
                        $q->where('notifiable_type', $user->getMorphClass())
                            ->where('notifiable_id', $user->id);
                    })->orWhereRaw("data::jsonb->>'user_id' = ?", [(string) $user->id]);
                })
                ->delete();

            $user->delete();
        });

        MediaUrl::disk()->deleteDirectory("users/{$userId}");
    }
}
