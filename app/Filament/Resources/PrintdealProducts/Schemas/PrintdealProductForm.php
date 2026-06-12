<?php

namespace App\Filament\Resources\PrintdealProducts\Schemas;

use App\Models\PrintdealProduct;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class PrintdealProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Catalog (synced)')
                    ->description('Mirrored from the Printdeal API by printdeal:sync-products; not editable here.')
                    ->schema([
                        Placeholder::make('catalog_name')
                            ->label('Product')
                            ->content(fn (PrintdealProduct $record): string => $record->displayName()),
                        Placeholder::make('sku')
                            ->label('SKU')
                            ->content(fn (PrintdealProduct $record): string => $record->sku),
                        Placeholder::make('purchase_price')
                            ->label('Purchase price (gross, 1 piece)')
                            ->content(fn (PrintdealProduct $record): string => $record->purchase_price_minor !== null
                                ? Number::currency($record->purchase_price_minor / 100, 'EUR', 'nl')
                                : 'Not priced yet; runs during the next sync once the product is offered with order attributes.'),
                        Placeholder::make('synced_at')
                            ->label('Last synced')
                            ->content(fn (PrintdealProduct $record): string => $record->synced_at?->diffForHumans() ?? 'Never'),
                        Placeholder::make('delisted_at')
                            ->label('Delisted')
                            ->content(fn (PrintdealProduct $record): string => $record->delisted_at !== null
                                ? "Gone from the Printdeal catalog since {$record->delisted_at->toDateString()}; cannot be ordered."
                                : 'No, available in the Printdeal catalog.'),
                    ])
                    ->columns(2),

                Section::make('Offering')
                    ->description('What the app sells. A product is orderable once it is enabled, mapped to an app product, has order attributes, and has a price (fixed, or margin on the synced purchase price).')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Offer in the app')
                            ->helperText('Off = hidden as "coming soon" in the app.'),
                        Select::make('app_product')
                            ->label('App product')
                            ->options([
                                'calendar' => 'Photo calendar',
                                'album' => 'Photo album',
                                'mug' => 'Mug',
                                'tshirt' => 'T-shirt',
                            ])
                            ->native(false)
                            ->helperText('Which shop tile this product backs. With multiple enabled products per tile, the most recently updated wins.'),
                        Repeater::make('order_attributes')
                            ->label('Order attributes')
                            ->schema([
                                TextInput::make('attribute')->required(),
                                TextInput::make('value')->required(),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Exact attribute/value pairs Printdeal expects for this product (see GET /v3/products/{sku}). Sent with every order and used to fetch the purchase price.'),
                        TagsInput::make('sizes')
                            ->label('Sizes')
                            ->placeholder('S, M, L, ...')
                            ->helperText('Only for grouped products such as t-shirts; the chosen size is sent as a variant.'),
                    ])
                    ->columns(2),

                Section::make('Pricing')
                    ->schema([
                        TextInput::make('fixed_price_minor')
                            ->label('Fixed selling price (cents)')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('2495')
                            ->helperText('What the user pays, in cents (2495 = EUR 24,95). Takes precedence over the margin.'),
                        TextInput::make('margin_percent')
                            ->label('Margin %')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('%')
                            ->helperText('Applied to the synced purchase price when no fixed price is set.'),
                        Placeholder::make('selling_price')
                            ->label('Current selling price')
                            ->content(fn (PrintdealProduct $record): string => $record->sellingPriceMinor() !== null
                                ? Number::currency($record->sellingPriceMinor() / 100, 'EUR', 'nl')
                                : 'None yet, product cannot be ordered.'),
                    ])
                    ->columns(2),
            ]);
    }
}
