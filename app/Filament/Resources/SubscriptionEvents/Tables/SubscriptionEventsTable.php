<?php

namespace App\Filament\Resources\SubscriptionEvents\Tables;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\SubscriptionEvent;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'subscription:id,user_id,plan_id,channel,channel_subscription_id,status',
                'subscription.user:id,name,email',
                'subscription.plan:id,name,slug',
                'user:id,name,email',
            ]))
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->label('#'),
                TextColumn::make('channel')
                    ->badge(),
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
                TextColumn::make('subscription.user.email')
                    ->label('User')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->whereHas('subscription.user', fn (Builder $q) => $q->where('email', 'ilike', "%{$search}%"))
                            ->orWhereHas('user', fn (Builder $q) => $q->where('email', 'ilike', "%{$search}%"));
                    })
                    ->placeholder('—')
                    ->url(fn (SubscriptionEvent $record): ?string => $record->subscription?->user_id
                        ? UserResource::getUrl('edit', ['record' => $record->subscription->user_id])
                        : null),
                TextColumn::make('subscription.plan.name')
                    ->label('Plan')
                    ->placeholder('—'),
                TextColumn::make('subscription_id')
                    ->label('Sub')
                    ->placeholder('— (unlinked)')
                    ->url(fn (SubscriptionEvent $record): ?string => $record->subscription_id
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription_id])
                        : null),
                TextColumn::make('subscription.channel_subscription_id')
                    ->label('Channel sub id')
                    ->limit(20)
                    ->tooltip(fn (SubscriptionEvent $record): ?string => $record->subscription?->channel_subscription_id)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_event_id')
                    ->label('Event id')
                    ->limit(20)
                    ->tooltip(fn (SubscriptionEvent $record): string => (string) $record->external_event_id)
                    ->searchable(),
                TextColumn::make('from_status')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('to_status')
                    ->badge()
                    ->placeholder('—'),
                IconColumn::make('processed_at')
                    ->label('Done')
                    ->boolean()
                    ->getStateUsing(fn (SubscriptionEvent $record): bool => $record->processed_at !== null),
                TextColumn::make('error')
                    ->limit(40)
                    ->tooltip(fn (SubscriptionEvent $record): ?string => $record->error)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->options(collect(SubscriptionChannel::cases())->mapWithKeys(fn (SubscriptionChannel $c): array => [$c->value => $c->value])->all()),
                SelectFilter::make('type')
                    ->options(collect(SubscriptionEventType::cases())->mapWithKeys(fn (SubscriptionEventType $t): array => [$t->value => $t->value])->all()),
                TernaryFilter::make('processed')
                    ->label('Processed')
                    ->placeholder('All')
                    ->trueLabel('Processed')
                    ->falseLabel('Pending')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('processed_at'),
                        false: fn (Builder $q) => $q->whereNull('processed_at'),
                    ),
                Filter::make('has_error')
                    ->label('Has error')
                    ->query(fn (Builder $q) => $q->whereNotNull('error')),
                Filter::make('unlinked')
                    ->label('Unlinked (no subscription)')
                    ->query(fn (Builder $q) => $q->whereNull('subscription_id')),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
