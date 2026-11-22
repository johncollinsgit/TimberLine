<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\MakerVideo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;

class MakerVideoRenderer
{
    public function render(MakerVideo $video, string $destination): float
    {
        $disk = Storage::disk('marketplace_video');
        $input = $disk->path($video->source_path);
        $probe = new Process([config('marketplace.video_ffprobe'), '-v', 'error', '-protocol_whitelist', 'file', '-format_whitelist', 'mov,matroska,webm',
            '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $input]);
        $probe->setTimeout(30)->mustRun();
        $info = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $duration = (float) ($info['format']['duration'] ?? 0);
        $width = (int) ($info['streams'][0]['width'] ?? 0);
        $height = (int) ($info['streams'][0]['height'] ?? 0);
        // Browser MediaRecorder streams often omit container duration and a seek index.
        $seekable = $duration > 0;
        if (! $seekable && $width > 0 && $height > 0) {
            $scan = new Process([config('marketplace.video_ffmpeg'), '-nostdin', '-v', 'error', '-progress', 'pipe:1',
                '-protocol_whitelist', 'file', '-format_whitelist', 'mov,matroska,webm', '-i', $input,
                '-map', '0:v:0', '-c', 'copy', '-f', 'null', '-']);
            $scan->setTimeout(120)->mustRun();
            preg_match_all('/^out_time_us=(\d+)/m', $scan->getOutput(), $times);
            $duration = (float) end($times[1]) / 1000000;
        }
        if ($duration <= 0 || $duration > 14400 || $width <= 0 || $height <= 0 || $width * $height > 3840 * 2160) {
            throw new RuntimeException('Unsupported video.');
        }
        $end = $video->trim_end ?? $duration;
        $length = $end - $video->trim_start;
        if ($end > $duration + 0.1 || $length <= 0) {
            throw ValidationException::withMessages(['trim_end' => 'The trim points fall outside the source video.']);
        }
        app(MakerVideoCaptions::class)->normalize($video->captions, $length);
        if ($video->tags()->where('active', true)->where(fn ($q) => $q->where('start_seconds', '>=', $length)->orWhere('end_seconds', '>', $length))->exists()) {
            throw ValidationException::withMessages(['tags' => 'A product time falls outside the edited video.']);
        }

        // Write only a re-encoded, trimmed, metadata-free copy to the reviewed output.
        $command = [config('marketplace.video_ffmpeg'), '-nostdin', '-v', 'error', '-y', '-threads', '2',
            '-protocol_whitelist', 'file', '-format_whitelist', 'mov,matroska,webm', ...($seekable ? ['-ss', (string) $video->trim_start] : []),
            '-i', $input, ...($seekable ? [] : ['-ss', (string) $video->trim_start]),
            '-t', (string) $length, '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '-1', '-map_chapters', '-1',
            '-vf', 'scale=min(1920\,iw):min(1920\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1',
            '-c:v', 'libx264', '-threads', '2', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $disk->path($destination)];
        (new Process($command))->setTimeout(7000)->mustRun();

        (new Process([config('marketplace.video_ffmpeg'), '-nostdin', '-v', 'error', '-y', '-ss', (string) min(1, $length / 4),
            '-i', $disk->path($destination), '-frames:v', '1', '-vf', 'scale=min(720\,iw):-2', '-q:v', '3', $disk->path($destination.'.jpg')]))
            ->setTimeout(60)->mustRun();

        return $length;
    }
}
