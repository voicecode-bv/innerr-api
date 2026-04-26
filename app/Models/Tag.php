<?php

namespace App\Models;

use App\Enums\TagType;
use App\Services\MediaUploadService;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['user_id', 'type', 'name', 'birthdate', 'avatar', 'avatar_thumbnail'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'tag',
        'usage_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TagType::class,
            'birthdate' => 'date',
            'usage_count' => 'integer',
        ];
    }

    public function isPerson(): bool
    {
        return $this->type === TagType::Person;
    }

    protected static function booted(): void
    {
        static::deleting(function (Tag $tag) {
            if ($tag->avatar !== null || $tag->avatar_thumbnail !== null) {
                $media = app(MediaUploadService::class);
                $media->delete($tag->avatar);
                $media->delete($tag->avatar_thumbnail);
            }
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
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)->withTimestamps();
    }
}
