<?php

namespace App\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

enum OnboardingStep: string implements HasColor, HasIcon, HasLabel
{
    case Intro = 'intro';
    case FirstCircle = 'first_circle';
    case AddChildren = 'add_children';
    case FirstMoment = 'first_moment';
    case InviteMembers = 'invite_members';
    case Notifications = 'notifications';

    public function label(): string
    {
        return match ($this) {
            self::Intro => 'Intro',
            self::FirstCircle => 'First circle',
            self::AddChildren => 'Add children',
            self::FirstMoment => 'First moment',
            self::InviteMembers => 'Invite members',
            self::Notifications => 'Notifications',
        };
    }

    /**
     * The position of this step within the onboarding flow, starting at 1.
     */
    public function order(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function getLabel(): string|Htmlable|null
    {
        return $this->label();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Intro => 'gray',
            self::FirstCircle => 'info',
            self::AddChildren => 'warning',
            self::FirstMoment => 'primary',
            self::InviteMembers => 'success',
            self::Notifications => 'success',
        };
    }

    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Intro => Heroicon::OutlinedHandRaised,
            self::FirstCircle => Heroicon::OutlinedUserGroup,
            self::AddChildren => Heroicon::OutlinedFaceSmile,
            self::FirstMoment => Heroicon::OutlinedCamera,
            self::InviteMembers => Heroicon::OutlinedEnvelope,
            self::Notifications => Heroicon::OutlinedBell,
        };
    }
}
