<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Enums\Entitlement;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->maxLength(64),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('tier')
                    ->numeric()
                    ->required()
                    ->default(0),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
                Toggle::make('is_default')
                    ->helperText('Exactly one plan should be the default (gratis tier).'),
                Toggle::make('is_active')
                    ->default(true),
                KeyValue::make('features')
                    ->keyLabel('Feature')
                    ->valueLabel('Limit')
                    ->columnSpanFull(),
                Select::make('entitlements')
                    ->multiple()
                    ->options(collect(Entitlement::values())->mapWithKeys(fn (string $v): array => [$v => $v])->all())
                    ->columnSpanFull(),
            ]);
    }
}
