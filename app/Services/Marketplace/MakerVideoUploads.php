<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\MakerVideo;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MakerVideoUploads
{
    public const CHUNK_BYTES = 1048576;

    private const LIFETIME = 86400;

    private const STAGING = 'upload-staging';

    private const MIME = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];

    public function start(int $maker, string $token, int $size, string $fingerprint, ?int $video, int $revision): array
    {
        $this->prune(20);

        return $this->locked('reservations', function () use ($maker, $token, $size, $fingerprint, $video, $revision) {
            return $this->locked($maker.':'.$token, function () use ($maker, $token, $size, $fingerprint, $video, $revision) {
                $dir = $this->directory($maker, $token);
                if (is_file($dir.'/meta.json')) {
                    $meta = $this->read($dir);
                    abort_unless($meta['size'] === $size && $meta['fingerprint'] === $fingerprint
                        && $meta['video_id'] === $video && $meta['revision'] === $revision, 409, 'This upload belongs to a different file or draft.');

                    return $this->status($meta);
                }
                $count = $reserved = 0;
                foreach (glob($this->root().'/*/*/meta.json') ?: [] as $entry) {
                    $other = $this->metadata($entry);
                    if ($other === null || $other['state'] === 'applied') {
                        continue;
                    }
                    $reserved += $other['size'];
                    $count += ((int) basename(dirname($entry, 2)) === $maker) ? 1 : 0;
                }
                abort_if($count >= 3 || $size + $reserved > 8 * 1024 ** 3, 429, 'Video upload storage is busy. Finish an existing upload or try again later.');
                $available = disk_free_space($this->root());
                abort_if($available !== false && $available < $size * 2 + 64 * self::CHUNK_BYTES, 507, 'Video storage is full. Try again later.');
                File::ensureDirectoryExists($dir, 0700);
                $meta = ['size' => $size, 'fingerprint' => $fingerprint, 'hashes' => [], 'state' => 'receiving',
                    'video_id' => $video, 'revision' => $revision];
                $this->write($dir, $meta);

                return $this->status($meta);
            });
        });
    }

    public function chunk(int $maker, string $token, int $index, UploadedFile $part, string $checksum): array
    {
        return $this->locked($maker.':'.$token, function () use ($maker, $token, $index, $part, $checksum) {
            $dir = $this->directory($maker, $token);
            $meta = $this->read($dir);
            $expected = min(self::CHUNK_BYTES, $meta['size'] - $index * self::CHUNK_BYTES);
            $next = count($meta['hashes']);
            abort_if($index < 0 || $expected <= 0 || $part->getSize() !== $expected, 422, 'Incorrect chunk size.');
            $bytes = file_get_contents($part->getRealPath());
            abort_unless($bytes !== false && hash_equals($checksum, hash('sha256', $bytes)), 422, 'Chunk checksum mismatch.');
            if ($index < $next) {
                abort_unless(hash_equals($meta['hashes'][$index], $checksum), 409, 'A different chunk was already received.');

                return $this->status($meta);
            }
            abort_unless($meta['state'] === 'receiving' && $index === $next, 409, 'Send the next sequential chunk.');
            $stream = fopen($dir.'/data', 'c+b');
            try {
                $offset = $index * self::CHUNK_BYTES;
                // A crash after writing a part can leave bytes beyond the last committed offset.
                if (fstat($stream)['size'] < $offset || ! ftruncate($stream, $offset) || fseek($stream, $offset) !== 0
                    || fwrite($stream, $bytes) !== $expected || ! fflush($stream)) {
                    throw new RuntimeException('The video chunk could not be written.');
                }
            } finally {
                fclose($stream);
            }
            $meta['hashes'][] = $checksum;
            $this->write($dir, $meta);

            return $this->status($meta);
        });
    }

    public function finish(int $maker, string $token): array
    {
        return $this->locked($maker.':'.$token, function () use ($maker, $token) {
            $dir = $this->directory($maker, $token);
            $meta = $this->read($dir);
            if ($meta['state'] !== 'receiving') {
                return $this->status($meta);
            }
            abort_unless(count($meta['hashes']) === (int) ceil($meta['size'] / self::CHUNK_BYTES), 409, 'The video is still uploading.');
            abort_unless(hash_equals($meta['fingerprint'], hash('sha256', implode('', $meta['hashes']))), 422, 'Video fingerprint mismatch.');
            $file = $dir.'/data';
            clearstatcache(true, $file);
            abort_unless(is_file($file) && filesize($file) === $meta['size'] && $meta['size'] <= config('marketplace.video_upload_mb') * 1024 ** 2, 422, 'Incorrect completed video size.');
            $stream = fopen($file, 'rb');
            $digest = hash_init('sha256');
            try {
                foreach ($meta['hashes'] as $expected) {
                    $bytes = fread($stream, self::CHUNK_BYTES);
                    abort_unless($bytes !== false && hash_equals($expected, hash('sha256', $bytes)), 422, 'A stored chunk failed verification.');
                    hash_update($digest, $bytes);
                }
            } finally {
                fclose($stream);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
            if (! isset(self::MIME[$mime])) {
                throw ValidationException::withMessages(['source' => 'Choose an MP4, MOV or WebM video.']);
            }
            $meta['sha256'] = hash_final($digest);
            $meta['ext'] = self::MIME[$mime];
            $meta['state'] = 'ready';
            $this->write($dir, $meta);

            return $this->status($meta);
        });
    }

    public function save(int $maker, string $token, ?int $video, int $revision, Closure $save)
    {
        return $this->locked($maker.':'.$token, function () use ($maker, $token, $video, $revision, $save) {
            $dir = $this->directory($maker, $token);
            $meta = $this->read($dir);
            abort_unless($meta['video_id'] === $video && $meta['revision'] === $revision, 409, 'This upload belongs to a different draft revision.');
            abort_unless(in_array($meta['state'], ['ready', 'applied'], true), 409, 'Complete the video upload first.');
            $name = $token.'.'.$meta['ext'];
            $path = 'sources/'.$maker.'/'.$name;
            // Recover a success whose HTTP response or staging receipt was lost.
            $existing = MakerVideo::where('maker_id', $maker)
                ->when(isset($meta['saved_id']), fn ($q) => $q->whereKey($meta['saved_id']), fn ($q) => $q->where('source_path', $path))
                ->first();
            if ($existing) {
                $this->applied($dir, $meta, $existing->id);

                return redirect()->route('marketplace.public.maker.videos.edit', [$maker, $existing->id])->with('status', 'Video already saved.');
            }
            abort_if($meta['state'] === 'applied', 410, 'This upload has already been used.');
            $file = new UploadedFile($dir.'/data', $name, null, null, true);
            abort_unless(is_file($file->getPathname()) && hash_equals($meta['sha256'], hash_file('sha256', $file->getPathname())), 422, 'Staged video checksum mismatch.');
            [$response, $savedId] = $save($file, $name);
            $this->applied($dir, $meta, $savedId);

            return $response;
        });
    }

    private function applied(string $dir, array $meta, int $id): void
    {
        if ($meta['state'] !== 'applied') {
            $meta['state'] = 'applied';
            $meta['saved_id'] = $id;
            $this->write($dir, $meta);
        }
        File::delete($dir.'/data');
    }

    public function cancel(int $maker, string $token): void
    {
        $this->locked($maker.':'.$token, function () use ($maker, $token) {
            $dir = $this->directory($maker, $token);
            if (is_file($dir.'/meta.json') && $this->metadata($dir.'/meta.json')['state'] !== 'applied') {
                File::deleteDirectory($dir);
            }
        });
    }

    public function prune(int $limit = 250): int
    {
        $removed = 0;
        foreach (glob($this->root().'/[0-9]*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            if (! Str::isUuid($token) || ! $this->expired($dir)) {
                continue;
            }
            $this->locked(basename(dirname($dir)).':'.$token, function () use ($dir, &$removed) {
                if (is_dir($dir) && $this->expired($dir)) {
                    File::deleteDirectory($dir);
                    $removed++;
                }
            }, true);
            if ($removed >= $limit) {
                break;
            }
        }

        return $removed;
    }

    private function root(): string
    {
        return Storage::disk('marketplace_video')->path(self::STAGING);
    }

    private function directory(int $maker, string $token): string
    {
        abort_unless($maker > 0 && Str::isUuid($token), 404);

        return $this->root().'/'.$maker.'/'.strtolower($token);
    }

    private function locked(string $key, Closure $action, bool $skip = false)
    {
        $locks = $this->root().'/.locks';
        File::ensureDirectoryExists($locks, 0700);
        // Fixed lock files stay allocated when upload directories are pruned.
        $slot = $key === 'reservations' ? 'reservations' : substr(hash('sha256', $key), 0, 2);
        $lock = fopen($locks.'/'.$slot, 'c+b');
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                if ($skip) {
                    return null;
                }
                abort(423, 'Upload is busy. Retry the request.');
            }

            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function expired(string $dir): bool
    {
        $meta = $this->metadata($dir.'/meta.json');
        $updated = $meta['updated'] ?? @filemtime($dir);

        return $updated !== false && $updated < now()->timestamp - self::LIFETIME;
    }

    private function read(string $dir): array
    {
        abort_unless(is_file($dir.'/meta.json'), 404, 'Upload does not exist.');
        $meta = $this->metadata($dir.'/meta.json');
        abort_if($meta === null, 404, 'Upload does not exist.');
        abort_if($meta['updated'] < now()->timestamp - self::LIFETIME, 410, 'Upload expired. Select the video again.');

        return $meta;
    }

    private function metadata(string $file): ?array
    {
        // Reservation/pruning scans can race a cancellation from a different request.
        $bytes = @file_get_contents($file);
        if ($bytes === false && ! is_file($file)) {
            return null;
        }
        if ($bytes === false) {
            throw new RuntimeException('Unable to read the video upload state.');
        }

        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    private function write(string $dir, array $meta): void
    {
        $meta['updated'] = now()->timestamp;
        File::replace($dir.'/meta.json', json_encode($meta, JSON_THROW_ON_ERROR), 0600);
    }

    private function status(array $meta): array
    {
        return ['state' => $meta['state'], 'next_index' => count($meta['hashes']), 'chunk_bytes' => self::CHUNK_BYTES];
    }
}
