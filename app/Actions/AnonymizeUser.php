<?php

namespace App\Actions;

use App\Models\CircleInvitation;
use App\Models\User;
use App\Services\MemberPersonSyncer;
use App\Support\MediaUrl;
use App\Support\UserStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AnonymizeUser
{
    public function __invoke(User $user): void
    {
        $userId = $user->id;

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();

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

            CircleInvitation::query()
                ->where('user_id', $user->id)
                ->orWhere('inviter_id', $user->id)
                ->delete();

            app(MemberPersonSyncer::class)->detachAll($user);

            $user->memberOfCircles()->detach();

            $user->circles()->get()->each->delete();

            $user->likes()->get()->each->delete();

            $user->comments()->get()->each->delete();

            $user->posts()->get()->each->delete();

            $placeholder = Str::ulid()->toBase32();

            $user->forceFill([
                'name' => 'Deleted user',
                'username' => 'deleted_'.$placeholder,
                'email' => 'deleted_'.$placeholder.'@deleted.local',
                'password' => Hash::make(Str::random(64)),
                'avatar' => null,
                'bio' => null,
                'fcm_token' => null,
                'notification_preferences' => null,
                'default_circle_ids' => null,
                'device_info' => null,
                'apple_id' => null,
                'google_id' => null,
                'email_verified_at' => null,
                'remember_token' => null,
                'anonymized_at' => now(),
            ])->save();
        });

        MediaUrl::disk()->deleteDirectory("users/{$userId}");

        UserStorage::reset($userId);
    }
}
