<?php

namespace App\Models;

use App\Enums\AppPlatform;
use Database\Factories\AppReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['platform', 'latest_version', 'minimum_version', 'store_url'])]
class AppRelease extends Model
{
    /** @use HasFactory<AppReleaseFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => AppPlatform::class,
        ];
    }
}
