<?php

namespace App\Filament\Resources\SubscriptionEvents\Pages;

use App\Filament\Resources\SubscriptionEvents\SubscriptionEventResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionEvents extends ListRecords
{
    protected static string $resource = SubscriptionEventResource::class;
}
