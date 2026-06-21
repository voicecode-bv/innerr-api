<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\OnboardingStep;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('username')
                    ->required(),
                TextInput::make('avatar'),
                Textarea::make('bio')
                    ->columnSpanFull(),
                TextInput::make('locale')
                    ->required()
                    ->default('en'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password(),
                TextInput::make('notification_preferences'),
                TextInput::make('device_info'),
                TextInput::make('default_circle_ids'),
                DateTimePicker::make('anonymized_at'),
                TextInput::make('google_id'),
                TextInput::make('apple_id'),
                DateTimePicker::make('onboarded_at'),
                TextInput::make('avatar_thumbnail'),
                self::onboardingProgressSection(),
            ]);
    }

    /**
     * Read-only overview of every onboarding step, showing which the user has
     * completed (and when) and which are still outstanding.
     */
    private static function onboardingProgressSection(): Section
    {
        return Section::make('Onboarding')
            ->columnSpanFull()
            ->columns(3)
            ->hiddenOn('create')
            ->description(function (?User $record): string {
                if (! $record) {
                    return '';
                }

                $completed = $record->onboardingSteps->count();
                $total = count(OnboardingStep::cases());
                $furthest = $record->furthestOnboardingStep()?->label() ?? 'None';

                return "{$completed}/{$total} completed · Furthest step: {$furthest}";
            })
            ->schema(
                array_map(
                    fn (OnboardingStep $step): Placeholder => Placeholder::make("onboarding_{$step->value}")
                        ->label("{$step->order()}. {$step->label()}")
                        ->content(function (?User $record) use ($step): HtmlString {
                            $completedAt = $record?->onboardingSteps
                                ->firstWhere('step', $step)
                                ?->completed_at;

                            return $completedAt
                                ? new HtmlString('<span class="text-primary-600 dark:text-primary-400 font-medium">✓ '.$completedAt->format('d M Y H:i').'</span>')
                                : new HtmlString('<span class="text-gray-400">✗ Not completed</span>');
                        }),
                    OnboardingStep::cases(),
                ),
            );
    }
}
