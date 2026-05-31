<?php

namespace App\Models;

use App\Enums\MediaStatus;
use App\Enums\PostType;
use App\Support\PostViewerVisibility;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

#[Fillable(['user_id', 'type', 'media_url', 'media_type', 'media_status', 'thumbnail_url', 'thumbnail_small_url', 'caption', 'quote_text', 'quote_author', 'location', 'taken_at', 'coordinates'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasSpatial, HasUuids;

    protected static function booted(): void
    {
        static::deleting(function (Post $post) {
            DB::table('notifications')
                ->whereRaw("data::jsonb->>'post_id' = ?", [(string) $post->id])
                ->delete();

            $tagIds = $post->tags()->pluck('tags.id')->all();

            if ($tagIds !== []) {
                Tag::whereIn('id', $tagIds)->decrement('usage_count');
            }

            $personIds = $post->persons()->pluck('people.id')->all();

            if ($personIds !== []) {
                Person::whereIn('id', $personIds)->decrement('usage_count');
            }
        });
    }

    /**
     * Verwijder database-notificaties die naar deze post verwijzen voor
     * gebruikers die geen view-toegang meer hebben (geen owner, geen owner
     * of member van een van de huidige circles van de post). Aangeroepen
     * nadat de owner de set circles van een post wijzigt; voorkomt dat een
     * notificatie-deeplink toegang blijft geven aan iemand die net is
     * uitgesloten.
     *
     * Set-based delete in één query — geen materialisatie van viewer-IDs.
     */
    public function pruneNotificationsForLostAccess(): int
    {
        $circleIds = $this->circles()->pluck('circles.id');

        return DB::table('notifications')
            ->whereRaw("data::jsonb->>'post_id' = ?", [(string) $this->id])
            ->where('notifiable_id', '!=', $this->user_id)
            ->whereNotIn('notifiable_id', function ($q) use ($circleIds) {
                $q->select('user_id')
                    ->from('circle_user')
                    ->whereIn('circle_id', $circleIds);
            })
            ->whereNotIn('notifiable_id', function ($q) use ($circleIds) {
                $q->select('user_id')
                    ->from('circles')
                    ->whereIn('id', $circleIds);
            })
            ->delete();
    }

    /**
     * Verwijder database-notificaties voor één specifieke user als die
     * de post niet langer mag zien (geen owner én geen overige gedeelde
     * circle). Aangeroepen vanuit member-removal/leave-flows in
     * CircleMemberController: per post in de circle die de user verlaat
     * checken we of er nog een andere toegangsroute is, anders prunen we
     * die ene user zijn notificaties.
     */
    public function pruneNotificationsForUserIfLostAccess(User $user): int
    {
        if ($user->id === $this->user_id) {
            return 0;
        }

        $stillAccessible = $this->circles()
            ->where(function ($q) use ($user) {
                $q->where('circles.user_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
            })
            ->exists();

        if ($stillAccessible) {
            return 0;
        }

        return DB::table('notifications')
            ->whereRaw("data::jsonb->>'post_id' = ?", [(string) $this->id])
            ->where('notifiable_id', $user->id)
            ->delete();
    }

    /**
     * Sync the tags attached to this post and keep each tag's denormalized
     * `usage_count` in step with the changes.
     *
     * @param  array<int, string>  $tagIds
     */
    public function syncTags(array $tagIds): void
    {
        DB::transaction(function () use ($tagIds) {
            $current = $this->tags()->pluck('tags.id')->all();
            $toAttach = array_values(array_diff($tagIds, $current));
            $toDetach = array_values(array_diff($current, $tagIds));

            if ($toAttach !== []) {
                $this->tags()->attach($toAttach);
                Tag::whereIn('id', $toAttach)->increment('usage_count');
            }

            if ($toDetach !== []) {
                $this->tags()->detach($toDetach);
                Tag::whereIn('id', $toDetach)->decrement('usage_count');
            }
        });
    }

    /**
     * Sync the persons attached to this post and keep each person's
     * denormalized `usage_count` in step with the changes.
     *
     * @param  array<int, string>  $personIds
     */
    public function syncPersons(array $personIds): void
    {
        DB::transaction(function () use ($personIds) {
            $current = $this->persons()->pluck('people.id')->all();
            $toAttach = array_values(array_diff($personIds, $current));
            $toDetach = array_values(array_diff($current, $personIds));

            if ($toAttach !== []) {
                $this->persons()->attach($toAttach);
                Person::whereIn('id', $toAttach)->increment('usage_count');
            }

            if ($toDetach !== []) {
                $this->persons()->detach($toDetach);
                Person::whereIn('id', $toDetach)->decrement('usage_count');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'media_status' => MediaStatus::class,
            'taken_at' => 'datetime',
            'coordinates' => Point::class,
        ];
    }

    protected function latitude(): Attribute
    {
        return Attribute::get(fn (): ?float => $this->coordinates?->latitude);
    }

    protected function longitude(): Attribute
    {
        return Attribute::get(fn (): ?float => $this->coordinates?->longitude);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return MorphMany<Like, $this>
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /**
     * @return BelongsToMany<Circle, $this>
     */
    public function circles(): BelongsToMany
    {
        return $this->belongsToMany(Circle::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class)->withTimestamps();
    }

    /**
     * @return HasMany<PostMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('sort_order');
    }

    /**
     * De meest recente liker die voor deze viewer zichtbaar is — gebruikt
     * door de "X en N anderen vinden dit leuk" regel onder een post. Geeft
     * `null` als er geen likes zijn of geen enkele liker zichtbaar is voor
     * deze viewer.
     *
     * Per-model gecached zodat een Resource die de gerelateerde user nogmaals
     * uitleest geen extra query veroorzaakt.
     */
    public function firstVisibleLikerFor(User $viewer): ?User
    {
        $cacheKey = "first_visible_liker_for:{$viewer->id}";

        if (array_key_exists($cacheKey, $this->relations)) {
            $cached = $this->relations[$cacheKey];

            return $cached instanceof User ? $cached : null;
        }

        if (($this->likes_count ?? 0) === 0) {
            $this->setRelation($cacheKey, null);

            return null;
        }

        $visibility = PostViewerVisibility::for($viewer, $this);

        $query = $this->likes()->latest()->with('user:id,name,username,avatar');
        $visibility->scopeLikesQuery($query);

        $like = $query->first();

        $user = $like?->user;
        $this->setRelation($cacheKey, $user);

        return $user;
    }
}
