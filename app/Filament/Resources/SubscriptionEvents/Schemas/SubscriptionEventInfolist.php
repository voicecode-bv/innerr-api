<?php

namespace App\Filament\Resources\SubscriptionEvents\Schemas;

use App\Models\SubscriptionEvent;
use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

class SubscriptionEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('id')->label('#'),
                            TextEntry::make('channel')->badge(),
                            TextEntry::make('type')->badge(),
                            TextEntry::make('external_event_id')->label('Notification UUID')->copyable(),
                            TextEntry::make('occurred_at')->dateTime(),
                            TextEntry::make('received_at')->dateTime(),
                            TextEntry::make('processed_at')->dateTime()->placeholder('— pending —'),
                            TextEntry::make('from_status')->badge()->placeholder('—'),
                            TextEntry::make('to_status')->badge()->placeholder('—'),
                        ]),
                        TextEntry::make('error')
                            ->color('danger')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Subscription')
                    ->visible(fn (SubscriptionEvent $record): bool => $record->subscription_id !== null)
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('subscription.id')->label('Sub #'),
                            TextEntry::make('subscription.status')->badge(),
                            TextEntry::make('subscription.plan.name')->label('Plan'),
                            TextEntry::make('subscription.channel_subscription_id')
                                ->label('Channel sub id')
                                ->copyable(),
                            TextEntry::make('subscription.current_period_start')->dateTime()->label('Period start'),
                            TextEntry::make('subscription.current_period_end')->dateTime()->label('Period end'),
                            TextEntry::make('subscription.user.email')->label('User'),
                            TextEntry::make('subscription.environment')->badge(),
                            TextEntry::make('subscription.auto_renew')
                                ->label('Auto renew')
                                ->formatStateUsing(fn ($state): string => $state ? 'yes' : 'no'),
                        ]),
                    ])
                    ->collapsible(),

                Section::make('Google notification details')
                    ->visible(fn (SubscriptionEvent $record): bool => $record->channel?->value === 'google')
                    ->schema([
                        TextEntry::make('google_subscription_notification')
                            ->label('subscriptionNotification')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson((array) ($record->payload['subscription_notification'] ?? [])))
                            ->placeholder('— not present —')
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('google_voided_purchase')
                            ->label('voidedPurchaseNotification')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson((array) ($record->payload['voided_purchase_notification'] ?? [])))
                            ->placeholder('— not present —')
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('google_test')
                            ->label('testNotification')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson((array) ($record->payload['test_notification'] ?? [])))
                            ->placeholder('— not present —')
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('google_purchase_token')
                            ->label('purchaseToken')
                            ->state(fn (SubscriptionEvent $record): string => (string) ($record->payload['channel_subscription_id'] ?? ''))
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('Decoded Apple payload')
                    ->visible(fn (SubscriptionEvent $record): bool => $record->channel?->value === 'apple'
                        && ! empty($record->payload['signedPayload'] ?? null))
                    ->schema([
                        TextEntry::make('apple_outer')
                            ->label('Outer notification')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson(self::decodeApple($record, 'signedPayload')))
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('apple_transaction')
                            ->label('Transaction info')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson(self::decodeAppleNested($record, 'signedTransactionInfo')))
                            ->placeholder('— not present (e.g. TEST notification) —')
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('apple_renewal')
                            ->label('Renewal info')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson(self::decodeAppleNested($record, 'signedRenewalInfo')))
                            ->placeholder('— not present —')
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Raw payload')
                    ->schema([
                        TextEntry::make('payload')
                            ->state(fn (SubscriptionEvent $record): string => self::formatJson($record->payload ?? []))
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeApple(SubscriptionEvent $record, string $key): array
    {
        $jws = (string) ($record->payload[$key] ?? '');

        if ($jws === '') {
            return [];
        }

        try {
            return app(AppleJwsVerifier::class)->decodeUnverified($jws);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeAppleNested(SubscriptionEvent $record, string $innerKey): array
    {
        $outer = self::decodeApple($record, 'signedPayload');
        $jws = (string) ($outer['data'][$innerKey] ?? '');

        if ($jws === '') {
            return [];
        }

        try {
            return app(AppleJwsVerifier::class)->decodeUnverified($jws);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function formatJson(array $data): string
    {
        if ($data === []) {
            return '';
        }

        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<pre style="white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 0.8rem; line-height: 1.4;">'
            .e($json)
            .'</pre>';
    }
}
