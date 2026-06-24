<?php

namespace App\Filament\Resources\Circles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CirclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->sortable(),
                IconColumn::make('members_can_invite')
                    ->label('Members can invite')
                    ->boolean(),
                IconColumn::make('members_can_view_members')
                    ->label('Members can see others')
                    ->boolean(),
                IconColumn::make('members_can_download')
                    ->label('Members can download')
                    ->boolean(),
                IconColumn::make('auto_add_new_users')
                    ->label('Auto-add new users')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label('ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('members_can_invite'),
                TernaryFilter::make('members_can_view_members')
                    ->label('Members can see others'),
                TernaryFilter::make('members_can_download')
                    ->label('Members can download'),
                TernaryFilter::make('auto_add_new_users')
                    ->label('Auto-add new users'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
