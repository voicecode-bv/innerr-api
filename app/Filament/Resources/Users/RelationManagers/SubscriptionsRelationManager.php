<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('channel')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('current_period_end')->dateTime()->label('Period end'),
                TextColumn::make('renews_at')->dateTime(),
                TextColumn::make('canceled_at')->dateTime(),
                TextColumn::make('ended_at')->dateTime(),
            ])
            ->defaultSort('id', 'desc');
    }
}
