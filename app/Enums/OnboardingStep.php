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
}
