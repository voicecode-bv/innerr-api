<?php

namespace App\Filament\Resources\PrintOrders\Schemas;

use App\Enums\PrintOrderStatus;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrintOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('number')->label('#'),
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(fn (PrintOrderStatus $state): string => str($state->value)->headline())
                                ->color(fn (PrintOrderStatus $state): string => match ($state) {
                                    PrintOrderStatus::Paid, PrintOrderStatus::Submitted => 'success',
                                    PrintOrderStatus::PendingPayment => 'warning',
                                    PrintOrderStatus::Failed => 'danger',
                                    PrintOrderStatus::Canceled => 'gray',
                                }),
                            TextEntry::make('amount_minor')
                                ->label('Amount')
                                ->money('EUR', divideBy: 100),
                            TextEntry::make('user.email')->label('User')->placeholder('—'),
                            TextEntry::make('created_at')->dateTime(),
                            TextEntry::make('currency'),
                        ]),
                    ]),

                Section::make('Shipping address')
                    ->schema([
                        TextEntry::make('shipping_address')
                            ->hiddenLabel()
                            ->state(fn (PrintOrder $record): string => self::formatAddress($record->shipping_address ?? []))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Printdeal & payment')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('printdeal_order_id')->label('Printdeal id')->placeholder('—')->copyable(),
                            TextEntry::make('printdeal_order_number')->label('Printdeal #')->placeholder('—')->copyable(),
                            TextEntry::make('printdeal_status')->label('Printdeal status')->badge()->placeholder('—'),
                            TextEntry::make('mollie_payment_id')->label('Mollie payment')->placeholder('—')->copyable(),
                        ]),
                    ]),

                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('app_product')->badge(),
                                    TextEntry::make('amount_minor')
                                        ->label('Amount')
                                        ->money('EUR', divideBy: 100),
                                    TextEntry::make('printdeal_status')->label('Printdeal status')->badge()->placeholder('—'),
                                    TextEntry::make('options')
                                        ->state(fn (PrintOrderItem $record): string => self::formatOptions($record->options ?? []))
                                        ->placeholder('—')
                                        ->columnSpan(2),
                                    TextEntry::make('artwork_size')
                                        ->label('Artwork')
                                        ->state(fn (PrintOrderItem $record): string => $record->artwork_width_mm !== null
                                            ? "{$record->artwork_width_mm} × {$record->artwork_height_mm} mm"
                                            : '—'),
                                    TextEntry::make('photo_count')
                                        ->label('Photos')
                                        ->state(fn (PrintOrderItem $record): int => count($record->photos ?? [])),
                                    TextEntry::make('printdeal_sku')->label('SKU')->placeholder('—')->copyable()->columnSpan(2),
                                    TextEntry::make('pdf_path')->label('PDF path')->placeholder('— not rendered —')->copyable()->columnSpanFull(),
                                ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private static function formatAddress(array $address): string
    {
        if ($address === []) {
            return '';
        }

        $name = trim(($address['firstName'] ?? '').' '.($address['lastName'] ?? ''));
        $street = trim(($address['street'] ?? '').' '.($address['houseNumber'] ?? '').($address['houseNumberAddition'] ?? ''));
        $city = trim(($address['postalCode'] ?? '').' '.($address['city'] ?? ''));

        return collect([$name, $street, $city, $address['country'] ?? null, $address['email'] ?? null])
            ->filter(fn (?string $line): bool => $line !== null && $line !== '')
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function formatOptions(array $options): string
    {
        return collect($options)
            ->map(fn (mixed $value, string $key): string => "{$key}: {$value}")
            ->implode("\n");
    }
}
