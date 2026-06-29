<?php

namespace App\Models;

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Enums\OnboardingStepOutcome;
use Database\Factories\OnboardingStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'step', 'outcome', 'completed_at'])]
class OnboardingStep extends Model
{
    /** @use HasFactory<OnboardingStepFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => OnboardingStepEnum::class,
            'outcome' => OnboardingStepOutcome::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
