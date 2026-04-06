<?php

namespace App\Http\Controllers;

use App\Support\MediaUrl;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        $disk = MediaUrl::disk();

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
