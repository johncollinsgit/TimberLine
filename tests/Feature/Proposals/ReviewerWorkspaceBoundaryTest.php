<?php

namespace Tests\Feature\Proposals;

use App\Http\Middleware\ReviewerAiBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReviewerWorkspaceBoundaryTest extends TestCase
{
    public function test_all_workspace_equivalent_routes_sanitize_precontroller_failures_without_exempting_method_or_throttle(): void
    {
        foreach (['projection', 'facts', 'commands', 'command-status'] as $action) {
            foreach (['', '/'] as $suffix) {
                foreach ([503, 405, 419, 429] as $status) {
                    $path = '/enterprise-owner/service-proposals/SYNTHETIC/campus/workspace/'.$action.$suffix;
                    $request = Request::create('https://example.invalid'.str_replace('workspace', 'work%73pace', $path), 'GET');
                    $response = (new ReviewerAiBoundary)->handle($request, fn () => throw new HttpException($status, 'INTERNAL DEBUG SENTINEL', headers: ['Allow' => 'POST', 'Retry-After' => '60']));
                    $this->assertSame($status, $response->getStatusCode());
                    $value = json_decode($response->getContent(), true);
                    $this->assertSame(['error', 'automatic_retry'], array_keys($value));
                    $this->assertFalse($value['automatic_retry']);
                    $this->assertStringNotContainsString('SENTINEL', $response->getContent());
                    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                    $this->assertSame('POST', $response->headers->get('Allow'));
                    $this->assertSame('60', $response->headers->get('Retry-After'));
                }
            }
        }
    }

    public function test_only_controller_marked_closed_rejections_preserve_definitive_command_identity(): void
    {
        $body = ['error' => 'workspace_conflict', 'automatic_retry' => false, 'definitive_not_applied' => true,
            'request_id' => 'SYNTHETIC', 'digest' => str_repeat('a', 64), 'workspace_id' => 'SYNTHETIC'];
        foreach ([false, true] as $handled) {
            $request = Request::create('https://example.invalid/enterprise-owner/service-proposals/SYNTHETIC/campus/workspace/commands', 'POST');
            $response = (new ReviewerAiBoundary)->handle($request, function ($request) use ($body, $handled) {
                if ($handled) {
                    $request->attributes->set('service_proposal_workspace_response', true);
                }

                return new JsonResponse($body, 409);
            });
            $this->assertSame($handled ? $body : ['error' => 'original_request_required', 'automatic_retry' => false], json_decode($response->getContent(), true));
        }
    }

    public function test_workspace_runtime_exceptions_are_private_and_unrelated_paths_are_unchanged(): void
    {
        $request = Request::create('https://example.invalid/enterprise-owner/service-proposals/SYNTHETIC/campus/workspace/facts', 'POST');
        $response = (new ReviewerAiBoundary)->handle($request, fn () => throw new \RuntimeException('PRIVATE INTERNAL SENTINEL'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('SENTINEL', $response->getContent());
        $original = new Response('Unrelated existing page', 200);
        $this->assertSame($original, (new ReviewerAiBoundary)->handle(Request::create('/unrelated'), fn () => $original));
    }
}
