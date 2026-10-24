<?php

namespace App\Proposals;

use App\Services\Team\TeamFileScanner;
use Carbon\CarbonImmutable;

/** Closed future-edition image admission. No boolean or status-only scanner reply can confer readiness. */
final class ReviewerWorkspaceImagesPolicy
{
    public function resolve(Version $version, bool $requireScanner = true): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.image_access.enabled') === true, 404);
        $payload = $version->payload;
        $features = [];
        foreach (['review_workspace', 'review_workspace_collaboration', 'review_workspace_attachments', 'review_workspace_images'] as $key) {
            $f = $payload[$key] ?? null;
            abort_unless(is_array($f), 404);
            if ($key === 'review_workspace') {
                app(ReviewerWorkspaceSchema::class)->descriptor($f);
            } else {
                ReviewerWorkspaceSchema::exact($f, ['schema_version', 'policy_sha256', 'expires_at']);
                abort_unless($f['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($f['policy_sha256']) && ReviewerWorkspaceSchema::utc($f['expires_at']), 503);
            }
            abort_unless(CarbonImmutable::parse($f['expires_at'])->lte($version->expires_at), 503);
            abort_unless(now()->lt($f['expires_at']), 410);
            $features[$key] = $f;
        }
        abort_unless($version->revoked_at === null && $version->expires_at->isFuture()
            && CarbonImmutable::parse($features['review_workspace_images']['expires_at'])->lte($features['review_workspace_attachments']['expires_at'])
            && CarbonImmutable::parse($features['review_workspace_attachments']['expires_at'])->lte($features['review_workspace_collaboration']['expires_at'])
            && CarbonImmutable::parse($features['review_workspace_collaboration']['expires_at'])->lte($features['review_workspace']['expires_at']), 410);
        $hash = $features['review_workspace_images']['policy_sha256'];
        $policy = config('service_proposals.reviewer_workspace.image_access.policies.'.$hash);
        abort_unless(is_array($policy), 503);
        ReviewerWorkspaceSchema::exact($policy, ['schema_version', 'images_enabled', 'scan_config_sha256', 'max_scan_age_seconds', 'thumbnail_width', 'thumbnail_height', 'thumbnail_max_bytes']);
        abort_unless($policy['schema_version'] === 1 && $policy['images_enabled'] === true && ReviewerWorkspaceSchema::hash($policy['scan_config_sha256'])
            && is_int($policy['max_scan_age_seconds']) && $policy['max_scan_age_seconds'] >= 60 && $policy['max_scan_age_seconds'] <= 3600
            && $policy['thumbnail_width'] === 320 && $policy['thumbnail_height'] === 240 && $policy['thumbnail_max_bytes'] === 262144
            && hash_equals($hash, ReviewerWorkspaceSchema::digest($policy)), 503);
        $scanner = $this->scanner($policy['scan_config_sha256'], $requireScanner);

        return ['image_feature' => $features['review_workspace_images'], 'image_policy' => $policy, 'scanner' => $scanner];
    }

    public function scanner(string $hash, bool $requireEnabled = true): array
    {
        $s = config('service_proposals.reviewer_workspace.image_access.scanners.'.$hash);
        abort_unless(is_array($s), 503);
        ReviewerWorkspaceSchema::exact($s, ['schema_version', 'transport', 'host', 'port', 'file_max_bytes', 'engine_version', 'minimum_database_serial',
            'maximum_definition_age_seconds', 'daemon_timezone', 'scanner_source_sha256', 'evidence_source_sha256']);
        abort_unless($s['schema_version'] === 1 && $s['transport'] === 'existing_team_clamd_version_v1' && $s['host'] === '127.0.0.1'
            && is_int($s['port']) && $s['port'] >= 1 && $s['port'] <= 65535 && is_int($s['file_max_bytes']) && $s['file_max_bytes'] >= ReviewerWorkspaceAttachmentsStore::MAX_BYTES
            && $s['file_max_bytes'] <= 50 * 1024 * 1024 && is_string($s['engine_version']) && preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?$/D', $s['engine_version'])
            && is_int($s['minimum_database_serial']) && $s['minimum_database_serial'] > 0
            && is_int($s['maximum_definition_age_seconds']) && $s['maximum_definition_age_seconds'] >= 3600 && $s['maximum_definition_age_seconds'] <= 172800
            && $s['daemon_timezone'] === 'UTC' && hash_equals($hash, ReviewerWorkspaceSchema::digest($s)), 503);
        abort_unless((! $requireEnabled || config('team_work.scanner.enabled') === true) && config('team_work.scanner.host') === $s['host']
            && config('team_work.scanner.port') === $s['port'] && config('team_work.file_max_bytes') === $s['file_max_bytes']
            && hash_equals($s['scanner_source_sha256'], hash_file('sha256', (new \ReflectionClass(TeamFileScanner::class))->getFileName()))
            && hash_equals($s['evidence_source_sha256'], hash_file('sha256', (new \ReflectionClass(ReviewerWorkspaceScannerEvidence::class))->getFileName())), 503);

        return $s;
    }

    /** Pure last-point expiry fence, also used after the last fresh authority callback before scan or binary output. */
    public function assertTime(Version $version, array $admission, ?string $scanExpires = null): void
    {
        $now = now();
        abort_unless($version->revoked_at === null && $now->lt($version->expires_at)
            && $now->lt($admission['image_feature']['expires_at']) && ($scanExpires === null || $now->lt($scanExpires)), 410);
        foreach (['review_workspace', 'review_workspace_collaboration', 'review_workspace_attachments'] as $key) {
            abort_unless($now->lt($version->payload[$key]['expires_at']), 410);
        }
    }
}
