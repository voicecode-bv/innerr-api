<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleMemberRequest;
use App\Models\Circle;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class CircleMemberController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreCircleMemberRequest $request, Circle $circle): JsonResponse
    {
        $this->authorize('update', $circle);

        $user = User::where('username', $request->validated('username'))->first();

        $circle->members()->syncWithoutDetaching([$user->id]);

        return response()->json(['message' => 'Member added.'], 201);
    }

    public function destroy(Circle $circle, User $user): JsonResponse
    {
        $this->authorize('update', $circle);

        $circle->members()->detach($user->id);

        return response()->json(null, 204);
    }
}
