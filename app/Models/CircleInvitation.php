<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Database\Factories\CircleInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['circle_id', 'user_id', 'inviter_id', 'status'])]
class CircleInvitation extends Model
{
    /** @use HasFactory<CircleInvitationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Circle, $this>
     */
    public function circle(): BelongsTo
    {
        return $this->belongsTo(Circle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
