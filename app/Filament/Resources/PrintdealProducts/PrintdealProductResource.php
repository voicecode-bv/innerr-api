<?php

namespace App\Filament\Resources\PrintdealProducts;

use App\Filament\Resources\PrintdealProducts\Pages\EditPrintdealProduct;
use App\Filament\Resources\PrintdealProducts\Pages\ListPrintdealProducts;
use App\Filament\Resources\PrintdealProducts\Schemas\PrintdealProductForm;
use App\Filament\Resources\PrintdealProducts\Tables\PrintdealProductsTable;
use App\Models\PrintdealProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PrintdealProductResource extends Resource
{
    protected static ?string $model = PrintdealProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $recordTitleAttribute = 'sku';

    /** Records only enter through `printdeal:sync-products`. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PrintdealProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrintdealProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintdealProducts::route('/'),
            'edit' => EditPrintdealProduct::route('/{record}/edit'),
        ];
    }
}
