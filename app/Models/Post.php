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
}
