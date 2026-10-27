<?php

namespace Tests\Feature\Enterprise;

use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\TeamOperationRecord;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerAiConnection;
use App\Proposals\ReviewerAiResponses;
use App\Proposals\ReviewerAiSnapshot;
use App\Proposals\Version;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReviewerAiConnectionFixture;

/** Real mounted kernel/Access/publication/approval; only provider HTTP is fake. */
final class ReviewerAiIntegrationTest extends ReviewerAiConnectionFixture
{
    private array $jar = [];

    private string $token;

    private Version $version;

    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite is intentionally testing-only; enforce the production CSRF branch instead of Laravel's unit-test exemption.
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        config(['session.cookie' => 'everbranch_enterprise_session', 'session.domain' => null, 'session.secure' => true, 'session.http_only' => true,
            'service_proposals.publish_enabled' => true, 'service_proposals.writes_enabled' => true,
            'service_proposals.recipient_hosts' => ['proposal.example.invalid'], 'enterprise.reviewer_demo_enabled' => true,
            'tenancy.domains.canonical.landlord_host' => 'proposal.example.invalid',
            'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'fictional-integration-ai-edition']]);
        $this->configuredConnection();
        $owner = User::query()->create(['name' => 'FICTIONAL OPERATOR', 'email' => 'operator@example.invalid',
            'password' => Hash::make('FICTIONAL OWNER TEST ONLY'), 'is_active' => true, 'email_verified_at' => now()]);
        // Publication is explicit test setup. The recipient never signs in as this operator or obtains a membership.
        $service = app(ProposalService::class);
        $draft = $service->leesMcRaeDraft($this->tenant, $owner->id);
        $published = $service->publish($this->tenant, $owner->id, $draft->id, 1, 'FICTIONAL RECIPIENT PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $manifest = $this->version->payload['campus_review'];
        $config = config('service_proposals.reviewer_ai');
        $config['version_id'] = $this->version->id;
        $config['version_hash'] = $this->version->content_hash;
        $config['build_id'] = $manifest['build_id'];
        $config['source_hash'] = $manifest['source_hash'];
        $config['approval_sha256'] = hash('sha256', json_encode(ReviewerAiConnection::approvalFields($config), JSON_THROW_ON_ERROR));
        config(['service_proposals.reviewer_ai' => $config]);
    }

    protected function tearDown(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        parent::tearDown();
    }

    private function callMounted(string $method, string $path, ?array $body = null, bool $json = false, ?string $csrf = null, array $server = []): Response
    {
        $prefix = '/enterprise-owner';
        $server += ['SCRIPT_NAME' => $prefix.'/index.php', 'PHP_SELF' => $prefix.'/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php',
            'HTTPS' => 'on', 'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html'];
        if ($json) {
            $server['CONTENT_TYPE'] = 'application/json';
            if ($csrf !== null) {
                $server['HTTP_X_CSRF_TOKEN'] = $csrf;
            }
        }
        $request = Request::create('https://proposal.example.invalid'.$prefix.$path, $method, $json ? [] : ($body ?? []), $this->jar, [], $server,
            $json ? json_encode($body ?? [], JSON_THROW_ON_ERROR) : null);
        $this->assertSame($prefix, $request->getBaseUrl());
        // The array handler retains saved sessions; clear only the reusable Store's in-memory attributes between HTTP requests.
        $this->app['session']->driver()->flush();
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->jar[$cookie->getName()] = $cookie->getValue();
        }
        $kernel->terminate($request, $response);

        return $response;
    }

    private function path(string $suffix = ''): string
    {
        return '/service-proposals/'.$this->token.$suffix;
    }

    private function unlock(): Response
    {
        $locked = $this->callMounted('GET', $this->path());
        $this->assertSame(200, $locked->getStatusCode());
        preg_match('/name="_token"\s+value="([^"]+)"/', $locked->getContent(), $match);
        $this->assertArrayHasKey(1, $match);
        $unlocked = $this->callMounted('POST', $this->path('/unlock'), ['_token' => html_entity_decode($match[1]), 'password' => 'FICTIONAL RECIPIENT PASSWORD']);
        $this->assertSame(302, $unlocked->getStatusCode());
        $page = $this->callMounted('GET', $this->path('/campus/ask'));
        $this->assertSame(200, $page->getStatusCode(), substr($page->getContent(), 0, 600));
        preg_match('/"csrf_token":"([A-Za-z0-9]+)"/', $page->getContent(), $meta);
        $this->assertArrayHasKey(1, $meta);
        $this->csrf = html_entity_decode($meta[1]);

        return $page;
    }

    private function binding(): array
    {
        $manifest = $this->version->payload['campus_review'];

        return ['version_hash' => $this->version->content_hash, 'build_id' => $manifest['build_id'], 'source_hash' => $manifest['source_hash']];
    }

    private function previewBody(): array
    {
        return ['binding' => $this->binding(), 'request_id' => (string) Str::uuid(), 'question' => 'Which fictional work is active?',
            'snapshot' => ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 1,
                'area_ids' => ['73000000-0000-4000-8000-000000000001'], 'sources' => [['ref' => 'project:75000000-0000-4000-8000-000000000001',
                    'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'Fictional job', 'version' => 1,
                    'status' => 'active', 'tasks' => [], 'buildings' => []]], 'coverage' => ReviewerAiSnapshot::COVERAGE]];
    }

