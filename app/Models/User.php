<?php

namespace App\Models;

use App\Enums\NotificationPreference;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'avatar', 'bio', 'locale', 'fcm_token', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'notification_preferences' => '{"post_liked":false,"post_commented":true,"comment_liked":true,"new_circle_post":true,"circle_invitation_accepted":true}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<Like, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    /**
     * @return HasMany<Circle, $this>
     */
    public function circles(): HasMany
    {
        return $this->hasMany(Circle::class);
    }

    /**
     * @return BelongsToMany<Circle, $this>
     */
    public function memberOfCircles(): BelongsToMany
    {
        return $this->belongsToMany(Circle::class)->withTimestamps();
    }

    /**
     * @return HasMany<CircleInvitation, $this>
     */
    public function circleInvitations(): HasMany
    {
        return $this->hasMany(CircleInvitation::class);
    }

    public function wantsPushNotification(NotificationPreference $type): bool
    {
        return ($this->notification_preferences ?? NotificationPreference::defaults())[$type->value] ?? true;
    }

    public function routeNotificationForFcm(): ?string
    {
        return $this->fcm_token;
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
