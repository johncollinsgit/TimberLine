<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Illuminate\Http\Request;

/** Proposal capability only. This supplies no paid admission, native User, membership or ledger initialization. */
final class ReviewerAiAccess
{
    public function __construct(private Access $access, private CampusReviewManifest $manifests) {}

    public function scope(Request $request, string $token, ?array $expected = null, bool $startBrowser = false): array
    {
        abort_unless(config('service_proposals.reviewer_ai.ui_enabled') === true, 404);
        abort_unless(config('enterprise.runtime') === true && config('service_proposals.runtime_purpose') === 'proposal_owner' && MountUrls::matches($request), 404);
        abort_if($request->query->count() !== 0, 400);
        $version = $this->access->recipient($request, $token);
        abort_unless($this->access->unlocked($request, $version), 403, 'Unlock this exact proposal version first.');
        $manifest = $this->manifests->assertCurrent($version, 'ask');
        if ($expected !== null) {
            $keys = array_keys($expected);
            sort($keys);
            abort_unless($keys === ['build_id', 'source_hash', 'version_hash'], 422, 'Only the exact proposal build binding is accepted.');
        }
        foreach (['version_hash' => $version->content_hash, 'build_id' => $manifest['build_id'], 'source_hash' => $manifest['source_hash']] as $key => $value) {
            abort_unless($expected === null || is_string($expected[$key]) && hash_equals($value, $expected[$key]), 409,
                'The private demo build changed. Review a fresh snapshot.');
        }
        abort_unless($request->hasSession() && $request->session()->getId() !== '', 403);
        $key = 'service_proposal_ai_browser.'.$version->id;
        $nonce = $request->session()->get($key);
        if ($startBrowser && ! is_string($nonce)) {
            $nonce = bin2hex(random_bytes(32));
            $request->session()->put($key, $nonce);
        }
        abort_unless(is_string($nonce) && preg_match('/^[a-f0-9]{64}$/D', $nonce), 403, 'The original private demo browser is required.');

        return ['version' => $version, 'manifest' => $manifest,
            'browser_hash' => hash('sha256', 'proposal-review-ai-v1|'.$version->id.'|'.$version->content_hash.'|'.$manifest['build_id'].'|'.$manifest['source_hash'].'|'.MountUrls::context().'|'.$request->session()->getId().'|'.$nonce)];
    }
}
