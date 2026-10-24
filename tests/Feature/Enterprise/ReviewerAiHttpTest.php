<?php

namespace Tests\Feature\Enterprise;

use App\Proposals\Access;
use App\Proposals\CampusReviewManifest;
use App\Proposals\ReviewerAiController;
use App\Proposals\ReviewerAiHttpPolicy;
use App\Proposals\Version;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReviewerAiConnectionFixture;

final class ReviewerAiHttpTest extends ReviewerAiConnectionFixture
{
    private function request(string $body = '{}', array $headers = []): Request
    {
        $request = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/ask/ai/status', 'POST', [], [], [],
            ['SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/fictional/index.php', 'CONTENT_TYPE' => 'application/json'], $body);
        $session = new Store('fictional-reviewer-http', new ArraySessionHandler(120));
        $session->start();
        $session->put('_token', 'FICTIONAL_CSRF_TOKEN_ONLY');
        $request->setLaravelSession($session);
        foreach ($headers + ['X-CSRF-TOKEN' => 'FICTIONAL_CSRF_TOKEN_ONLY'] as $key => $value) {
            $request->headers->set($key, $value);
        }

        return $request;
    }

    public function test_closed_json_and_explicit_csrf_reject_unknown_nested_shapes_forms_queries_oversize_and_missing_token_before_access(): void
    {
        $policy = app(ReviewerAiHttpPolicy::class);
        $fields = ['binding', 'preview_id', 'request_id', 'digest', 'snapshot_digest'];
        $valid = ['binding' => ['version_hash' => 'x'], 'preview_id' => 'x', 'request_id' => 'x', 'digest' => 'x', 'snapshot_digest' => 'x'];
        $this->assertSame($valid, $policy->body($this->request(json_encode($valid)), $fields));
        $bad = [
            [$this->request(json_encode($valid + ['user_id' => 1])), 422],
            [$this->request('[]'), 422], [$this->request('null'), 422],
            [$this->request(str_repeat('x', 65537)), 400],
            [$this->request(json_encode($valid), ['Content-Type' => 'application/x-www-form-urlencoded']), 400],
            [$this->request(json_encode($valid), ['X-CSRF-TOKEN' => 'WRONG']), 419],
        ];
        $query = $this->request(json_encode($valid));
        $query->query->set('tenant_id', 2);
        $bad[] = [$query, 400];
        $missing = $this->request(json_encode($valid));
        $missing->headers->remove('X-CSRF-TOKEN');
        $bad[] = [$missing, 419];
        foreach ($bad as [$request, $status]) {
            try {
                $policy->body($request, $fields);
                $this->fail('Unsafe request must fail before Access or storage.');
            } catch (HttpException $failure) {
                $this->assertSame($status, $failure->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    public function test_browser_and_ip_rate_limits_do_not_reset_by_changing_request_ids_or_browser_sessions(): void
    {
        $scope = $this->configuredConnection();
        $policy = app(ReviewerAiHttpPolicy::class);
        $request = $this->request();
        for ($i = 0; $i < 6; $i++) {
            $policy->rate($request, $scope, 'preview');
        }
        try {
            $policy->rate($request, $scope, 'preview');
            $this->fail('Seventh preview cannot pass.');
        } catch (HttpException $failure) {
            $this->assertSame(429, $failure->getStatusCode());
        }
        for ($i = 0; $i < 34; $i++) {
            $scope['browser_hash'] = hash('sha256', 'different-browser-'.$i);
            $policy->rate($request, $scope, 'status');
        }
        $scope['browser_hash'] = hash('sha256', 'last-browser');
        try {
            $policy->rate($request, $scope, 'status');
            $this->fail('IP ceiling must survive browser resets.');
        } catch (HttpException $failure) {
            $this->assertSame(429, $failure->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_controller_sanitizes_all_failures_and_returns_private_noncacheable_json_even_when_debug_is_enabled(): void
    {
        $this->configuredConnection();
        config(['app.debug' => true]);
        $response = app(ReviewerAiController::class)->status($this->request('{not valid json'), 'PRIVATE_URL_TOKEN_SENTINEL');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['error' => 'invalid_request', 'provider_redispatch' => false], $response->getData(true));
        foreach (['PRIVATE_URL_TOKEN_SENTINEL', 'trace', 'exception', 'api_key'] as $text) {
            $this->assertStringNotContainsString($text, $response->getContent());
        }
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $missingCsrf = $this->request('{}');
        $missingCsrf->headers->remove('X-CSRF-TOKEN');
        $this->assertSame(419, app(ReviewerAiController::class)->status($missingCsrf, 'FICTIONAL')->getStatusCode());
    }

    public function test_controller_preview_status_cancel_and_delayed_confirm_match_frontend_protocol_in_original_browser(): void
    {
        $scope = $this->configuredConnection();
        app()->instance(Access::class, new class($scope['version']) extends Access
        {
            public function __construct(private Version $version) {}

            public function recipient(Request $request, string $token): Version
            {
                return $this->version;
            }

            public function unlocked(Request $request, Version $version): bool
            {
                return true;
            }
        });
        app()->instance(CampusReviewManifest::class, new class($scope['manifest']) extends CampusReviewManifest
        {
            public function __construct(private array $manifest) {}

            public function assertCurrent(Version $version, string $page): array
            {
                return $this->manifest;
            }
        });
        $binding = ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']];
        $snapshot = ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 1,
            'area_ids' => ['73000000-0000-4000-8000-000000000001'], 'sources' => [['ref' => 'project:75000000-0000-4000-8000-000000000001',
                'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'Fictional job', 'version' => 1,
                'status' => 'active', 'tasks' => [], 'buildings' => []]], 'coverage' => \App\Proposals\ReviewerAiSnapshot::COVERAGE];
        $request = $this->request(json_encode(['binding' => $binding, 'snapshot' => $snapshot, 'question' => 'Fictional question', 'request_id' => (string) \Illuminate\Support\Str::uuid()]));
        $controller = app(ReviewerAiController::class);
        $previewResponse = $controller->preview($request, 'FICTIONAL');
        $this->assertSame(200, $previewResponse->getStatusCode());
        $preview = $previewResponse->getData(true);
        $this->assertSame('Fictional question', $preview['question']);
        $this->assertArrayNotHasKey('preview', $preview);
        $receipt = ['binding' => $binding, 'preview_id' => $preview['id'], 'request_id' => $preview['request_id'], 'digest' => $preview['digest'], 'snapshot_digest' => $preview['snapshot_digest']];
        $next = $this->request(json_encode($receipt));
        $otherBrowser = $controller->status($next, 'FICTIONAL');
        $this->assertSame(403, $otherBrowser->getStatusCode());
        $next->setLaravelSession($request->session());
        $status = $controller->status($next, 'FICTIONAL');
        $this->assertSame('prepared', $status->getData(true)['status']);
        $this->assertSame($receipt['digest'], $status->getData(true)['digest']);
        // A second independent, legitimately unlocked session can start its own AI request.
        // Its receipt fence is not a physical computer or User-Agent allowlist.
        $secondRequest = $this->request(json_encode(['binding' => $binding, 'snapshot' => $snapshot, 'question' => 'Second client question',
            'request_id' => (string) \Illuminate\Support\Str::uuid()]), ['User-Agent' => 'Synthetic mobile Safari']);
        $secondResponse = $controller->preview($secondRequest, 'FICTIONAL');
        $this->assertSame(200, $secondResponse->getStatusCode());
        $secondPreview = $secondResponse->getData(true);
        $this->assertNotSame($preview['id'], $secondPreview['id']);
        $secondReceipt = ['binding' => $binding, 'preview_id' => $secondPreview['id'], 'request_id' => $secondPreview['request_id'],
            'digest' => $secondPreview['digest'], 'snapshot_digest' => $secondPreview['snapshot_digest']];
        $secondStatus = $this->request(json_encode($secondReceipt));
        $secondStatus->setLaravelSession($secondRequest->session());
        $this->assertSame('prepared', $controller->status($secondStatus, 'FICTIONAL')->getData(true)['status']);
        $crossed = $this->request(json_encode($receipt));
        $crossed->setLaravelSession($secondRequest->session());
        $this->assertSame(404, $controller->status($crossed, 'FICTIONAL')->getStatusCode());
        $cancelled = $controller->cancel($next, 'FICTIONAL')->getData(true);
        $this->assertSame('cancelled', $cancelled['status']);
        $keys = array_keys($cancelled);
        sort($keys);
        $this->assertSame(['content_expired', 'digest', 'preview_id', 'request_id', 'snapshot_digest', 'status'], $keys);
        $this->assertSame($cancelled, $controller->confirm($next, 'FICTIONAL')->getData(true));
        Http::assertNothingSent();
    }

    public function test_unexpected_access_failure_never_echoes_secret_exception_or_creates_native_identity(): void
    {
        $scope = $this->configuredConnection();
        app()->instance(Access::class, new class extends Access
        {
            public function recipient(Request $request, string $token): Version
            {
                throw new \RuntimeException('SECRET_PROVIDER_SENTINEL sk-PRIVATE invalid SQL');
            }
        });
        app()->instance(CampusReviewManifest::class, new class extends CampusReviewManifest
        {
            public function assertCurrent(Version $version, string $page): array
            {
                return [];
            }
        });
        $body = ['binding' => ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']],
            'preview_id' => 'x', 'request_id' => 'x', 'digest' => 'x', 'snapshot_digest' => 'x'];
        $request = $this->request(json_encode($body));
        $this->assertSame('/enterprise-owner', $request->getBaseUrl());
        $response = app(ReviewerAiController::class)->status($request, 'FICTIONAL');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['error' => 'reconciliation_required', 'provider_redispatch' => false], $response->getData(true));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('users')->count());
        Http::assertNothingSent();
    }
}
