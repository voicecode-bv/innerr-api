<?php

namespace App\Models;

use App\Enums\MediaStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

#[Fillable(['user_id', 'media_url', 'media_type', 'media_status', 'thumbnail_url', 'thumbnail_small_url', 'caption', 'location', 'taken_at', 'coordinates'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, HasSpatial;

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
        });
    }

    /**
     * Sync the tags attached to this post and keep each tag's denormalized
     * `usage_count` in step with the changes.
     *
     * @param  array<int, int>  $tagIds
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
