<?php

namespace App\Filament\Resources\PrintOrders\Pages;

use App\Filament\Resources\PrintOrders\PrintOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPrintOrder extends ViewRecord
{
    protected static string $resource = PrintOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PrintOrderResource::refreshFromPrintdealAction(),
            PrintOrderResource::resubmitAction(),
        ];
    }
}