    private function receipt(array $preview): array
    {
        return ['binding' => $this->binding(), 'preview_id' => $preview['id'], 'request_id' => $preview['request_id'],
            'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest']];
    }

    private function data(Response $response, int $status = 200): array
    {
        $this->assertSame($status, $response->getStatusCode(), substr($response->getContent(), 0, 600));

        return json_decode($response->getContent(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function assertPrivate(Response $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        foreach (['sk-FICTIONAL', 'PRIVATE OWNER SENTINEL', 'SQLSTATE', 'Stack trace', 'SECRET_PROVIDER_SENTINEL'] as $secret) {
            $this->assertFalse(str_contains($response->getContent(), $secret), 'Private response must not contain sentinel or diagnostic: '.$secret);
        }
    }

    public function test_actual_private_page_boot_is_nonsecret_scoped_and_rendering_changes_no_grants_ledger_or_provider_state(): void
    {
        $before = [User::count(), DB::table('tenant_user')->count(), DB::table('team_area_members')->count(),
            DB::table('team_operation_records')->get()->toJson()];
        $page = $this->unlock();
        $this->assertTrue(str_contains($page->getContent(), 'id="proposal-ai-form"'), 'The actual private rendered page must include chat composer.');
        $this->assertTrue(str_contains($page->getContent(), 'Review fields for AI'), 'Explicit preview control is present.');
        preg_match('/EverbranchCampusReviewBoot\s*=\s*(\{[^\n]+\});/', $page->getContent(), $bootMatch);
        $this->assertArrayHasKey(1, $bootMatch);
        $boot = json_decode($bootMatch[1], true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('https://proposal.example.invalid/enterprise-owner'.$this->path('/campus/ask/ai/preview'), $boot['reviewer_ai']['endpoints']['preview']);
        $this->assertTrue(str_contains($page->getContent(), 'EverbranchProposalAiClient'), 'Private AI client is server-included only after exact approval.');
        $this->assertPrivate($page);
        if (getenv('REVIEWER_AI_EXPORT_BROWSER_FIXTURE') === '1') {
            $directory = dirname(base_path()).'/integration-browser';
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/page.html', $page->getContent());
            file_put_contents($directory.'/fixture.json', json_encode(['url' => 'https://proposal.example.invalid/enterprise-owner'.$this->path('/campus/ask'),
                'csp' => $page->headers->get('Content-Security-Policy'), 'binding' => $this->binding()], JSON_THROW_ON_ERROR));
        }
        $public = app(\App\Enterprise\ReviewerPresentation::class)->respond(Request::create('https://proposal.example.invalid/platform/demos/lees-mcrae'));
        $this->assertStringContainsString('Coming soon', $public->getContent());
        $this->assertStringNotContainsString('id="proposal-ai-form"', $public->getContent());
        $this->assertStringNotContainsString('EverbranchProposalAiClient', $public->getContent());
        $this->assertSame($before, [User::count(), DB::table('tenant_user')->count(), DB::table('team_area_members')->count(),
            DB::table('team_operation_records')->get()->toJson()]);
        $this->assertFalse(auth()->check());
        Http::assertNothingSent();
    }

    public function test_real_routes_enforce_csrf_closed_body_exact_version_and_cancel_blocks_delayed_confirm(): void
    {
        $this->unlock();
        $body = $this->previewBody();
        $url = $this->path('/campus/ask/ai/preview');
        $missing = $this->callMounted('POST', $url, $body, true);
        $this->data($missing, 419);
        $this->assertPrivate($missing);
        $this->data($this->callMounted('POST', $url, $body + ['user_id' => 1], true, $this->csrf), 422);
        $wrong = $body;
        $wrong['binding']['source_hash'] = str_repeat('f', 64);
        $this->data($this->callMounted('POST', $url, $wrong, true, $this->csrf), 409);
        $preview = $this->data($this->callMounted('POST', $url, $body, true, $this->csrf));
        $receipt = $this->receipt($preview);
        $this->assertSame('prepared', $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/status'), $receipt, true, $this->csrf))['status']);
        $cancelled = $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/cancel'), $receipt, true, $this->csrf));
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertTrue($cancelled['content_expired']);
        $this->assertSame($cancelled, $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/confirm'), $receipt, true, $this->csrf)));
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        Http::assertNothingSent();
    }

    public function test_paid_route_uses_one_fake_request_and_recovers_same_receipt_without_native_identity(): void
    {
        $this->unlock();
        $preview = $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/preview'), $this->previewBody(), true, $this->csrf));
        $receipt = $this->receipt($preview);
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel());

            return Http::response(['id' => 'resp_FICTIONAL_ONLY', 'model' => ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'total_tokens' => 1050],
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'answer' => 'The fictional job is active.', 'refs' => ['project:75000000-0000-4000-8000-000000000001']])]]]]]);
        });
        $result = $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/confirm'), $receipt, true, $this->csrf));
        $this->assertSame('accepted', $result['status']);
        $this->assertSame($receipt['digest'], $result['digest']);
        $this->assertSame($result, $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/status'), $receipt, true, $this->csrf)));
        $this->assertSame($result, $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/confirm'), $receipt, true, $this->csrf)));
        Http::assertSentCount(1);
        $this->assertFalse(auth()->check());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }

    public function test_another_unlocked_browser_and_revoked_version_cannot_reuse_the_original_receipt(): void
    {
        $this->unlock();
        $preview = $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/preview'), $this->previewBody(), true, $this->csrf));
        $receipt = $this->receipt($preview);
        $originalJar = $this->jar;
        $originalCsrf = $this->csrf;
        $this->jar = [];
        $this->unlock();
        $foreign = $this->callMounted('POST', $this->path('/campus/ask/ai/status'), $receipt, true, $this->csrf);
        $this->data($foreign, 404);
        $this->assertPrivate($foreign);
        $this->jar = $originalJar;
        $this->csrf = $originalCsrf;
        $this->assertSame('prepared', $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/status'), $receipt, true, $this->csrf))['status']);
        $this->version->forceFill(['revoked_at' => now()])->save();
        $revoked = $this->callMounted('POST', $this->path('/campus/ask/ai/confirm'), $receipt, true, $this->csrf);
        $this->data($revoked, 410);
        $this->assertPrivate($revoked);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        Http::assertNothingSent();
    }

    public function test_flags_off_public_and_native_pages_receive_no_private_chat_assets_and_middleware_errors_stay_private_in_debug(): void
    {
        $this->unlock();
        config(['service_proposals.reviewer_ai.enabled' => false]);
        $off = $this->callMounted('GET', $this->path('/campus/ask'));
        $this->assertSame(200, $off->getStatusCode());
        $this->assertStringContainsString('Coming soon', $off->getContent());
        $this->assertStringNotContainsString('id="proposal-ai-form"', $off->getContent());
        $this->assertStringNotContainsString('EverbranchProposalAiClient', $off->getContent());
        $native = $this->callMounted('GET', '/');
        $this->assertSame(302, $native->getStatusCode());
        $this->assertStringNotContainsString('proposal-ai-form', $native->getContent());
        config(['app.debug' => true, 'session.path' => '/WRONG_MOUNT']);
        $bad = $this->callMounted('POST', $this->path('/campus/ask/ai/preview'), $this->previewBody(), true, $this->csrf);
        $this->data($bad, 503);
        $this->assertPrivate($bad);
        $this->assertSame(['error' => 'reconciliation_required', 'provider_redispatch' => false], json_decode($bad->getContent(), true));
        Http::assertNothingSent();
    }

    public function test_equivalent_encoded_and_trailing_slash_routes_keep_csrf_failures_private(): void
    {
        $this->unlock();
        config(['app.debug' => true]);
        $encoded = '%'.strtoupper(bin2hex($this->token[0])).substr($this->token, 1);
        $encodedPath = '/service-proposals/'.$encoded.'/campus/ask/ai/preview';
        foreach ([$encodedPath, $encodedPath.'/', $this->path('/campus/ask/ai/preview/')] as $path) {
            $response = $this->callMounted('POST', $path, $this->previewBody(), true);
            $this->assertSame(['error' => 'access_required', 'provider_redispatch' => false], $this->data($response, 419));
            $this->assertPrivate($response);
        }
        $preview = $this->data($this->callMounted('POST', $encodedPath, $this->previewBody(), true, $this->csrf));
        $cancelled = $this->data($this->callMounted('POST', $this->path('/campus/ask/ai/cancel'), $this->receipt($preview), true, $this->csrf));
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        Http::assertNothingSent();
    }
}
