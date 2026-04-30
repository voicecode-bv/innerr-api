<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('user.username')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('current_period_end')
                    ->label('Period end')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('renews_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->options(collect(SubscriptionChannel::cases())->mapWithKeys(fn (SubscriptionChannel $c): array => [$c->value => $c->value])->all()),
                SelectFilter::make('status')
                    ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $s): array => [$s->value => $s->value])->all()),
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->options(fn () => Plan::query()->pluck('name', 'id')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
