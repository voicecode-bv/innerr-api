<?php

namespace App\Filament\Resources\PrintdealProducts\Schemas;

use App\Models\PrintdealProduct;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class PrintdealProductForm
{
    /**
     * Attribute names from the synced schema, for the datalist suggestions.
     * The quantity attribute is system-managed (appended to every price and
     * order call), so it never belongs in the admin's attribute lists.
     *
     * @return array<int, string>
     */
    protected static function schemaAttributeNames(?PrintdealProduct $record): array
    {
        return collect($record?->attribute_schema ?? [])
            ->pluck('attribute')
            ->reject(fn (string $attribute): bool => strtolower($attribute) === 'quantity')
            ->values()
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

    /**
     * Select options keyed by themselves: the stored value IS the label.
     *
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    protected static function asOptions(array $values): array
    {
        return array_combine($values, $values);
    }

    /**
     * The attribute names the customer chooses from (user options), used to
     * point the artwork sizing at the size and frame options.
     *
     * @return array<int, string>
     */
    protected static function userOptionAttributeNames(?PrintdealProduct $record): array
    {
        return collect($record?->user_options ?? [])
            ->pluck('attribute')
            ->filter()
            ->values()
            ->all();
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
                            ->label('Purchase price (base, 1 piece)')
                            ->content(fn (PrintdealProduct $record): string => $record->purchase_price_minor !== null
                                ? Number::currency($record->purchase_price_minor / 100, 'EUR', 'nl')
                                    .(empty($record->user_options) ? '' : ' (first value of every user option; other combinations are quoted live)')
                                : 'Not priced yet; fetched on save once the order attributes are configured.'),
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
                    ->description('What the app sells. A product is orderable once it is enabled, mapped to an app product, has its attributes configured (fixed below, as user options, or a mix), and has a price (fixed, or a margin).')
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
                                'puzzle' => 'Photo puzzle',
                                'canvas' => 'Photo canvas',
                            ])
                            ->native(false)
                            ->helperText('Which product family this belongs to in the app; it decides the artwork layout and photo limits. Multiple enabled products per family all show up in the shop.'),
                        TextInput::make('name.nl-NL')
                            ->label('Name (Dutch)')
                            ->placeholder(fn (PrintdealProduct $record): string => $record->name['en-EN'] ?? '')
                            ->helperText('Shown in the app for Dutch users; empty falls back to the English Printdeal name.'),
                        TextInput::make('name.fr-FR')
                            ->label('Name (French)')
                            ->placeholder(fn (PrintdealProduct $record): string => $record->name['en-EN'] ?? '')
                            ->helperText('Shown in the app for French users; empty falls back to the English Printdeal name.'),
                        Placeholder::make('schema_status')
                            ->label('Attribute schema')
                            ->content(fn (PrintdealProduct $record): string => empty($record->attribute_schema)
                                ? 'Not available: the automatic fetch when opening this page failed. Reload the page or run "Sync catalog" to get name/value suggestions in the fields below.'
                                : count($record->attribute_schema).' attributes known; the fields below suggest their names and allowed values.')
                            ->columnSpanFull(),
                        Repeater::make('order_attributes')
                            ->label('Order attributes')
                            ->schema([
                                // Real dropdowns, not datalist suggestions:
                                // Safari barely surfaces datalists, which made
                                // these fields feel uneditable. Inside a
                                // repeater $record is the (absent) item
                                // record, so the options resolve the page's
                                // product via $livewire instead.
                                Select::make('attribute')
                                    ->required()
                                    ->live()
                                    ->distinct()
                                    ->options(fn (EditRecord $livewire): array => self::asOptions(self::schemaAttributeNames($livewire->getRecord()))),
                                Select::make('value')
                                    ->required()
                                    ->options(fn (Get $get, EditRecord $livewire): array => self::asOptions(self::schemaValuesFor($livewire->getRecord(), $get('attribute')))),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('The fixed part of every order: pick one value per attribute. Anything the customer should choose (size, puzzle format, ...) moves to User options below; an attribute that appears in both is removed here automatically on save.'),
                        Repeater::make('user_options')
                            ->label('User options')
                            ->schema([
                                Select::make('attribute')
                                    ->required()
                                    ->live()
                                    ->distinct()
                                    ->options(fn (EditRecord $livewire): array => self::asOptions(self::schemaAttributeNames($livewire->getRecord()))),
                                TagsInput::make('values')
                                    ->required()
                                    ->placeholder('S, M, L, ...')
                                    ->suggestions(fn (Get $get, EditRecord $livewire): array => self::schemaValuesFor($livewire->getRecord(), $get('attribute'))),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->helperText('Attributes the customer picks in the app (size, puzzle format, packing, ...) with the values they can choose from. The price follows the choice automatically: the app quotes each combination live at Printdeal and applies the margin.'),
                    ])
                    ->columns(2),

                Section::make('Artwork dimensions')
                    ->description('The PDF page size in millimetres for products whose size depends on the customer\'s choice (puzzle, canvas). Leave empty to use the built-in size from config/print.php.')
                    ->schema([
                        Select::make('artwork.size_attribute')
                            ->label('Size option')
                            ->options(fn (EditRecord $livewire): array => self::asOptions(self::userOptionAttributeNames($livewire->getRecord())))
                            ->native(false)
                            ->helperText('Which user option carries the size. Leave empty for a single fixed size (then add one row below without a value).')
                            ->columnSpanFull(),
                        Repeater::make('artwork.sizes')
                            ->label('Sizes (mm)')
                            ->schema([
                                TextInput::make('value')
                                    ->label('When the size option is')
                                    ->placeholder('90 x 60 cm')
                                    ->helperText('Must match the option value exactly. Leave empty for a single fixed size.'),
                                TextInput::make('width')
                                    ->label('Width (mm)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1),
                                TextInput::make('height')
                                    ->label('Height (mm)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1),
                            ])
                            ->columns(3)
                            ->columnSpanFull()
                            ->helperText('Type the trim (base) size per option value in mm — e.g. 90 x 60 cm becomes 900 x 600. The frame (below) and a 3 mm print bleed per edge are added automatically.'),
                        Select::make('artwork.popular')
                            ->label('Most chosen size')
                            ->options(fn (Get $get): array => collect($get('artwork.sizes') ?? [])
                                ->pluck('value', 'value')
                                ->filter()
                                ->all())
                            ->native(false)
                            ->helperText('Highlighted with a "Most chosen" badge in the app. Leave empty for none.')
                            ->columnSpanFull(),
                        Select::make('artwork.frame_attribute')
                            ->label('Frame option (canvas)')
                            ->options(fn (EditRecord $livewire): array => self::asOptions(self::userOptionAttributeNames($livewire->getRecord())))
                            ->native(false)
                            ->helperText('Optional. The chosen frame adds twice its depth to every edge.')
                            ->columnSpanFull(),
                        Repeater::make('artwork.frames')
                            ->label('Frame depths (mm)')
                            ->schema([
                                TextInput::make('value')
                                    ->label('When the frame is')
                                    ->placeholder('2 cm'),
                                TextInput::make('depth')
                                    ->label('Depth (mm)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->helperText('2 cm is 20 mm and adds 40 mm to width and height; 4,5 cm is 45 mm and adds 90 mm.'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Pricing')
                    ->schema([
                        TextInput::make('fixed_price_minor')
                            ->label('Fixed selling price (cents)')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('2495')
                            ->helperText('What the user pays, in cents incl. VAT (2495 = EUR 24,95). Takes precedence over the margin and applies to every option combination, so cost differences between options are absorbed by us.'),
                        TextInput::make('margin_percent')
                            ->label('Margin %')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('%')
                            ->helperText('Applied to the live-quoted net (ex-VAT) purchase price, then VAT is added on top to reach the consumer price. Used when no fixed price is set.'),
                        Placeholder::make('selling_price')
                            ->label('Base selling price (incl. VAT)')
                            ->content(fn (PrintdealProduct $record): string => $record->sellingPriceMinor() !== null
                                ? Number::currency($record->sellingPriceMinor() / 100, 'EUR', 'nl')
                                    .(empty($record->user_options) ? '' : ' (shown as "from" price; the exact price follows the chosen options)')
                                : 'None yet, product cannot be ordered.'),
                    ])
                    ->columns(2),
            ]);
    }
}
