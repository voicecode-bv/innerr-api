<?php

namespace App\Enums;

enum OnboardingStep: string
{
    case Intro = 'intro';
    case FirstCircle = 'first_circle';
    case AddChildren = 'add_children';
    case InviteMembers = 'invite_members';
    case Notifications = 'notifications';
}
