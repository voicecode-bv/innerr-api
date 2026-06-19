<?php

namespace App\Models;

use App\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

#[Fillable([
    'post_id', 'sort_order', 'path', 'original_path', 'source_path', 'type',
    'format', 'status', 'thumbnail_path', 'thumbnail_small_path', 'width',
    'height', 'crop', 'taken_at', 'coordinates', 'external_job_id',
    'processing_started_at',
])]
class PostMedia extends Model
{
    use HasSpatial, HasUuids;

    protected $table = 'post_media';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MediaStatus::class,
            'taken_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'coordinates' => Point::class,
            'crop' => 'array',
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
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
