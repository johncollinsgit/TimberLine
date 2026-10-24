<?php

namespace App\Proposals;

/** Operator-owned exact allowlist, EMPTY by default. A renderer is never an access grant. */
final class LegacyCampusRegistry
{
    public function registered(Version $version): bool
    {
        $rows = config('service_proposals.campus_review.legacy_versions', []);
        abort_unless(is_array($rows), 503, 'The legacy review registry is invalid.');

        return array_key_exists((string) $version->id, $rows);
    }

    /** Compatibility is browser/feedback only, even if a future current build happens to share its manifest. */
    public function denyAdditionalAuthority(Version $version): void
    {
        abort_if($this->registered($version), 404, 'This edition has no additional service authority.');
    }

    public function resolve(Version $version, string $page): ?array
    {
        if (! $this->registered($version)) {
            return null;
        }
        $row = config('service_proposals.campus_review.legacy_versions')[(string) $version->id];
        $keys = is_array($row) ? array_keys($row) : [];
        sort($keys, SORT_STRING);
        abort_unless($keys === ['content_hash', 'renderer', 'tenant_id'] && is_int($row['tenant_id']) && $row['tenant_id'] > 0
            && is_string($row['content_hash']) && preg_match('/^[a-f0-9]{64}$/D', $row['content_hash'])
            && $row['renderer'] === LegacyCampusV5Definition::RENDERER, 503, 'The legacy review registry is invalid.');
        abort_unless(config('service_proposals.campus_review.enabled') === true
            && config('service_proposals.owner_workspaces.'.$version->tenant_id) === 'enterprise'
            && (int) $version->tenant_id === $row['tenant_id'] && hash_equals($row['content_hash'], $version->content_hash)
            && ($version->payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE
            && in_array($version->payload['audience'] ?? null, ['client', 'reviewer'], true), 404);
        try {
            $valid = ! $version->revoked_at && $version->expires_at instanceof \Carbon\CarbonInterface && $version->expires_at->isFuture();
        } catch (\Throwable) {
            $valid = false;
        }
        abort_unless($valid, 410);
        $artifact = new LegacyCampusArtifact;
        $manifest = $artifact->manifest();
        abort_unless(($version->payload['campus_review'] ?? null) === $manifest, 409, 'The legacy review binding changed.');
        abort_unless(in_array($page, array_column($manifest['pages'], 'id'), true), 404);

        return ['kind' => 'legacy_browser_only', 'manifest' => $manifest, 'renderer' => LegacyCampusV5Definition::RENDERER];
    }
}
