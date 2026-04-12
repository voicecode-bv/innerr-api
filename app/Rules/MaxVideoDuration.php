<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MaxVideoDuration implements ValidationRule
{
    /**
     * @param  int  $maxSeconds  Maximum allowed duration in seconds.
     */
    public function __construct(
        protected int $maxSeconds = 180,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $mimeType = $value->getMimeType() ?? '';
        if (! str_starts_with($mimeType, 'video/')) {
            return; // Not a video, skip duration check.
        }

        try {
            $result = Process::timeout(10)->run([
                'ffprobe',
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $value->getPathname(),
            ]);

            if ($result->failed()) {
                Log::warning('MaxVideoDuration: ffprobe failed', [
                    'stderr' => $result->errorOutput(),
                ]);

                return; // Allow upload if ffprobe fails; server will handle it.
            }

            $duration = (float) trim($result->output());

            if ($duration > $this->maxSeconds) {
                $maxMinutes = (int) ceil($this->maxSeconds / 60);
                $fail(__('validation.max_video_duration', [
                    'max' => $maxMinutes,
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning('MaxVideoDuration: exception during validation', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
