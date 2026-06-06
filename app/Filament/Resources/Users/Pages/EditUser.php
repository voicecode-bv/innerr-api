<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\DeleteUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (User $record): bool {
                    app(DeleteUser::class)($record);

                    return true;
                }),
        ];
    }
}
