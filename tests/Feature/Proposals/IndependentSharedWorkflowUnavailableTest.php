<?php

namespace Tests\Feature\Proposals;

use App\Enterprise\ReviewerPresentation;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReviewerWorkspaceFixture;

final class IndependentSharedWorkflowUnavailableTest extends ReviewerWorkspaceFixture
{
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        config(['enterprise.reviewer_demo_enabled' => true, 'tenancy.domains.canonical.landlord_host' => 'proposal.example.invalid',
            'session.cookie' => 'everbranch_enterprise_session', 'session.domain' => null, 'session.secure' => true, 'session.http_only' => true]);
        $this->initialize();
    }

    private function mounted(string $method, string $path, array $body = []): Response
    {
        $request = Request::create('https://proposal.example.invalid/enterprise-owner'.$path, $method, $body, $this->jar, [], [
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php',
            'HTTPS' => 'on', 'HTTP_ACCEPT' => 'text/html']);
        $this->assertSame('/enterprise-owner', $request->getBaseUrl());
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

    private function unlock(): string
    {
        $this->assertSame(200, $this->mounted('GET', $this->path())->getStatusCode());
        $csrf = $this->app['session']->driver()->token();
        $this->assertIsString($csrf);
        $this->assertSame(302, $this->mounted('POST', $this->path('/unlock'), ['_token' => $csrf, 'password' => 'FICTIONAL_RECIPIENT_PASSWORD'])->getStatusCode());

        return $this->app['session']->driver()->token();
    }

    private function state(): array
    {
        return ['graph' => (array) DB::table('team_operation_records')->where('kind', ReviewerWorkspaceStore::KIND)->first(),
            'workspace_commands' => TeamOperationRecord::where('kind', ReviewerWorkspaceStore::COMMAND)->count(),
            'users' => DB::table('users')->get()->toJson(), 'tenant_user' => DB::table('tenant_user')->get()->toJson(),
            'area_members' => DB::table('team_area_members')->get()->toJson(),
            'ledger' => (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first()];
    }

    public function test_current_shared_workflows_and_related_review_actions_refuse_without_canvas_or_mutation(): void
    {
        $csrf = $this->unlock();
        $before = $this->state();
        $response = $this->mounted('GET', $this->path('/campus/workflows'));
        if ($response->getStatusCode() === 200) {
            preg_match('/id="enterprise-workflow" data-bootstrap="([^"]+)"/', $response->getContent(), $match);
            $boot = json_decode(html_entity_decode($match[1] ?? '{}', ENT_QUOTES, 'UTF-8'), true);
            fwrite(STDERR, 'SHARED_WORKFLOW_OBSERVATION '.json_encode(['status' => 200, 'has_shared_descriptor' => isset($boot['reviewerWorkspace']),
                'scope_project_id' => $boot['scope']['project_id'] ?? null, 'current_graph_project_id' => $this->ids['project'],
                'initial_title' => $boot['initial']['project']['name'] ?? null], JSON_THROW_ON_ERROR)."\n");
            $this->assertSame($before, $this->state(), 'A canvas render must not write shared/native data or ledger.');
        }
        $this->assertSame(404, $response->getStatusCode(), 'Unsupported shared workflows must not mount a fixed browser-sample project.');
        $this->assertStringNotContainsString('id="enterprise-workflow"', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(419, $this->mounted('POST', $this->path('/campus/workflows/reviewer-name'), ['reviewer_name' => 'Andrew'])->getStatusCode());
        $binding = $this->binding();
        $fields = ['_token' => $csrf] + $binding;
        foreach ([['GET', '/campus/workflows/review', []],
            ['POST', '/campus/workflows/reviewer-name', $fields + ['reviewer_name' => $this->version->payload['reviewer_names'][0]]],
            ['POST', '/campus/workflows/comments', $fields + ['body' => 'Unsupported workflow feedback', 'comment_key' => (string) Str::uuid(),
                'expected_reviewer_name' => $this->version->payload['reviewer_names'][0]]]] as [$method, $suffix, $body]) {
            $this->assertSame(404, $this->mounted($method, $this->path($suffix), $body)->getStatusCode(), $suffix);
        }
        $this->assertSame($before, $this->state());
        $review = $this->mounted('GET', $this->path('/campus/projects/review'));
        $this->assertSame(200, $review->getStatusCode());
        $context = json_decode($review->getContent(), true, 64, JSON_THROW_ON_ERROR)['review'];
        $this->assertCount(13, $context['pages']);
        $this->assertNotContains('workflows', array_column($context['pages'], 'id'));
        foreach (['pages', 'data_pages', 'name_pages', 'comment_pages'] as $group) {
            $this->assertArrayNotHasKey('workflows', $context['urls'][$group]);
        }
        $this->assertSame($binding['source_hash'], $context['source_hash']);
        $this->assertCount(14, $this->version->payload['campus_review']['pages']);
    }

    public function test_public_browser_only_workflow_canvas_and_private_without_shared_descriptor_stay_available(): void
    {
        $request = Request::create('http://localhost/platform/demos/lees-mcrae/workflows');
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);
        $response = app(ReviewerPresentation::class)->respond($request, true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('id="enterprise-workflow"', $response->getContent());
        $this->assertStringContainsString("connect-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->publish(false);
        $csrf = $this->unlock();
        $this->assertIsString($csrf);
        $response = $this->mounted('GET', $this->path('/campus/workflows'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('id="enterprise-workflow"', $response->getContent());
        $review = $this->mounted('GET', $this->path('/campus/workflows/review'));
        $this->assertSame(200, $review->getStatusCode());
        $context = json_decode($review->getContent(), true, 64, JSON_THROW_ON_ERROR)['review'];
        $this->assertCount(14, $context['pages']);
        $this->assertArrayHasKey('workflows', $context['urls']['pages']);
    }
}
