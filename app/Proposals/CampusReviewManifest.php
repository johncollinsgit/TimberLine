<?php

namespace App\Proposals;

use App\Models\Tenant;

/** A reviewed fictional build is bound to a published proposal, never to campus membership. */
class CampusReviewManifest
{
    public const CLIENT_REFERENCE = 'lees-mcrae-college';

    public const PAGES = [
        'home' => 'Campus overview', 'requests' => 'Requests', 'projects' => 'Projects',
        'roll_call' => 'Roll Call', 'buildings' => 'Buildings', 'calendar' => 'Calendar',
        'people' => 'People and contractors', 'assets' => 'Assets', 'workflows' => 'Workflows',
        'finance' => 'Finance packets', 'usage' => 'AI and voice usage', 'ask' => 'Ask Everbranch',
        'contractors' => 'Contractor planning', 'requester' => 'Requester experience',
    ];

    public function current(): array
    {
        abort_unless(config('service_proposals.campus_review.enabled') === true, 404);
        $build = config('service_proposals.campus_review.build_id');
        abort_unless(is_string($build) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $build), 503,
            'A reviewed campus build must be configured before campus review is available.');

        return ['schema_version' => 1, 'client_reference' => self::CLIENT_REFERENCE, 'build_id' => $build, 'source_hash' => $this->sourceHash(),
            'pages' => array_map(fn ($id, $title) => ['id' => $id, 'title' => $title], array_keys(self::PAGES), array_values(self::PAGES))];
    }

    /** Fixed local source groups only; never accepts a file path from a reviewer. */
    protected function sourceHash(): string
    {
        $paths = ['app/Enterprise/ReviewerPresentation.php', 'app/Enterprise/LocationCatalog.php',
            'app/Proposals/CampusReviewManifest.php', 'resources/views/service-proposals/campus-review.blade.php',
            'resources/data/enterprise/lees-mcrae-locations.json', 'resources/data/enterprise/official-campus-plan.png'];
        foreach (['resources/views/enterprise/*.blade.php', 'resources/js/enterprise/*.js', 'resources/js/enterprise/*.tsx',
            'resources/js/enterprise/*.css', 'resources/js/enterprise/reviewer/*.js'] as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $file) {
                $paths[] = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
            }
        }
        $manifestPath = 'apps/enterprise/public/build/manifest.json';
        $paths[] = $manifestPath;
        if (is_file(base_path($manifestPath))) {
            $manifest = json_decode(file_get_contents(base_path($manifestPath)), true, 32, JSON_THROW_ON_ERROR);
            foreach (is_array($manifest) ? $manifest : [] as $entry) {
                foreach (array_merge([$entry['file'] ?? ''], $entry['css'] ?? []) as $asset) {
                    abort_unless(is_string($asset) && preg_match('~^assets/[A-Za-z0-9._-]+$~D', $asset), 503);
                    $paths[] = 'apps/enterprise/public/build/'.$asset;
                }
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        abort_if(count($paths) > 200, 503, 'The campus review source manifest exceeds its reviewed bound.');
        $hashes = [];
        foreach ($paths as $path) {
            $absolute = base_path($path);
            abort_if(is_file($absolute) && filesize($absolute) > 20000000, 503);
            $hashes[$path] = is_file($absolute) ? hash_file('sha256', $absolute) : 'absent';
        }

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    public function forPublication(Tenant $tenant, array $payload): ?array
    {
        if (config('service_proposals.campus_review.enabled') !== true
            || ($payload['client']['reference'] ?? null) !== self::CLIENT_REFERENCE
            || config('service_proposals.owner_workspaces.'.$tenant->id) !== 'enterprise') {
            return null;
        }

        return $this->current();
    }

    public function available(Version $version): bool
    {
        try {
            $this->assertCurrent($version, 'home');

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if (! in_array($error->getStatusCode(), [404, 409, 410, 503], true)) {
                throw $error;
            }

            return false;
        }
    }

    public function assertCurrent(Version $version, string $page): array
    {
        abort_unless(config('service_proposals.owner_workspaces.'.$version->tenant_id) === 'enterprise'
            && ($version->payload['client']['reference'] ?? null) === self::CLIENT_REFERENCE
            && in_array($version->payload['audience'] ?? null, ['client', 'reviewer'], true), 404);
        abort_if($version->revoked_at || ! $version->expires_at->isFuture(), 410);
        $manifest = $version->payload['campus_review'] ?? null;
        abort_unless(is_array($manifest), 404);
        abort_unless($manifest === $this->current(), 409,
            'This published campus review build is no longer current. Request a new published review version.');
        abort_unless(array_key_exists($page, self::PAGES), 404);

        return $manifest;
    }
}
