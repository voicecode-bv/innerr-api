<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('from_status'),
                TextColumn::make('to_status'),
                TextColumn::make('external_event_id')->limit(40),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                TextColumn::make('processed_at')->dateTime()->sortable(),
                TextColumn::make('error')->limit(60)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc');
    }
}
