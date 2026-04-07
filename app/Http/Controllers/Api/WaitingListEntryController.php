<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaitingListEntryRequest;
use App\Models\WaitingListEntry;
use Illuminate\Http\JsonResponse;

class WaitingListEntryController extends Controller
{
    public function store(StoreWaitingListEntryRequest $request): JsonResponse
    {
        WaitingListEntry::create($request->validated());

        return response()->json(['message' => 'Successfully joined the waiting list.'], 201);
    }
}
