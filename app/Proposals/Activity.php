<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Private, bounded review journal. No passwords, IPs, user agents or work content. */
class Activity
{
    public const MAX_BYTES = 4194304;

    public const MAX_EVENTS = 4000;

    public const SECTIONS = ['overview', 'approach', 'scope', 'terms', 'review'];

    public function enabled(Version $version): bool
    {
        return config('service_proposals.enabled') === true
            && config('service_proposals.owner_workspaces.'.$version->tenant_id) === 'enterprise'
            && ($version->payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE
            && is_array($version->payload['campus_review'] ?? null);
    }

    private function path(Version $version): string
    {
        if (! ctype_digit((string) $version->tenant_id) || ! Str::isUuid($version->id)) {
            throw new RuntimeException('Invalid review activity scope.');
        }

        return storage_path('app/private/proposal-activity/'.$version->tenant_id.'/'.$version->id.'.jsonl');
    }

    public function record(Request $request, Version $version, string $event, ?string $page = null, ?int $scene = null, ?string $id = null): bool
    {
        if (! $this->enabled($version) || config('service_proposals.writes_enabled') !== true) {
            return false;
        }
        try {
            $key = 'service_proposal_visit.'.$version->id;
            $visit = $request->session()->get($key);
            if (! is_string($visit) || ! Str::isUuid($visit)) {
                $visit = (string) Str::uuid();
                $request->session()->put($key, $visit);
            }
            $name = $request->session()->get('service_proposal_name.'.$version->id);
            $user = $request->user();
            $ownerTest = $user && $user->is_active && $user->email_verified_at
                && $user->tenants()->where('tenants.id', $version->tenant_id)
                    ->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'admin'])->exists();
            $entry = ['id' => $id ?? (string) Str::uuid(), 'at' => now()->utc()->toIso8601String(),
                'visit' => $visit, 'event' => $event, 'page' => $page, 'scene' => $scene,
                'name' => in_array($name, $version->payload['reviewer_names'] ?? [], true) ? $name : null,
                'owner_test' => (bool) $ownerTest, 'version_hash' => $version->content_hash];
            $path = $this->path($version);
            $directory = dirname($path);
            foreach ([dirname($directory), $directory, $path] as $candidate) {
                if (is_link($candidate)) {
                    throw new RuntimeException('Review journal links are unavailable.');
                }
            }
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Review journal directory is unavailable.');
            }
            $handle = @fopen($path, 'c+b');
            if ($handle === false) {
                throw new RuntimeException('Review journal is unavailable.');
            }
            try {
                if (! flock($handle, LOCK_EX)) {
                    throw new RuntimeException('Review journal lock is unavailable.');
                }
                chmod($path, 0600);
                $size = fstat($handle)['size'];
                if ($size >= self::MAX_BYTES) {
                    return false;
                }
                $count = 0;
                while (($line = fgets($handle, 16384)) !== false) {
                    $row = json_decode($line, true);
                    if (! is_array($row) || ! isset($row['id'], $row['value'])) {
                        throw new RuntimeException('Review journal needs inspection.');
                    }
                    if ($row['id'] === $entry['id']) {
                        return true;
                    }
                    $count++;
                }
                $line = json_encode(['id' => $entry['id'], 'value' => Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR)."\n";
                if ($count >= self::MAX_EVENTS || $size + strlen($line) > self::MAX_BYTES) {
                    return false;
                }
                fseek($handle, 0, SEEK_END);
                if (fwrite($handle, $line) !== strlen($line) || ! fflush($handle)) {
                    throw new RuntimeException('Review journal write is incomplete.');
                }

                return true;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (Throwable $error) {
            // Analytics must not prevent a reviewer from opening the proposal.
            Log::warning('Proposal activity could not be recorded.', ['version_id' => $version->id]);

            return false;
        }
    }

    /** Caller must first resolve the current active owner and exact tenant/version. */
    public function read(Version $version): array
    {
        $result = ['events' => [], 'limited' => false, 'unavailable' => false];
        if (! $this->enabled($version)) {
            return $result;
        }
        $path = $this->path($version);
        if (! file_exists($path)) {
            return $result;
        }
        if (is_link($path) || is_link(dirname($path)) || is_link(dirname(dirname($path)))) {
            return [...$result, 'unavailable' => true];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [...$result, 'unavailable' => true];
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                return [...$result, 'unavailable' => true];
            }
            if (fstat($handle)['size'] > self::MAX_BYTES) {
                return [...$result, 'limited' => true, 'unavailable' => true];
            }
            while (($line = fgets($handle, 16384)) !== false && count($result['events']) < self::MAX_EVENTS) {
                try {
                    $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    $entry = json_decode(Crypt::decryptString($row['value']), true, 16, JSON_THROW_ON_ERROR);
                    if (($entry['version_hash'] ?? null) !== $version->content_hash || ! Str::isUuid($entry['visit'] ?? '')) {
                        throw new RuntimeException('Review journal scope mismatch.');
                    }
                    $result['events'][] = $entry;
                } catch (Throwable $error) {
                    $result['unavailable'] = true;
                }
            }
            $result['limited'] = count($result['events']) >= self::MAX_EVENTS || fstat($handle)['size'] >= self::MAX_BYTES - 2048;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $result;
    }
}
