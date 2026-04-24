<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Support\MediaUrl;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class PhotoMapController extends Controller
{
    private const MAX_RESULTS = 5000;

    #[OA\Get(
        path: '/api/photos/map',
        summary: 'Photos for map',
        description: 'Return photos with coordinates as a GeoJSON FeatureCollection, suitable for a Mapbox GL clustered source. Restricted to the authenticated user\'s own posts and posts in circles they own or are a member of. A bounding box is required and the result is capped at '.self::MAX_RESULTS.' features.',
        tags: ['Photos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'bbox',
                in: 'query',
                required: true,
                description: 'Bounding box as "west,south,east,north" in WGS84 decimal degrees.',
                schema: new OA\Schema(type: 'string', example: '4.7,52.3,5.0,52.5'),
            ),
            new OA\Parameter(
                name: 'media_type',
                in: 'query',
                required: false,
                description: 'Filter by media type. Defaults to "image".',
                schema: new OA\Schema(type: 'string', enum: ['image', 'video', 'all'], default: 'image'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'GeoJSON FeatureCollection of photos with coordinates.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'FeatureCollection'),
                        new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the result hit the server cap and additional photos exist in the bounding box.'),
                        new OA\Property(
                            property: 'features',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'type', type: 'string', example: 'Feature'),
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(
                                        property: 'geometry',
                                        properties: [
                                            new OA\Property(property: 'type', type: 'string', example: 'Point'),
                                            new OA\Property(property: 'coordinates', type: 'array', items: new OA\Items(type: 'number'), description: '[longitude, latitude]'),
                                        ],
                                    ),
                                    new OA\Property(
                                        property: 'properties',
                                        properties: [
                                            new OA\Property(property: 'post_id', type: 'integer'),
                                            new OA\Property(property: 'media_type', type: 'string', enum: ['image', 'video']),
                                            new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
                                            new OA\Property(property: 'taken_at', type: 'string', format: 'date-time', nullable: true),
                                        ],
                                    ),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $authUser = $request->user();

        return $this->buildResponse($request, function (Builder $query) use ($authUser): void {
            $query->where(function (Builder $q) use ($authUser): void {
                $q->where('posts.user_id', $authUser->id)
                    ->orWhereHas('circles', function (Builder $circle) use ($authUser): void {
                        $circle->where('circles.user_id', $authUser->id)
                            ->orWhereHas('members', fn (Builder $m) => $m->whereKey($authUser->id));
                    });
            });
        });
    }

    #[OA\Get(
        path: '/api/profiles/{username}/photos/map',
        summary: 'Photos for map (profile)',
        description: 'Return a user\'s photos with coordinates as a GeoJSON FeatureCollection. When viewing another user\'s profile, results are limited to posts that appear in at least one circle the authenticated user owns or is a member of, so photos in circles the authenticated user does not belong to are never exposed. When viewing the authenticated user\'s own profile, all of their posts are returned. A bounding box is required and the result is capped at '.self::MAX_RESULTS.' features.',
        tags: ['Photos'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'bbox',
                in: 'query',
                required: true,
                description: 'Bounding box as "west,south,east,north" in WGS84 decimal degrees.',
                schema: new OA\Schema(type: 'string', example: '4.7,52.3,5.0,52.5'),
            ),
            new OA\Parameter(
                name: 'media_type',
                in: 'query',
                required: false,
                description: 'Filter by media type. Defaults to "image".',
                schema: new OA\Schema(type: 'string', enum: ['image', 'video', 'all'], default: 'image'),
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'GeoJSON FeatureCollection of photos with coordinates.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'User not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function profile(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();

        return $this->buildResponse($request, function (Builder $query) use ($authUser, $user): void {
            $query->where('posts.user_id', $user->id);

            if ($authUser->id !== $user->id) {
                $query->whereHas('circles', function (Builder $circle) use ($authUser): void {
                    $circle->where('circles.user_id', $authUser->id)
                        ->orWhereHas('members', fn (Builder $m) => $m->whereKey($authUser->id));
                });
            }
        });
    }

    private function buildResponse(Request $request, Closure $applyVisibilityScope): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'media_type' => ['sometimes', Rule::in(['image', 'video', 'all'])],
        ]);

        [$west, $south, $east, $north] = array_map('floatval', explode(',', $validated['bbox']));

        abort_if($west >= $east || $south >= $north, 422, 'Invalid bounding box: west must be less than east and south must be less than north.');
        abort_if($south < -90 || $north > 90 || $west < -180 || $east > 180, 422, 'Bounding box coordinates out of range.');

        $mediaType = $validated['media_type'] ?? 'image';

        $query = Post::query()
            ->select(['id', 'user_id', 'media_type', 'thumbnail_small_url', 'thumbnail_url', 'coordinates', 'taken_at'])
            ->whereNotNull('coordinates')
            ->when(
                $east - $west < 180 && $north - $south < 180,
                fn (Builder $q) => $q->whereRaw(
                    'coordinates && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography',
                    [$west, $south, $east, $north],
                ),
            )
            ->when($mediaType !== 'all', fn (Builder $q) => $q->where('media_type', $mediaType));

        $applyVisibilityScope($query);

        $posts = $query->limit(self::MAX_RESULTS + 1)->get();

        $truncated = $posts->count() > self::MAX_RESULTS;
        $posts = $posts->take(self::MAX_RESULTS);

        $features = $posts->map(fn (Post $post) => [
            'type' => 'Feature',
            'id' => $post->id,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$post->longitude, $post->latitude],
            ],
            'properties' => [
                'post_id' => $post->id,
                'media_type' => $post->media_type,
                'thumbnail_url' => MediaUrl::sign($post->thumbnail_small_url ?? $post->thumbnail_url),
                'taken_at' => $post->taken_at,
            ],
        ])->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'truncated' => $truncated,
            'features' => $features,
        ]);
    }
}
