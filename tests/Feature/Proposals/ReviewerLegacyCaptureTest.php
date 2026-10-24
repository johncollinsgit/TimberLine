<?php

namespace Tests\Feature\Proposals;

use App\Enterprise\Provisioning\ClientSilo;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\User;
use App\Proposals\CampusReviewManifest;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\Version;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReviewerAiFixture;

/** Actual mounted private fixture, real password login/session/CSRF. No actingAs or principal injection. */
final class ReviewerLegacyCaptureTest extends ReviewerAiFixture
{
    private User $owner;

    private Version $version;

    private array $cookies = [];

    private array $approval;

    private array $pins;

    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        // This capture has NO anchor/ledger dependency; remove the unrelated base fixture rows.
        DB::table('team_operation_records')->delete();
        DB::table('team_areas')->where('id', $this->anchor->id)->delete();
        config(['service_proposals.campus_review.enabled' => true, 'service_proposals.campus_review.build_id' => 'FICTIONAL-local-capture-v1',
            'service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false,
            'service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
            'cache.limiter' => 'array', 'app.debug' => true]);
        $this->app->bind(EnterpriseCsrfToken::class, RealLegacyCaptureTestCsrf::class);
        $this->owner = User::query()->create(['name' => 'FICTIONAL CAPTURE OWNER', 'email' => 'capture@example.invalid', 'password' => Hash::make('FICTIONAL_CAPTURE_ONLY'), 'is_active' => true]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
        $this->publish();
    }

