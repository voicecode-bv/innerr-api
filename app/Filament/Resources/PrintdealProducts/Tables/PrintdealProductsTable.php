<?php

namespace App\Filament\Resources\PrintdealProducts\Tables;

use App\Models\PrintdealProduct;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PrintdealProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->state(fn (PrintdealProduct $record): string => $record->displayName())
                    ->searchable(query: fn ($query, string $search) => $query->where('name', 'like', "%{$search}%"))
                    ->description(fn (PrintdealProduct $record): string => $record->sku),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('app_product')
                    ->label('App product')
                    ->badge()
                    ->placeholder('Not mapped'),
                TextColumn::make('purchase_price_minor')
                    ->label('Purchase')
                    ->money('EUR', divideBy: 100)
                    ->placeholder('-'),
                TextColumn::make('selling_price')
                    ->label('Selling')
                    ->state(fn (PrintdealProduct $record): ?float => $record->sellingPriceMinor() !== null
                        ? $record->sellingPriceMinor() / 100
                        : null)
                    ->money('EUR')
                    ->placeholder('-'),
                TextColumn::make('margin_percent')
                    ->label('Margin')
                    ->suffix('%')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('synced_at')
                    ->label('Synced')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('delisted_at')
                    ->label('Delisted')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->state(fn (PrintdealProduct $record): bool => $record->delisted_at !== null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('enabled', 'desc')
            ->filters([
                TernaryFilter::make('enabled'),
                SelectFilter::make('app_product')
                    ->options([
                        'calendar' => 'Photo calendar',
                        'album' => 'Photo album',
                        'mug' => 'Mug',
                        'tshirt' => 'T-shirt',
                    ]),
                TernaryFilter::make('delisted')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('delisted_at'),
                        false: fn ($query) => $query->whereNull('delisted_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
