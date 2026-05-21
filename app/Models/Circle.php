<?php

namespace App\Models;

use App\Enums\CircleMemberRole;
use Database\Factories\CircleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['user_id', 'name', 'photo', 'members_can_invite', 'members_can_view_members', 'members_can_download', 'auto_add_new_users'])]
class Circle extends Model
{
    /** @use HasFactory<CircleFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'members_can_invite' => 'boolean',
            'members_can_view_members' => 'boolean',
            'members_can_download' => 'boolean',
            'auto_add_new_users' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function administrators(): BelongsToMany
    {
        return $this->members()->wherePivot('role', CircleMemberRole::Administrator->value);
    }

    public function isAdministrator(User $user): bool
    {
        return $this->administrators()->whereKey($user->id)->exists();
    }

    public function isOwnerOrAdministrator(User $user): bool
    {
        if ($user->id === $this->user_id) {
            return true;
        }

        return $this->isAdministrator($user);
    }

    /**
     * Adds a `viewer_role` select column with the given user's role in this circle
     * ('administrator'/'member'), or null if they are not a member. The owner is
     * not in the pivot table, so the column will be null for them.
     *
     * @param  Builder<Circle>  $query
     */
    public function scopeWithViewerRole(Builder $query, string $userId): Builder
    {
        return $query->addSelect([
            'viewer_role' => DB::table('circle_user')
                ->select('role')
                ->whereColumn('circle_id', 'circles.id')
                ->where('user_id', $userId)
                ->limit(1),
        ]);
    }

    /**
     * Resolves the given user's role in this circle. Uses the pre-loaded
     * `viewer_role` attribute (from `withViewerRole()`) when available;
     * otherwise issues a single indexed lookup against `circle_user`.
     */
    public function resolveViewerRole(string $userId): ?string
    {
        if (array_key_exists('viewer_role', $this->attributes)) {
            return $this->attributes['viewer_role'];
        }

        return DB::table('circle_user')
            ->where('circle_id', $this->id)
            ->where('user_id', $userId)
            ->value('role');
    }

    /**
     * @return HasMany<CircleInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CircleInvitation::class);
    }

    /**
     * @return HasMany<CircleOwnershipTransfer, $this>
     */
    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(CircleOwnershipTransfer::class);
    }

    /**
     * @return HasMany<CircleInviteLink, $this>
     */
    public function inviteLinks(): HasMany
    {
        return $this->hasMany(CircleInviteLink::class);
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class)->withTimestamps();
    }
}
