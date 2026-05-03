<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\MollieChannel;
use App\Services\Subscriptions\SubscriptionStateMachine;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
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
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge(),
                TextColumn::make('channel_subscription_id')
                    ->label('Channel sub id')
                    ->limit(20)
                    ->tooltip(fn (Subscription $record): ?string => $record->channel_subscription_id)
                    ->searchable()
                    ->copyable(),
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
                Action::make('cancel_test')
                    ->label('Test cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn (Subscription $record): bool => self::isTestable($record))
                    ->requiresConfirmation()
                    ->modalDescription('Cancelt het Mollie-abonnement bij Mollie en transitiet de lokale status naar canceled. Toegang loopt door tot period_end.')
                    ->action(function (Subscription $record, ChannelRegistry $registry, SubscriptionStateMachine $stateMachine): void {
                        try {
                            /** @var MollieChannel $channel */
                            $channel = $registry->for(SubscriptionChannel::Mollie);
                            $channel->cancel($record);
                            $stateMachine->apply($record, SubscriptionEventType::Canceled);

                            Notification::make()
                                ->title('Subscription canceled')
                                ->body("#{$record->id} → {$record->fresh()->status?->value}")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Cancel mislukt')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('refund_test')
                    ->label('Test refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (Subscription $record): bool => self::isTestable($record))
                    ->requiresConfirmation()
                    ->modalDescription('Refundt de meest recente succesvolle Mollie-transactie. Mollie POSTt vervolgens zelf naar de webhook waarop de status naar refunded gaat.')
                    ->action(function (Subscription $record, ChannelRegistry $registry): void {
                        $tx = SubscriptionTransaction::query()
                            ->where('subscription_id', $record->id)
                            ->where('channel', SubscriptionChannel::Mollie)
                            ->whereIn('kind', [SubscriptionTransaction::KIND_INITIAL, SubscriptionTransaction::KIND_RENEWAL])
                            ->where('amount_minor', '>', 0)
                            ->orderByDesc('occurred_at')
                            ->first();

                        if (! $tx) {
                            Notification::make()
                                ->title('Geen refundable transactie gevonden')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            /** @var MollieChannel $channel */
                            $channel = $registry->for(SubscriptionChannel::Mollie);
                            $channel->refundGrant($record, $tx->external_transaction_id);

                            Notification::make()
                                ->title('Refund gestart bij Mollie')
                                ->body("Transaction {$tx->external_transaction_id}. Wacht op webhook om status naar refunded te zetten.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Refund mislukt')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    private static function isTestable(Subscription $record): bool
    {
        return auth()->id() === 1
            && $record->channel === SubscriptionChannel::Mollie;
    }
}
