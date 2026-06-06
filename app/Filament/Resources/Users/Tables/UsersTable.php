<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\DeleteUser;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('username')
                    ->searchable(),
                // TextColumn::make('avatar')
                //     ->searchable(),
                TextColumn::make('locale')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('anonymized_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('google_id')
                    ->searchable(),
                TextColumn::make('apple_id')
                    ->searchable(),
                TextColumn::make('onboarded_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('posts_count')
                    ->label('Posts')
                    ->counts('posts')
                    ->sortable()
                    ->numeric(),
                TextColumn::make('storage_used_bytes')
                    ->label('Storage used')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 2))
                    ->tooltip(fn (int $state): string => Number::format($state).' bytes'),
                // TextColumn::make('avatar_thumbnail')
                //     ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(function (User $record): bool {
                        app(DeleteUser::class)($record);

                        return true;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (Collection $records): void {
                            $records->each(fn (User $record) => app(DeleteUser::class)($record));
                        }),
                ]),
            ]);
    }
}
