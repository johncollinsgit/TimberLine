<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use App\Enterprise\Provisioning\ClientSilo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only, actual-owner admission for a local observational export. No graph or capture record exists. */
final class ReviewerLegacyCapture
{
    private const APPROVAL_KEYS = ['tenant_id', 'client_id', 'silo_id', 'storage_id', 'version_hash', 'manifest_sha256', 'source_hash', 'build_id', 'module_sha256', 'expires_at'];

    public const PIN_KEYS = ['version_hash', 'manifest_sha256', 'source_hash', 'build_id', 'approval_sha256'];

    public function __construct(private Access $owners, private CampusReviewManifest $manifests, private ClientSilo $silos, private ProposalService $proposals) {}

    public function page(Request $request, string $versionId): array
    {
        return $this->admit($request, $versionId);
    }

    public function authorize(Request $request, string $versionId, array $pins): array
    {
        $context = $this->admit($request, $versionId);
        abort_unless(self::keys($pins, self::PIN_KEYS)
            && count(array_filter(self::PIN_KEYS, fn ($key) => $pins[$key] !== $context['pins'][$key])) === 0, 409);
        $now = now()->utc();
        $deadline = min($now->timestamp + 60, $context['expires']);
        abort_unless($now->timestamp < $deadline, 410);

        return ['schema_version' => 1, 'pins' => $pins, 'deadline' => gmdate('Y-m-d\TH:i:s\Z', $deadline),
            'capture_binding' => ['schema_version' => 1, 'capture_id' => (string) Str::uuid(), 'observed_at' => $now->format('Y-m-d\TH:i:s\Z'),
                'observed_version_id' => $versionId, 'observed_version_hash' => $pins['version_hash'],
                'observed_source_hash' => $pins['source_hash'], 'observed_build_id' => $pins['build_id']]];
    }

    private function admit(Request $request, string $versionId): array
    {
        abort_unless(config('service_proposals.legacy_capture.enabled') === true, 404);
        abort_unless(Str::isUuid($versionId) && MountUrls::matches($request), 404);

        return DB::transaction(function () use ($request, $versionId): array {
            $tenant = $this->owners->owner($request);
            $this->freshOwner($request, (int) $tenant->id);
            $approval = $this->approval($versionId);
            $this->runtime($approval, (int) $tenant->id);
            $version = Version::query()->whereKey($versionId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $version->tenant_id === (int) $tenant->id && is_array($version->payload)
                && ! array_key_exists('review_workspace', $version->payload), 404);
            abort_if($version->revoked_at !== null || ! $version->expires_at->isFuture(), 410);
            abort_unless(hash_equals($approval['version_hash'], $version->content_hash)
                && hash_equals($version->content_hash, $this->proposals->hash($version->payload)), 409);
            $edition = $this->manifests->resolveReviewEdition($version, 'home');
            $manifest = $edition['manifest'] ?? null;
            $script = self::script();
            abort_unless(in_array($edition['kind'] ?? null, ['current', 'legacy_browser_only'], true) && is_array($manifest)
                && hash_equals($approval['manifest_sha256'], ReviewerWorkspaceSchema::digest($manifest))
                && ($manifest['source_hash'] ?? null) === $approval['source_hash']
                && ($manifest['build_id'] ?? null) === $approval['build_id']
                && hash_equals($approval['module_sha256'], hash('sha256', $script)), 409);
            $expiry = strtotime($approval['expires_at']);
            abort_unless($expiry <= $version->expires_at->timestamp, 422);
            // Re-read real authority after source/marker IO. No cached Request User can override revocation.
            $this->runtime($approval, (int) $tenant->id);
            $this->freshOwner($request, (int) $tenant->id);
            $version = Version::query()->whereKey($versionId)->lockForUpdate()->firstOrFail();
            abort_if($version->revoked_at !== null || ! $version->expires_at->isFuture() || now()->timestamp >= $expiry, 410);
            abort_unless($this->approval($versionId) === $approval, 409);
            $pins = array_intersect_key($approval, array_flip(['version_hash', 'manifest_sha256', 'source_hash', 'build_id']));
            $pins['approval_sha256'] = ReviewerWorkspaceSchema::digest($approval);
            $url = MountUrls::url('/owner-workspaces/'.$tenant->slug.'/proposals/legacy-capture/'.$versionId.'/authorize');
            abort_unless(is_string($url), 503);

            return ['version_id' => $versionId, 'pins' => $pins, 'authorize_url' => $url, 'expires' => $expiry, 'script' => $script];
        });
    }

    private function approval(string $versionId): array
    {
        $map = config('service_proposals.legacy_capture.approvals');
        abort_unless(is_array($map) && count($map) === 1 && array_key_exists($versionId, $map)
            && is_array($map[$versionId]) && self::keys($map[$versionId], self::APPROVAL_KEYS), 404);
        $a = $map[$versionId];
        abort_unless(is_int($a['tenant_id']) && $a['tenant_id'] > 0, 422);
        foreach (['client_id', 'silo_id', 'storage_id'] as $key) {
            abort_unless(is_string($a[$key]) && Str::isUuid($a[$key]), 422);
        }
        foreach (['version_hash', 'manifest_sha256', 'source_hash', 'module_sha256'] as $key) {
            abort_unless(is_string($a[$key]) && preg_match('/^[a-f0-9]{64}$/D', $a[$key]), 422);
        }
        abort_unless(is_string($a['build_id']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $a['build_id'])
            && is_string($a['expires_at']) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $a['expires_at'])
            && gmdate('Y-m-d\TH:i:s\Z', strtotime($a['expires_at']) ?: 0) === $a['expires_at'], 422);
        abort_if(now()->timestamp >= strtotime($a['expires_at']), 410);

        return $a;
    }

    private function runtime(array $approval, int $tenant): void
    {
        abort_unless(config('enterprise.runtime') === true && config('service_proposals.runtime_purpose') === 'proposal_owner', 404);
        $installed = $this->silos->assertReady();
        abort_unless($approval['tenant_id'] === $tenant && (int) $installed->tenant_id === $tenant
            && $installed->client_id === $approval['client_id'] && $installed->silo_id === $approval['silo_id']
            && config('service_proposals.owner_client_id') === $approval['client_id']
            && config('service_proposals.owner_silo_id') === $approval['silo_id']
            && config('enterprise_client.storage_id') === $approval['storage_id']
            && config('service_proposals.owner_workspaces') === [$tenant => 'enterprise'], 404);
    }

    private function freshOwner(Request $request, int $tenant): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $user = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        $member = DB::table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $actor->getKey())->lockForUpdate()->first();
        abort_unless($user !== null && $user->is_active === true && $user->email_verified_at !== null
            && $member !== null && in_array($member->role, ['owner', 'admin'], true)
            && in_array($member->membership_active, [true, 1, '1'], true), 403);
    }

    /** Fixed trusted bytes only. Admission and rendering share this exact checked snapshot; never reread for CSP. */
    public static function script(): string
    {
        $parts = [];
        foreach (['legacy-capture.js', 'legacy-capture-ui.js'] as $file) {
            $bytes = file_get_contents(resource_path('js/enterprise/reviewer/'.$file), false, null, 0, 65537);
            abort_unless(is_string($bytes) && strlen($bytes) <= 65536, 503);
            $parts[] = $bytes;
        }

        return $parts[0]."\n".$parts[1]."\nmountLegacyCapture(document, window, captureLegacySample);\n";
    }

    public static function keys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }
}
