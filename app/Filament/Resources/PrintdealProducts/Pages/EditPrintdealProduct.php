<?php

namespace App\Filament\Resources\PrintdealProducts\Pages;

use App\Filament\Resources\PrintdealProducts\PrintdealProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrintdealProduct extends EditRecord
{
    protected static string $resource = PrintdealProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
