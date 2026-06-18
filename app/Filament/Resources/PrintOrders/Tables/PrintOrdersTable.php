<?php

namespace App\Filament\Resources\PrintOrders\Tables;

use App\Enums\PrintOrderStatus;
use App\Filament\Resources\PrintOrders\PrintOrderResource;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PrintOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('#')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PrintOrderStatus $state): string => str($state->value)->headline())
                    ->color(fn (PrintOrderStatus $state): string => match ($state) {
                        PrintOrderStatus::Paid, PrintOrderStatus::Submitted => 'success',
                        PrintOrderStatus::PendingPayment => 'warning',
                        PrintOrderStatus::Failed => 'danger',
                        PrintOrderStatus::Canceled => 'gray',
                    }),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->money('EUR', divideBy: 100)
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items'),
                TextColumn::make('printdeal_order_number')
                    ->label('Printdeal #')
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('printdeal_status')
                    ->label('Printdeal status')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PrintOrderStatus::cases())
                        ->mapWithKeys(fn (PrintOrderStatus $s): array => [$s->value => str($s->value)->headline()->toString()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                PrintOrderResource::refreshFromPrintdealAction(),
                PrintOrderResource::resubmitAction(),
            ]);
    }
}
