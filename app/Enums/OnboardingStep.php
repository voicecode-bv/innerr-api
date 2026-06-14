<?php

namespace App\Enums;

enum OnboardingStep: string
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
}
