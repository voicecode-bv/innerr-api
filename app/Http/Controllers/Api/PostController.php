<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    use AuthorizesRequests;

    public function show(Post $post): PostResource
    {
        $post->load([
            'user:id,name,username,avatar',
            'comments' => fn ($query) => $query->oldest(),
            'comments.user:id,name,username,avatar',
            'likes',
        ])->loadCount(['likes', 'comments']);

        return new PostResource($post);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $file = $request->file('media');
        $path = $file->store('posts', 'public');

        $mimeType = $file->getMimeType();
        $mediaType = str_starts_with($mimeType, 'video/') ? 'video' : 'image';

        $post = $request->user()->posts()->create([
            'media_url' => Storage::disk('public')->url($path),
            'media_type' => $mediaType,
            'caption' => $request->validated('caption'),
            'location' => $request->validated('location'),
        ]);

        $post->load('user:id,name,username,avatar')
            ->loadCount(['likes', 'comments']);

        return (new PostResource($post))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        $mediaPath = str_replace(Storage::disk('public')->url(''), '', $post->media_url);
        Storage::disk('public')->delete($mediaPath);

        $post->delete();

        return response()->json(null, 204);
    }
}
