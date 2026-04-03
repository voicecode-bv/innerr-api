<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleRequest;
use App\Http\Requests\UpdateCircleRequest;
use App\Http\Resources\CircleResource;
use App\Models\Circle;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CircleController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $circles = $request->user()
            ->circles()
            ->withCount('members')
            ->latest()
            ->get();

        return CircleResource::collection($circles);
    }

    public function store(StoreCircleRequest $request): JsonResponse
    {
        $circle = $request->user()->circles()->create([
            'name' => $request->validated('name'),
        ]);

        $circle->loadCount('members');

        return (new CircleResource($circle))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Circle $circle): CircleResource
    {
        $this->authorize('view', $circle);

        $circle->load('members:id,name,username,avatar')
            ->loadCount('members');

        return new CircleResource($circle);
    }

    public function update(UpdateCircleRequest $request, Circle $circle): CircleResource
    {
        $this->authorize('update', $circle);

        $circle->update([
            'name' => $request->validated('name'),
        ]);

        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    public function destroy(Circle $circle): JsonResponse
    {
        $this->authorize('delete', $circle);

        $circle->delete();

        return response()->json(null, 204);
    }
}
