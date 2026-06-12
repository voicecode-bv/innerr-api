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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class PrintdealProductForm
{
    /**
     * Attribute names from the synced schema, for the datalist suggestions.
     *
     * @return array<int, string>
     */
    protected static function schemaAttributeNames(?PrintdealProduct $record): array
    {
        return collect($record?->attribute_schema ?? [])
            ->pluck('attribute')
            ->all();
    }

    /**
     * Allowed values for one attribute from the synced schema.
     *
     * @return array<int, string>
     */
    protected static function schemaValuesFor(?PrintdealProduct $record, ?string $attribute): array
    {
        $entry = collect($record?->attribute_schema ?? [])
            ->firstWhere('attribute', $attribute);

        return array_map(strval(...), $entry['values'] ?? []);
    }

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
                            ->helperText('Which product family this belongs to in the app; it decides the artwork layout and photo limits. Multiple enabled products per family all show up in the shop.'),
                        Placeholder::make('schema_status')
                            ->label('Attribute schema')
                            ->content(fn (PrintdealProduct $record): string => empty($record->attribute_schema)
                                ? 'Not synced yet. Map or enable this product, save, and run "Sync catalog"; the fields below then suggest the valid attribute names and values.'
                                : count($record->attribute_schema).' attributes known; the fields below suggest their names and allowed values.')
                            ->columnSpanFull(),
                        Repeater::make('order_attributes')
                            ->label('Order attributes')
                            ->schema([
                                TextInput::make('attribute')
                                    ->required()
                                    ->datalist(fn (?PrintdealProduct $record): array => self::schemaAttributeNames($record)),
                                TextInput::make('value')
                                    ->required()
                                    ->datalist(fn (Get $get, ?PrintdealProduct $record): array => self::schemaValuesFor($record, $get('attribute'))),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Exact attribute/value pairs Printdeal expects for this product. Pick one value per attribute; leave size-like attributes out (they go in Sizes below). Sent with every order and used to fetch the purchase price.'),
                        Repeater::make('user_options')
                            ->label('User options')
                            ->schema([
                                TextInput::make('attribute')
                                    ->required()
                                    ->datalist(fn (?PrintdealProduct $record): array => self::schemaAttributeNames($record)),
                                TagsInput::make('values')
                                    ->required()
                                    ->placeholder('S, M, L, ...')
                                    ->suggestions(fn (Get $get, ?PrintdealProduct $record): array => self::schemaValuesFor($record, $get('attribute'))),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Attributes the user picks in the app (Size, Color, ...) with the values they can choose from. Leave these out of the order attributes above; the chosen values are sent as a variant. Only for grouped products such as clothing.'),
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
