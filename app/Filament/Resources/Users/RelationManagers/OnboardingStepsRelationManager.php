<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OnboardingStepsRelationManager extends RelationManager
{
    protected static string $relationship = 'onboardingSteps';

    protected static ?string $title = 'Onboarding';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('step')
            ->columns([
                TextColumn::make('step')
                    ->label('Step')
                    ->badge()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed at')
                    ->dateTime()
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->defaultSort('completed_at', 'asc');
    }
}
