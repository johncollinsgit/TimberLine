<?php

namespace App\Jobs;

use App\Models\Marketplace\MakerVideo;
use App\Services\Marketplace\MakerVideoRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RenderMarketplaceVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7200;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function __construct(public int $videoId, public int $revision)
    {
        $this->onConnection('maker_video')->onQueue('maker-video');
    }

    public function handle(MakerVideoRenderer $renderer): void
    {
        $current = MakerVideo::whereKey($this->videoId)->where('revision', $this->revision)->where('status', 'processing');
        $video = (clone $current)->first();
        if (! $video) {
            return;
        }

        $disk = Storage::disk('marketplace_video');
        $directory = 'renders/'.$video->id;
        $disk->makeDirectory($directory);
        $output = $directory.'/'.Str::uuid().'.mp4';
        $retained = false;
        try {
            $duration = $renderer->render($video, $output);
            $retained = (bool) $current->update(['status' => 'review', 'render_path' => $output, 'duration' => $duration]);
        } catch (ValidationException $error) {
            $current->update(['status' => 'changes', 'review_note' => collect($error->errors())->flatten()->first()]);
        } finally {
            if (! $retained) {
                $disk->delete([$output, $output.'.jpg']);
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        MakerVideo::whereKey($this->videoId)->where('revision', $this->revision)->where('status', 'processing')->update([
            'status' => 'changes', 'review_note' => 'Video processing failed. Check the file and submit again. Contact the marketplace if it keeps happening.',
        ]);
    }
}
