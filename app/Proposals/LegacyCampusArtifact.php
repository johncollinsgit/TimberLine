<?php

namespace App\Proposals;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Verified public bytes only. Never accepts a member name from an HTTP request. */
final class LegacyCampusArtifact
{
    private array $index;

    private array $contents = [];

    private string $directory;

    /** An explicit alternate directory is for inspecting an identical signed public artifact, not authority. */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? dirname(__DIR__, 2).'/resources/legacy-campus/v5';
        abort_unless(realpath($this->directory) === $this->directory && ! is_link($this->directory), 503, 'The legacy renderer artifact is unavailable.');
        $encoded = $this->read('index.json', LegacyCampusV5Definition::INDEX_SHA256, 65536);
        $this->index = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        abort_unless(($this->index['renderer'] ?? null) === LegacyCampusV5Definition::RENDERER
            && is_array($this->index['files'] ?? null) && count($this->index['files']) === 125, 503);
        $expected = ['index.json'];
        $directories = [];
        $total = 0;
        foreach ($this->index['files'] as $member => $metadata) {
            abort_unless(is_string($member) && preg_match('~^(?:source/(?:resources|apps/enterprise/public/build)/|views/)[A-Za-z0-9._/-]+$~D', $member)
                && ! array_intersect(explode('/', $member), ['', '.', '..'])
                && is_array($metadata) && is_int($metadata['bytes'] ?? null) && $metadata['bytes'] >= 0 && $metadata['bytes'] <= 20000000
                && is_string($metadata['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']), 503);
            $bytes = $this->read($member, $metadata['sha256'], $metadata['bytes']);
            abort_unless(strlen($bytes) === $metadata['bytes'], 503, 'The legacy renderer artifact changed.');
            $this->contents[$member] = $bytes;
            $expected[] = $member;
            $total += strlen($bytes);
            for ($parent = dirname($member); $parent !== '.'; $parent = dirname($parent)) {
                $directories[$parent] = true;
            }
        }
        abort_if($total > 8000000, 503);
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $relative = substr($entry->getPathname(), strlen($this->directory) + 1);
            abort_if($entry->isLink(), 503, 'Linked legacy renderer members are refused.');
            if ($entry->isDir()) {
                abort_unless(isset($directories[$relative]) && ($entry->getPerms() & 0022) === 0, 503);
            } else {
                $actual[] = $relative;
            }
        }
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        abort_unless($actual === $expected, 503, 'The legacy renderer member inventory changed.');
    }

    private function read(string $member, string $hash, int $maximum): string
    {
        $path = $this->directory.'/'.$member;
        $relative = '';
        foreach (explode('/', $member) as $part) {
            $relative .= '/'.$part;
            abort_if(is_link($this->directory.$relative), 503, 'Linked legacy renderer members are refused.');
        }
        $info = @lstat($path);
        abort_unless($info && ($info['mode'] & 0170000) === 0100000 && $info['nlink'] === 1
            && ($info['mode'] & 0777) === 0644 && $info['size'] <= $maximum
            && realpath($path) === $path, 503, 'A legacy renderer member is unavailable.');
        $stream = @fopen($path, 'rb');
        abort_unless(is_resource($stream), 503);
        try {
            $opened = fstat($stream);
            abort_unless($opened && $opened['dev'] === $info['dev'] && $opened['ino'] === $info['ino'], 503);
            $bytes = stream_get_contents($stream, $maximum + 1);
            $after = @lstat($path);
            abort_unless(is_string($bytes) && strlen($bytes) <= $maximum && $after && ! is_link($path)
                && $after['dev'] === $info['dev'] && $after['ino'] === $info['ino'] && $after['nlink'] === 1
                && hash_equals($hash, hash('sha256', $bytes)), 503, 'A legacy renderer member changed.');

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    public function manifest(): array
    {
        return $this->index['original_manifest'];
    }

    public function bytes(string $originalPath): string
    {
        abort_unless(array_key_exists('source/'.$originalPath, $this->contents), 503, 'An unlisted legacy resource was refused.');

        return $this->contents['source/'.$originalPath];
    }

    public function viewRoot(): string
    {
        return $this->directory.'/views';
    }

    public function viewBytes(): array
    {
        $views = [];
        foreach ($this->contents as $member => $bytes) {
            if (str_starts_with($member, 'views/')) {
                $views[$this->directory.'/'.$member] = $bytes;
            }
        }

        return $views;
    }
}
