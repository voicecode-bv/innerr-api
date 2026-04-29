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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Soved\Laravel\Gdpr\Contracts\Portable as PortableContract;
use Soved\Laravel\Gdpr\Portable;

#[Fillable(['name', 'username', 'email', 'password', 'avatar', 'avatar_thumbnail', 'bio', 'locale', 'fcm_token', 'notification_preferences', 'default_circle_ids', 'device_info', 'google_id', 'apple_id', 'onboarded_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, PortableContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Portable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'notification_preferences' => '{"post_liked":true,"post_commented":true,"comment_liked":true,"comment_replied":true,"new_circle_post":true,"post_tagged":true,"circle_invitation_accepted":true,"circle_ownership_transfer_requested":true,"circle_ownership_transfer_accepted":true,"circle_ownership_transfer_declined":true}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'default_circle_ids' => 'array',
            'device_info' => 'array',
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
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
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

    /**
     * @return HasOne<Person, $this>
     */
    public function person(): HasOne
    {
        return $this->hasOne(Person::class);
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

    /**
     * The relations to include in the downloadable data.
     *
     * @var array
     */
    protected $gdprWith = ['posts', 'likes', 'comments', 'circles'];

    /**
     * The attributes that should be hidden for the downloadable data.
     *
     * @var array
     */
    protected $gdprHidden = ['password', 'fcm_token'];
}