    private function publish(?array $descriptor = null): void
    {
        $service = app(ProposalService::class);
        $draft = $service->leesMcRaeDraft($this->tenant, $this->owner->id)->refresh();
        if ($descriptor !== null) {
            $draft->payload = $draft->payload + ['review_workspace' => $descriptor];
            $draft->save();
        }
        $result = $service->publish($this->tenant, $this->owner->id, $draft->id, (int) $draft->revision, 'FICTIONAL_RECIPIENT_CAPTURE', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $result['version'];
        $m = $this->version->payload['campus_review'];
        $this->approval = ['tenant_id' => (int) $this->tenant->id, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'storage_id' => config('enterprise_client.storage_id'), 'version_hash' => $this->version->content_hash, 'manifest_sha256' => ReviewerWorkspaceSchema::digest($m),
            'source_hash' => $m['source_hash'], 'build_id' => $m['build_id'], 'module_sha256' => hash('sha256', \App\Proposals\ReviewerLegacyCapture::script()),
            'expires_at' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        $this->configure();
    }

    private function configure(): void
    {
        config(['service_proposals.legacy_capture' => ['enabled' => true, 'approvals' => [$this->version->id => $this->approval]]]);
        $this->pins = array_intersect_key($this->approval, array_flip(['version_hash', 'manifest_sha256', 'source_hash', 'build_id']));
        $this->pins['approval_sha256'] = ReviewerWorkspaceSchema::digest($this->approval);
    }

    private function path(string $suffix = ''): string
    {
        return '/enterprise-owner/owner-workspaces/'.$this->tenant->slug.'/proposals/legacy-capture/'.$this->version->id.$suffix;
    }

    private function http(string $path, string $method = 'GET', ?array $body = null, array $extra = []): Response
    {
        $this->app['auth']->forgetGuards();
        $server = ['SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php',
            'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'];
        if ($body !== null) {
            $server += ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
                'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_CSRF_TOKEN' => $this->csrf ?? ''];
        }
        $request = Request::create('https://proposal.example.invalid'.$path, $method, [], $this->cookies, [], array_replace($server, $extra), $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $kernel->terminate($request, $response);

        return $response;
    }

    private function login(): void
    {
        $page = $this->http('/enterprise-owner/login');
        $this->assertSame(200, $page->getStatusCode());
        preg_match('/name="_token"[^>]*value="([^"]+)"/', $page->getContent(), $m);
        $this->csrf = html_entity_decode($m[1] ?? '');
        $this->assertNotSame('', $this->csrf);
        $login = $this->http('/enterprise-owner/login', 'POST', ['email' => $this->owner->email, 'password' => 'FICTIONAL_CAPTURE_ONLY']);
        $this->assertSame(200, $login->getStatusCode(), $login->getContent());
        $this->assertStringContainsString($this->tenant->name, $login->getContent());
        $page = $this->http('/enterprise-owner/login');
        preg_match('/name="_token"[^>]*value="([^"]+)"/', $page->getContent(), $m);
        $this->csrf = html_entity_decode($m[1] ?? '');
        $this->assertNotSame('', $this->csrf);
    }

    private function rows(): array
    {
        $out = [];
        foreach (['users', 'tenant_user', 'service_proposal_versions', 'service_proposal_drafts', 'service_proposal_comments', 'team_areas', 'team_operation_records'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $out;
    }

    public function test_real_login_get_is_storage_free_and_closed_authorize_preserves_all_rows_and_exact_helper(): void
    {
        $this->login();
        $before = $this->rows();
        $page = $this->http($this->path());
        $this->assertSame(200, $page->getStatusCode(), $page->getContent());
        $this->assertStringContainsString('Download this tab’s edits', $page->getContent());
        $this->assertStringNotContainsString('sample-adapter.js', $page->getContent());
        $this->assertStringContainsString(file_get_contents(resource_path('js/enterprise/reviewer/legacy-capture.js')), $page->getContent());
        $this->assertStringNotContainsString("'unsafe-", $page->headers->get('Content-Security-Policy'));
        preg_match('~<script type="module">(.*?)</script>~s', $page->getContent(), $script);
        $this->assertStringContainsString("'sha256-".base64_encode(hash('sha256', $script[1], true))."'", $page->headers->get('Content-Security-Policy'));
        $this->assertSame($this->approval['module_sha256'], hash('sha256', $script[1]), 'Only the exact admitted module snapshot is rendered.');
        // Real session token regenerated by login, delivered through only private owner page.
        preg_match('~<script id="capture-context" type="application/json">(.*?)</script>~s', $page->getContent(), $boot);
        $this->csrf = json_decode($boot[1], true, 8, JSON_THROW_ON_ERROR)['csrf'];
        $out = $this->http($this->path('/authorize'), 'POST', array_reverse($this->pins, true));
        $this->assertSame(200, $out->getStatusCode(), $out->getContent());
        $data = json_decode($out->getContent(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($this->version->id, $data['capture_binding']['observed_version_id']);
        $this->assertSame($this->pins['version_hash'], $data['capture_binding']['observed_version_hash']);
        $this->assertLessThanOrEqual(60, strtotime($data['deadline']) - strtotime($data['capture_binding']['observed_at']));
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::table('team_operation_records')->count());
        $this->assertSame('ea305f02a19ba49371aa297107e8ab35317b9424945fe123f4585cc9163b4608', hash_file('sha256', resource_path('js/enterprise/reviewer/legacy-capture.js')));
    }

    public function test_guest_get_preserves_login_redirect_and_guest_post_is_private_no_debug_content(): void
    {
        $out = $this->http($this->path());
        $this->assertSame(302, $out->getStatusCode());
        $this->assertStringEndsWith('/enterprise-owner/login', $out->headers->get('Location'));
        $out = $this->http($this->path('/authorize'), 'POST', $this->pins);
        $this->assertContains($out->getStatusCode(), [401, 419]);
        $this->assertSame(['error' => 'capture_unavailable'], json_decode($out->getContent(), true));
        $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
    }

    public function test_actual_csrf_origin_closed_input_method_and_throttle_guards_have_sanitized_errors(): void
    {
        $this->login();
        $before = $this->rows();
        $out = $this->http($this->path('/authorize'), 'POST', $this->pins, ['HTTP_X_CSRF_TOKEN' => 'wrong']);
        $this->assertSame(419, $out->getStatusCode());
        $this->assertSame(403, $this->http($this->path('/authorize'), 'POST', $this->pins, ['HTTP_ORIGIN' => 'https://foreign.invalid'])->getStatusCode());
        $this->assertSame(422, $this->http($this->path('/authorize'), 'POST', $this->pins + ['raw' => 'UNTRUSTED_RAW_SENTINEL'])->getStatusCode());
        $this->assertSame(405, $this->http($this->path('/authorize'))->getStatusCode());
        $out = $this->http($this->path('/authorize'), 'POST', $this->pins, ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->assertSame(403, $out->getStatusCode());
        for ($i = 0; $i < 6; $i++) {
            $out = $this->http($this->path('/authorize'), 'POST', $this->pins);
        }
        $this->assertSame(429, $out->getStatusCode());
        $this->assertSame(['error' => 'capture_unavailable'], json_decode($out->getContent(), true));
        $this->assertSame($before, $this->rows());
    }

    public function test_fresh_database_role_user_and_verification_loss_cannot_reuse_owner_login(): void
    {
        $this->login();
        foreach ([['membership_active' => false], ['role' => 'employee'], ['role' => 'owner', 'membership_active' => true]] as $change) {
            DB::table('tenant_user')->where('user_id', $this->owner->id)->update($change);
            if (($change['membership_active'] ?? false) === true) {
                continue;
            }
            $this->assertSame(403, $this->http($this->path('/authorize'), 'POST', $this->pins)->getStatusCode());
        }
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
        $this->assertSame(403, $this->http($this->path('/authorize'), 'POST', $this->pins)->getStatusCode());
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => true, 'email_verified_at' => null]);
        $this->assertSame(403, $this->http($this->path('/authorize'), 'POST', $this->pins)->getStatusCode());
    }

    public function test_disabled_expired_revoked_source_map_and_silo_close_without_capture_writes(): void
    {
        $this->login();
        $before = $this->rows();
        config(['service_proposals.legacy_capture.enabled' => false]);
        $this->assertSame(404, $this->http($this->path())->getStatusCode());
        $this->configure();
        $this->approval['source_hash'] = str_repeat('b', 64);
        $this->configure();
        $this->assertSame(409, $this->http($this->path())->getStatusCode());
        $this->approval['source_hash'] = $this->version->payload['campus_review']['source_hash'];
        $this->approval['storage_id'] = (string) Str::uuid();
        $this->configure();
        $this->assertSame(404, $this->http($this->path())->getStatusCode());
        $this->approval['storage_id'] = config('enterprise_client.storage_id');
        $this->approval['expires_at'] = now()->subSecond()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->configure();
        $this->assertSame(410, $this->http($this->path())->getStatusCode());
        $this->assertSame($before, $this->rows());
        $this->approval['expires_at'] = now()->addMinute()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->configure();
        $this->version->update(['revoked_at' => now()]);
        $this->assertSame(410, $this->http($this->path())->getStatusCode());
    }

    public function test_any_shared_descriptor_is_refused_and_registered_capture_routes_retain_session_lock(): void
    {
        // Test setup only, explicit new immutable synthetic publication, no runtime capture permission implied.
        $this->publish(['schema_version' => 1, 'workspace_id' => (string) Str::uuid(), 'seed_sha256' => str_repeat('a', 64), 'policy_sha256' => str_repeat('b', 64), 'expires_at' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')]);
        $this->login();
        $this->assertSame(404, $this->http($this->path())->getStatusCode());
        foreach (['show', 'authorize'] as $name) {
            $this->assertSame(10, $this->app['router']->getRoutes()->getByName('service-proposals.owner.legacy-capture.'.$name)->locksFor());
        }
    }

    public function test_real_registered_legacy_v5_edition_is_observational_only_and_unsupported_null_descriptor_is_denied(): void
    {
        $payload = $this->version->payload;
        $payload['campus_review'] = (new \App\Proposals\LegacyCampusArtifact)->manifest();
        $this->syntheticVersion($payload);
        config(['service_proposals.campus_review.legacy_versions' => [$this->version->id => ['tenant_id' => (int) $this->tenant->id,
            'content_hash' => $this->version->content_hash, 'renderer' => 'lees-mcrae-v5']]]);
        $this->login();
        $before = $this->rows();
        $this->assertSame(200, $this->http($this->path())->getStatusCode());
        $out = $this->http($this->path('/authorize'), 'POST', $this->pins);
        $this->assertSame(200, $out->getStatusCode(), $out->getContent());
        $this->assertSame($before, $this->rows());
        $payload = $this->version->payload + ['review_workspace' => null];
        $this->syntheticVersion($payload);
        $this->assertSame(404, $this->http($this->path())->getStatusCode());
    }

    private function syntheticVersion(array $payload): void
    {
        // Explicit local immutable fixture, never an application import/publication path.
        $this->version = Version::query()->create(['tenant_id' => $this->tenant->id, 'draft_id' => $this->version->draft_id,
            'number' => Version::query()->max('number') + 1, 'payload' => $payload, 'content_hash' => app(ProposalService::class)->hash($payload),
            'token_hash' => hash('sha256', (string) Str::uuid()), 'password_hash' => Hash::make('FICTIONAL_RECIPIENT_CAPTURE'),
            'expires_at' => now()->addDay(), 'created_by' => $this->owner->id, 'publish_key' => hash('sha256', (string) Str::uuid()), 'access_result' => []]);
        $m = $payload['campus_review'];
        $this->approval = array_replace($this->approval, ['version_hash' => $this->version->content_hash, 'manifest_sha256' => ReviewerWorkspaceSchema::digest($m),
            'source_hash' => $m['source_hash'], 'build_id' => $m['build_id']]);
        $this->configure();
    }

    public function test_current_source_drift_and_malformed_approval_do_not_fall_back_to_registered_or_other_editions(): void
    {
        $this->login();
        config(['service_proposals.campus_review.build_id' => 'FICTIONAL-changed-build']);
        $this->assertSame(409, $this->http($this->path())->getStatusCode());
        config(['service_proposals.campus_review.build_id' => $this->approval['build_id']]);
        $this->approval['unexpected'] = 'UNTRUSTED';
        $this->configure();
        $this->assertSame(404, $this->http($this->path())->getStatusCode());
        unset($this->approval['unexpected']);
        $this->configure();
        config(['service_proposals.legacy_capture.approvals' => [$this->version->id => $this->approval, (string) Str::uuid() => $this->approval]]);
        $this->assertSame(404, $this->http($this->path())->getStatusCode());
    }

    public function test_last_fresh_authority_after_manifest_io_refuses_owner_loss(): void
    {
        $this->login();
        $id = $this->owner->id;
        $this->app->instance(CampusReviewManifest::class, new class($id) extends CampusReviewManifest
        {
            public function __construct(private int $actor) {}

            public function resolveReviewEdition(Version $version, string $page): array
            {
                $edition = parent::resolveReviewEdition($version, $page);
                DB::table('tenant_user')->where('user_id', $this->actor)->update(['membership_active' => false]);

                return $edition;
            }
        });
        $before = $this->rows();
        $out = $this->http($this->path('/authorize'), 'POST', $this->pins);
        $this->assertSame(403, $out->getStatusCode());
        // The simulated concurrent revocation is itself rolled back by the read-only transaction's refusal.
        $this->assertSame($before, $this->rows());
    }

    public function test_registered_legacy_ui_module_drift_refuses_get_before_storage_page_is_rendered(): void
    {
        $this->assertUiModuleDriftRefused('GET');
    }

    public function test_registered_legacy_ui_module_drift_refuses_authorization_post(): void
    {
        $this->assertUiModuleDriftRefused('POST');
    }

    private function assertUiModuleDriftRefused(string $method): void
    {
        \Tests\Support\ReviewerWorkspaceSourceSnapshot::run(function () use ($method): void {
            $payload = $this->version->payload;
            $payload['campus_review'] = (new \App\Proposals\LegacyCampusArtifact)->manifest();
            $this->syntheticVersion($payload);
            config(['service_proposals.campus_review.legacy_versions' => [$this->version->id => ['tenant_id' => (int) $this->tenant->id,
                'content_hash' => $this->version->content_hash, 'renderer' => 'lees-mcrae-v5']]]);
            $this->login();
            $path = resource_path('js/enterprise/reviewer/legacy-capture-ui.js');
            $original = file_get_contents($path);
            $before = $this->rows();
            try {
                file_put_contents($path, $original."\n// Harmless fixture-only byte drift; no human storage access.\n");
                $this->assertSame('ea305f02a19ba49371aa297107e8ab35317b9424945fe123f4585cc9163b4608', hash_file('sha256', resource_path('js/enterprise/reviewer/legacy-capture.js')));
                $out = $method === 'GET' ? $this->http($this->path()) : $this->http($this->path('/authorize'), 'POST', $this->pins);
                $this->assertSame(409, $out->getStatusCode(), 'Unapproved exact concatenated UI bytes must refuse, including registered legacy editions.');
                $this->assertSame(['error' => 'capture_unavailable'], json_decode($out->getContent(), true));
                $this->assertSame($before, $this->rows());
            } finally {
                file_put_contents($path, $original);
            }
        });
    }

    public function test_marker_and_outside_boundary_behaviour_are_preserved(): void
    {
        $this->login();
        $path = app(ClientSilo::class)->markerPath();
        $original = file_get_contents($path);
        unlink($path);
        try {
            $out = $this->http($this->path());
            $this->assertSame(503, $out->getStatusCode());
            $this->assertSame(['error' => 'capture_unavailable'], json_decode($out->getContent(), true));
        } finally {
            file_put_contents($path, $original);
        }
        $this->assertSame(200, $this->http('/enterprise-owner/login')->getStatusCode());
    }
}

/** Only override the testing bypass: normal real token comparison remains active for every mounted login/capture request. */
final class RealLegacyCaptureTestCsrf extends EnterpriseCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
