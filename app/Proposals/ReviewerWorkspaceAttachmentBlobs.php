<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/** Authenticated encrypted bytes in a dedicated private local namespace. No public disk, URL or caller path. */
final class ReviewerWorkspaceAttachmentBlobs
{
    private const PREFIX = 'proposal-review-workspace';

    private const PHYSICAL_CAP = 64 * 1024 * 1024;

    private function disk()
    {
        abort_unless(config('filesystems.disks.team_private.driver') === 'local' && config('filesystems.disks.team_private.visibility') === 'private'
            && config('filesystems.disks.team_private.serve') === false, 503);

        return Storage::disk('team_private');
    }

    private function path(string $relative, bool $directory = false, bool $create = false): string
    {
        abort_unless(preg_match('~^proposal-review-workspace(?:/[a-zA-Z0-9.-]+)*$~D', $relative) && ! str_contains($relative, '..'), 503);
        $disk = $this->disk();
        $root = realpath($disk->path(''));
        abort_unless(is_string($root) && is_dir($root) && ! is_link(rtrim($disk->path(''), '/')), 503);
        $parts = explode('/', $relative);
        $path = $root;
        foreach ($parts as $index => $part) {
            $path .= '/'.$part;
            abort_if(is_link($path), 503);
            $isDirectory = $directory || $index < count($parts) - 1;
            if ($isDirectory && ! is_dir($path)) {
                abort_unless($create, 503);
                abort_unless(mkdir($path, 0700) || is_dir($path), 503);
            }
            if ($isDirectory) {
                abort_unless(realpath($path) === $path && is_dir($path), 503);
            }
        }

        return $path;
    }

    private function prefix(TeamOperationRecord $row): string
    {
        $data = $row->data;
        abort_unless($row->kind === ReviewerWorkspaceAttachmentsStore::KIND && ReviewerWorkspaceSchema::uuid($row->id)
            && ReviewerWorkspaceSchema::uuid($data['workspace_id'] ?? null) && ReviewerWorkspaceSchema::hash($data['binding']['context_hash'] ?? null), 503);

        return self::PREFIX.'/'.$data['binding']['context_hash'].'/'.$data['workspace_id'].'/'.$row->id;
    }

    private function part(string $part): void
    {
        abort_unless(in_array($part, ['original', 'thumbnail'], true) || preg_match('/^chunk-(?:[0-9]|1[0-9])$/D', $part), 503);
    }

