<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleRequest;
use App\Http\Requests\UpdateCircleRequest;
use App\Http\Resources\CircleResource;
use App\Models\Circle;
use App\Support\MediaUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Laravel\Facades\Image;
use OpenApi\Attributes as OA;

class CircleController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/circles',
        summary: 'List circles',
        description: 'Return all circles for the authenticated user.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of circles',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Circle')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $circles = $request->user()
            ->circles()
            ->withCount('members')
            ->latest()
            ->get();

        return CircleResource::collection($circles);
    }

    #[OA\Post(
        path: '/api/circles',
        summary: 'Create circle',
        description: 'Create a new circle for the authenticated user.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Close Friends'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Circle created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
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

    #[OA\Get(
        path: '/api/circles/{circle}',
        summary: 'Show circle',
        description: 'Return a single circle with its members.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Circle details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle not found'),
        ],
    )]
    public function show(Circle $circle): CircleResource
    {
        $this->authorize('view', $circle);

        $circle->load('members:id,name,username,avatar')
            ->loadCount('members')
            ->load(['invitations' => fn ($query) => $query->where('status', InvitationStatus::Pending)->with('user:id,username')]);

        return new CircleResource($circle);
    }

    #[OA\Put(
        path: '/api/circles/{circle}',
        summary: 'Update circle',
        description: 'Update an existing circle. Requires ownership.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Best Friends'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Circle updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCircleRequest $request, Circle $circle): CircleResource
    {
        $this->authorize('update', $circle);

        $circle->update([
            'name' => $request->validated('name'),
        ]);

        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}',
        summary: 'Delete circle',
        description: 'Delete a circle. Requires ownership.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Circle deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle not found'),
        ],
    )]
    public function destroy(Circle $circle): JsonResponse
    {
        $this->authorize('delete', $circle);

        if ($circle->photo) {
            MediaUrl::disk()->delete($circle->photo);
        }

        $circle->delete();

        return response()->json(null, 204);
    }

    public function updatePhoto(Request $request, Circle $circle): CircleResource
    {
        $this->authorize('update', $circle);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,heic,heif', 'max:10240'],
        ]);

        if ($circle->photo) {
            MediaUrl::disk()->delete($circle->photo);
        }

        $file = $request->file('photo');
        $file = $this->convertHeicToJpeg($file);

        $path = $file->store('circles', config('filesystems.media'));

        $circle->update(['photo' => $path]);
        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    public function deletePhoto(Circle $circle): CircleResource
    {
        $this->authorize('update', $circle);

        if ($circle->photo) {
            MediaUrl::disk()->delete($circle->photo);
            $circle->update(['photo' => null]);
        }

        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    private function convertHeicToJpeg(UploadedFile $file): UploadedFile
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['heic', 'heif'])) {
            return $file;
        }

        $jpegPath = tempnam(sys_get_temp_dir(), 'heic_').'.jpg';

        Image::decodePath($file->getPathname())
            ->save($jpegPath, quality: 90);

        return new UploadedFile(
            $jpegPath,
            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg',
            'image/jpeg',
            test: true,
        );
    }
}
