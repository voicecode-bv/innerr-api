<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class TopStorageUsers extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Top 10 storage users';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()
                ->where('storage_used_bytes', '>', 0)
                ->orderByDesc('storage_used_bytes')
                ->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('username')
                    ->searchable(),
                TextColumn::make('storage_used_bytes')
                    ->label('Storage used')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 2)),
            ]);
    }
}
