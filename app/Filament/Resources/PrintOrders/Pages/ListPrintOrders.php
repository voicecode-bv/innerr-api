<?php

namespace App\Filament\Resources\PrintOrders\Pages;

use App\Filament\Resources\PrintOrders\PrintOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPrintOrders extends ListRecords
{
    protected static string $resource = PrintOrderResource::class;
}
