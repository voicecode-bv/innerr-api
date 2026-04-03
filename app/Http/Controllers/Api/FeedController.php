<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $posts = Post::with('user:id,name,username,avatar')
            ->withCount(['likes', 'comments'])
            ->latest()
            ->paginate(10);

        return PostResource::collection($posts);
    }
}