    private function locked(callable $operation): mixed
    {
        $directory = $this->path(self::PREFIX, true, true);
        $lockPath = $directory.'/.quota.lock';
        abort_if(is_link($lockPath), 503);
        $lock = fopen($lockPath, 'c+');
        abort_unless(is_resource($lock), 503);
        chmod($lockPath, 0600);
        try {
            abort_unless(flock($lock, LOCK_EX), 503);

            return $operation($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function size(string $directory): int
    {
        $bytes = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            abort_if($file->isLink(), 503);
            if ($file->isFile()) {
                $bytes += $file->getSize();
                abort_if($bytes > self::PHYSICAL_CAP, 429);
            }
        }

        return $bytes;
    }

    public function put(TeamOperationRecord $row, string $part, string $bytes): void
    {
        $this->part($part);
        abort_unless(strlen($bytes) >= 1 && strlen($bytes) <= ($part === 'original' ? ReviewerWorkspaceAttachmentsStore::MAX_BYTES : ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES), 422);
        $envelope = ['schema_version' => 1, 'binding_sha256' => ReviewerWorkspaceSchema::digest($row->data['binding']),
            'upload_id' => $row->id, 'part' => $part, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => base64_encode($bytes)];
        $cipher = Crypt::encryptString(json_encode($envelope, JSON_THROW_ON_ERROR));
        $this->locked(function ($directory) use ($row, $part, $cipher) {
            // Includes the temporary copy and old destination until atomic replacement; concurrent IO shares this file mutex.
            abort_if($this->size($directory) + strlen($cipher) > self::PHYSICAL_CAP, 429);
            $path = $this->path($this->prefix($row).'/'.$part, false, true);
            abort_if(file_exists(dirname($path).'/.retired') || is_link(dirname($path).'/.retired'), 409);
            $temporary = dirname($path).'/tmp-'.bin2hex(random_bytes(16));
            try {
                abort_unless(file_put_contents($temporary, $cipher, LOCK_EX) === strlen($cipher), 503);
                chmod($temporary, 0600);
                abort_unless(rename($temporary, $path), 503);
                chmod($path, 0600);
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });
    }

    public function read(TeamOperationRecord $row, string $part): string
    {
        $this->part($part);
        $path = $this->path($this->prefix($row).'/'.$part);
        abort_unless(is_file($path) && filesize($path) > 0 && filesize($path) <= 3 * ReviewerWorkspaceAttachmentsStore::MAX_BYTES, 503);
        try {
            $data = json_decode(Crypt::decryptString(file_get_contents($path)), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(503);
        }
        abort_unless(is_array($data), 503);
        ReviewerWorkspaceSchema::exact($data, ['schema_version', 'binding_sha256', 'upload_id', 'part', 'size', 'sha256', 'bytes']);
        abort_unless($data['schema_version'] === 1 && $data['binding_sha256'] === ReviewerWorkspaceSchema::digest($row->data['binding'])
            && $data['upload_id'] === $row->id && $data['part'] === $part && is_int($data['size']) && $data['size'] >= 1
            && $data['size'] <= ($part === 'original' ? ReviewerWorkspaceAttachmentsStore::MAX_BYTES : ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES)
            && ReviewerWorkspaceSchema::hash($data['sha256']) && is_string($data['bytes']), 503);
        $bytes = base64_decode($data['bytes'], true);
        abort_unless(is_string($bytes) && base64_encode($bytes) === $data['bytes'] && strlen($bytes) === $data['size']
            && hash_equals($data['sha256'], hash('sha256', $bytes)), 503);

        return $bytes;
    }

    public function remove(TeamOperationRecord $row, bool $chunksOnly = false): void
    {
        $this->locked(function () use ($row, $chunksOnly) {
            $raw = $this->disk()->path($this->prefix($row));
            if (! file_exists($raw) && ! is_link($raw)) {
                $this->path($this->prefix($row), true, true);
            }
            $path = $this->path($this->prefix($row), true);
            $retired = $path.'/.retired';
            abort_if(is_link($retired), 503);
            if (! $chunksOnly && ! file_exists($retired)) {
                abort_unless(file_put_contents($retired, 'retired', LOCK_EX) === 7, 503);
                chmod($retired, 0600);
            }
            $files = new \DirectoryIterator($path);
            foreach ($files as $file) {
                if ($file->isDot() || $file->getFilename() === '.retired') {
                    continue;
                }
                abort_unless($file->isFile() && ! $file->isLink(), 503);
                $name = $file->getFilename();
                abort_unless(in_array($name, ['original', 'thumbnail'], true) || preg_match('/^(?:chunk-(?:[0-9]|1[0-9])|tmp-[a-f0-9]{32}|scan-[a-f0-9]{64})$/D', $name), 503);
                if (! $chunksOnly || str_starts_with($name, 'chunk-')) {
                    abort_unless(unlink($file->getPathname()), 503);
                }
            }
            // Keep a bounded content-free retirement fence so a stale in-flight writer cannot recreate cancelled bytes.
        });
    }

    /** Private bounded plaintext only for the existing scanner. Caller deletes in finally; owner reaper handles crashed leases. */
    public function scanTemporary(TeamOperationRecord $row, string $bytes, string $nonce): string
    {
        abort_unless(ReviewerWorkspaceSchema::hash($nonce) && strlen($bytes) >= 1 && strlen($bytes) <= ReviewerWorkspaceAttachmentsStore::MAX_BYTES, 503);

        return $this->locked(function ($directory) use ($row, $bytes, $nonce) {
            abort_if($this->size($directory) + strlen($bytes) > self::PHYSICAL_CAP, 429);
            $path = $this->path($this->prefix($row).'/scan-'.$nonce, false, true);
            abort_if(file_exists(dirname($path).'/.retired') || is_link(dirname($path).'/.retired') || file_exists($path) || is_link($path), 409);
            $file = fopen($path, 'x');
            abort_unless(is_resource($file), 503);
            try {
                abort_unless(chmod($path, 0600), 503);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($file, substr($bytes, $offset));
                    abort_unless(is_int($written) && $written > 0, 503);
                    $offset += $written;
                }
                abort_unless(fflush($file), 503);
            } catch (\Throwable $failure) {
                if (is_file($path) && ! is_link($path)) {
                    unlink($path);
                }
                throw $failure;
            } finally {
                fclose($file);
            }

            return $path;
        });
    }

    public function removeScanTemporary(TeamOperationRecord $row, string $nonce): void
    {
        abort_unless(ReviewerWorkspaceSchema::hash($nonce), 503);
        $this->locked(function () use ($row, $nonce) {
            $raw = $this->disk()->path($this->prefix($row).'/scan-'.$nonce);
            if (! file_exists($raw) && ! is_link($raw)) {
                return;
            }
            $path = $this->path($this->prefix($row).'/scan-'.$nonce);
            abort_unless(is_file($path) && ! is_link($path) && unlink($path), 503);
        });
    }

    public function removeThumbnail(TeamOperationRecord $row): void
    {
        $this->locked(function () use ($row) {
            $raw = $this->disk()->path($this->prefix($row).'/thumbnail');
            if (! file_exists($raw) && ! is_link($raw)) {
                return;
            }
            $path = $this->path($this->prefix($row).'/thumbnail');
            abort_unless(is_file($path) && ! is_link($path) && unlink($path), 503);
        });
    }
}
