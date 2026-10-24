<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Private CSRF/session JSON facade. No initializer, native URI proxy or provider operation. */
final class ReviewerWorkspaceController
{
    public function __construct(private ReviewerWorkspace $workspace, private ReviewerAiHttpPolicy $http) {}

    private function respond(Request $request, callable $operation): JsonResponse
    {
        try {
            $response = response()->json($operation());
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $status = in_array($status, [400, 403, 404, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $response = response()->json($error instanceof ReviewerWorkspaceRejection ? $error->envelope() : ['error' => $status === 409 ? 'workspace_conflict' : ($status === 429 ? 'temporarily_limited' : ($status === 503 ? 'workspace_unavailable' : 'workspace_request_denied')), 'automatic_retry' => false], $status);
        }

        $request->attributes->set('service_proposal_workspace_response', true);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    private function body(Request $request, array $keys, string $action): array
    {
        abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
            && in_array($request->header('Sec-Fetch-Site', 'same-origin'), ['same-origin'], true), 403);
        $body = $this->http->body($request, $keys);
        $key = 'proposal-workspace:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->session()->getId().'|'.$action);
        $limit = $action === 'command' ? 30 : 60;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
        RateLimiter::hit($key, 60);
        $ip = 'proposal-workspace-ip:'.hash('sha256', $request->getHost().'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($ip, 120), 429);
        RateLimiter::hit($ip, 60);
        abort_unless(is_array($body['binding']) && is_array($body['selection']), 422);

        return $body;
    }

    public function projection(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, function () use ($request, $token) {
            $body = $this->body($request, ['binding', 'selection'], 'projection');

            return $this->workspace->projection($request, $token, $body['binding'], $body['selection']);
        });
    }

    /** Explicit sharing read only; ordinary workspace browsing need not fit the AI evidence bound. */
    public function facts(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, function () use ($request, $token) {
            $body = $this->body($request, ['binding', 'selection'], 'facts');

            return $this->workspace->facts($request, $token, $body['binding'], $body['selection']);
        });
    }

    public function command(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, function () use ($request, $token) {
            $body = $this->body($request, ['binding', 'selection', 'request_id', 'expected_revision', 'action', 'record_id', 'expected_record_version', 'payload'], 'command');
            $wire = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
            abort_unless($wire->payload instanceof \stdClass, 422);

            return $this->workspace->command($request, $token, $body);
        });
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, function () use ($request, $token) {
            $body = $this->body($request, ['binding', 'selection', 'request_id', 'digest'], 'status');
            abort_unless(is_string($body['request_id']) && is_string($body['digest']), 422);

            return $this->workspace->commandStatus($request, $token, $body['binding'], $body['selection'], $body['request_id'], $body['digest']);
        });
    }
}
