<?php

namespace App\Models;

use App\Services\MediaUploadService;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['created_by_user_id', 'user_id', 'name', 'birthdate', 'avatar', 'avatar_thumbnail'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'usage_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'usage_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Person $person) {
            if ($person->avatar !== null || $person->avatar_thumbnail !== null) {
                $media = app(MediaUploadService::class);
                $media->delete($person->avatar);
                $media->delete($person->avatar_thumbnail);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Circle, $this>
     */
    public function circles(): BelongsToMany
    {
        return $this->belongsToMany(Circle::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)->withTimestamps();
    }
}
