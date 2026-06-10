<?php

namespace App\Models;

use App\Enums\Entitlement;
use App\Enums\FeedLayout;
use App\Enums\NotificationPreference;
use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Enums\SubscriptionStatus;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Soved\Laravel\Gdpr\Contracts\Portable as PortableContract;
use Soved\Laravel\Gdpr\Portable;

#[Fillable(['name', 'username', 'email', 'password', 'avatar', 'avatar_thumbnail', 'bio', 'searchable', 'feed_layout', 'locale', 'notification_preferences', 'default_circle_ids', 'child_filter_ids', 'device_info', 'google_id', 'apple_id', 'mollie_customer_id', 'onboarded_at'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, PortableContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, Portable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'notification_preferences' => '{"post_liked":true,"post_commented":true,"comment_replied":true,"comment_liked":true,"new_circle_post":true,"post_tagged":true,"circle_invitation_received":true,"circle_invitation_accepted":true,"circle_ownership_transfer_requested":true,"circle_ownership_transfer_accepted":true,"circle_ownership_transfer_declined":true}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'feature_tour_started_at' => 'datetime',
            'feature_tour_completed_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'default_circle_ids' => 'array',
            'child_filter_ids' => 'array',
            'device_info' => 'array',
            'storage_used_bytes' => 'integer',
            'admin' => 'boolean',
            'searchable' => 'boolean',
            'feed_layout' => FeedLayout::class,
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

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<OnboardingStep, $this>
     */
    public function onboardingSteps(): HasMany
    {
        return $this->hasMany(OnboardingStep::class);
    }

    /**
     * The furthest completed onboarding step, ranked by flow position rather
     * than timestamp so back-and-forth navigation can never move the resume
     * point backwards. Null when no steps were tracked yet.
     */
    public function furthestOnboardingStep(): ?OnboardingStepEnum
    {
        $rank = array_flip(array_column(OnboardingStepEnum::cases(), 'value'));

        return $this->onboardingSteps
            ->sortByDesc(fn (OnboardingStep $step): int => $rank[$step->step->value] ?? -1)
            ->first()
            ?->step;
    }

    /**
     * @return HasMany<FeatureTourStep, $this>
     */
    public function featureTourSteps(): HasMany
    {
        return $this->hasMany(FeatureTourStep::class);
    }

    /**
     * @return HasMany<DeviceToken, $this>
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->ofMany(
                ['current_period_end' => 'max', 'id' => 'max'],
                fn ($query) => $query->whereIn('status', SubscriptionStatus::entitledValues()),
            );
    }

    private ?Plan $cachedCurrentPlan = null;

    public function currentPlan(): Plan
    {
        if ($this->cachedCurrentPlan instanceof Plan) {
            return $this->cachedCurrentPlan;
        }

        $planId = Cache::remember(
            self::planCacheKey($this->id),
            now()->addDay(),
            function (): string {
                $sub = $this->subscriptions()
                    ->whereIn('status', SubscriptionStatus::entitledValues())
                    ->orderByDesc('current_period_end')
                    ->orderByDesc('id')
                    ->first();

                return $sub?->plan_id ?? Plan::default()->id;
            },
        );

        return $this->cachedCurrentPlan = Plan::query()->findOrFail($planId);
    }

    public function hasEntitlement(Entitlement|string $entitlement): bool
    {
        return $this->currentPlan()->grants($entitlement);
    }

    public function isOnPaidPlan(): bool
    {
        return $this->currentPlan()->tier > 0;
    }

    public static function planCacheKey(string $userId): string
    {
        return "subscriptions:user:{$userId}:plan";
    }

    public static function flushPlanCache(string $userId): void
    {
        Cache::forget(self::planCacheKey($userId));
    }

    public function wantsPushNotification(NotificationPreference $type): bool
    {
        return ($this->notification_preferences ?? NotificationPreference::defaults())[$type->value] ?? true;
    }

    /**
     * @return array<int, string>
     */
    public function routeNotificationForFcm(): array
    {
        return $this->deviceTokens()->pluck('token')->all();
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Whether this account must verify its email before accessing the app.
     * Accounts that existed before verification was enforced were backfilled
     * with a verified timestamp, so this is simply "not yet verified".
     */
    public function requiresEmailVerification(): bool
    {
        return $this->email_verified_at === null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->admin === true;
    }

    /**
     * The relations to include in the downloadable data.
     *
     * @var array
     */
    protected $gdprWith = ['posts', 'likes', 'comments', 'circles', 'subscriptions'];

    /**
     * The attributes that should be hidden for the downloadable data.
     *
     * @var array
     */
    protected $gdprHidden = ['password'];
}
