<?php

namespace App\Models;

use Database\Factories\LikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'likeable_id', 'likeable_type'])]
class Like extends Model
{
    /** @use HasFactory<LikeFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::created(function (Like $like) {
            $like->likeable()->increment('likes_count');
        });

        static::deleted(function (Like $like) {
            $like->likeable()->decrement('likes_count');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }
}
