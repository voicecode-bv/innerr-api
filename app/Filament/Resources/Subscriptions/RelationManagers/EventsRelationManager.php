<?php

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Enums\SubscriptionEventType;
use App\Filament\Resources\SubscriptionEvents\SubscriptionEventResource;
use App\Models\SubscriptionEvent;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
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
                TextColumn::make('id')->label('#'),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (?SubscriptionEventType $state): string => match ($state) {
                        SubscriptionEventType::Started => 'success',
                        SubscriptionEventType::Renewed => 'success',
                        SubscriptionEventType::Canceled => 'warning',
                        SubscriptionEventType::Refunded => 'danger',
                        SubscriptionEventType::EnteredGrace => 'warning',
                        SubscriptionEventType::Expired => 'gray',
                        default => 'primary',
                    }),
                TextColumn::make('from_status')->placeholder('—'),
                TextColumn::make('to_status')->placeholder('—'),
                TextColumn::make('external_event_id')
                    ->label('Notification UUID')
                    ->limit(24)
                    ->tooltip(fn (SubscriptionEvent $record): string => (string) $record->external_event_id),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
                IconColumn::make('processed_at')
                    ->label('Done')
                    ->boolean()
                    ->getStateUsing(fn (SubscriptionEvent $record): bool => $record->processed_at !== null),
                TextColumn::make('error')
                    ->limit(40)
                    ->color('danger')
                    ->placeholder('—')
                    ->tooltip(fn (SubscriptionEvent $record): ?string => $record->error),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                Action::make('view_full')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (SubscriptionEvent $record): string => SubscriptionEventResource::getUrl('view', ['record' => $record->id]))
                    ->openUrlInNewTab(),
            ]);
    }
}
