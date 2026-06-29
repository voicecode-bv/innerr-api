<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum OnboardingStepOutcome: string implements HasColor, HasLabel
{
    /** The screen was opened, but the user has not advanced past it yet. */
    case Reached = 'reached';

    /** The user advanced past the screen by doing its intended action. */
    case Completed = 'completed';

    /** The user advanced past the screen without doing its intended action. */
    case Skipped = 'skipped';

    /**
     * Whether this outcome marks the user as having left the step. A reached
     * step is still in progress; completed and skipped are both terminal.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Reached;
    }

    public function label(): string
    {
        return match ($this) {
            self::Reached => 'Reached',
            self::Completed => 'Completed',
            self::Skipped => 'Skipped',
        };
    }

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Reached => 'gray',
            self::Completed => 'success',
            self::Skipped => 'warning',
        };
    }
}
