<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

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
                TextInput::make('donation_percentage')
                    ->label('Donation percentage')
                    ->helperText('Percentage of revenue this user wants donated to charity (0-100).')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->required(),
            ]);
    }
}
