<?php

namespace App\Filament\Resources\AppReleases\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AppReleaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('platform')
                    ->options(['ios' => 'iOS', 'android' => 'Android'])
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('One row per store platform.'),
                TextInput::make('latest_version')
                    ->label('Latest version')
                    ->placeholder('1.4.0')
                    ->maxLength(32)
                    ->helperText('Newest version live in the store. Drives the dismissible "update available" card. Only bump this once the store release is actually live.'),
                TextInput::make('minimum_version')
                    ->label('Minimum version')
                    ->placeholder('1.2.0')
                    ->maxLength(32)
                    ->helperText('Oldest version still supported. Anything below gets a blocking "update required" screen, so raise this carefully.'),
                TextInput::make('store_url')
                    ->label('Store URL')
                    ->url()
                    ->maxLength(255)
                    ->helperText('Public store listing the update buttons open.'),
            ]);
    }
}
