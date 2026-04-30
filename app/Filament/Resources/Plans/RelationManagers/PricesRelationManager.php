<?php

namespace App\Filament\Resources\Plans\RelationManagers;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('channel')
                    ->options(collect(SubscriptionChannel::cases())->mapWithKeys(fn (SubscriptionChannel $c): array => [$c->value => $c->value])->all())
                    ->required(),
                Select::make('interval')
                    ->options(collect(BillingInterval::cases())->mapWithKeys(fn (BillingInterval $i): array => [$i->value => $i->value])->all())
                    ->required(),
                TextInput::make('currency')
                    ->required()
                    ->default('EUR')
                    ->maxLength(3),
                TextInput::make('amount_minor')
                    ->numeric()
                    ->required()
                    ->helperText('Bedrag in minor units (cents).'),
                TextInput::make('channel_product_id')
                    ->maxLength(255)
                    ->helperText('Apple productId, Google SKU, of Mollie product slug.'),
                Toggle::make('is_active')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('channel_product_id')
            ->columns([
                TextColumn::make('channel'),
                TextColumn::make('interval'),
                TextColumn::make('currency'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => number_format(((int) $state) / 100, 2)),
                TextColumn::make('channel_product_id')
                    ->label('Product ID')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
