<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ReviewerAiController
{
    public function __construct(private ReviewerAiAccess $access, private ReviewerAiOrchestrator $orchestrator, private ReviewerAiHttpPolicy $http, private ReviewerAiWorkspace $workspace, private ReviewerAiReceipts $receipts) {}

    private function response(callable $operation): JsonResponse
    {
        try {
            $value = $operation();
            $response = response()->json($value);
        } catch (Throwable $failure) {
            $status = $failure instanceof HttpExceptionInterface ? $failure->getStatusCode()
                : ($failure instanceof ModelNotFoundException ? 404 : ($failure instanceof \JsonException ? 422 : 503));
            $status = in_array($status, [400, 403, 404, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $code = match ($status) {
                400, 422 => 'invalid_request', 403, 419 => 'access_required', 404 => 'unavailable',
                409 => 'original_request_required', 410 => 'content_expired', 429 => 'temporarily_limited', default => 'reconciliation_required',
            };
            // No exception/provider body, key, token, URL, question or database diagnostic reaches the browser/log.
            $response = response()->json(['error' => $code, 'provider_redispatch' => false], $status);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");

        return $response;
    }

    public function preview(Request $request, string $token): JsonResponse
    {
        return $this->response(function () use ($request, $token): array {
            $body = $this->http->body($request, ['binding', 'request_id', 'question'], ['history', 'snapshot', 'workspace']);
            abort_unless(is_array($body['binding']) && is_string($body['request_id']) && is_string($body['question']) && (! array_key_exists('history', $body) || is_array($body['history'])), 422);
            $scope = $this->access->scope($request, $token, $body['binding'], true);
            $this->http->rate($request, $scope, 'preview');

            $prepared = $this->workspace->prepare($scope, $request, $token, $body);
            $context = $prepared['scope']['workspace_context'] ?? null;
            $authority = fn () => $this->workspace->attach($this->access->scope($request, $token, $body['binding']),
                $request, $token, $body['binding'], $context);

            return $this->orchestrator->preview($prepared['scope'], $body['question'], $prepared['snapshot'], $body['request_id'],
                $authority, $body['history'] ?? [])['preview'];
        });
    }

    public function confirm(Request $request, string $token): JsonResponse
    {
        return $this->receipt($request, $token, 'confirm');
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        return $this->receipt($request, $token, 'cancel');
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return $this->receipt($request, $token, 'status');
    }

    private function receipt(Request $request, string $token, string $action): JsonResponse
    {
        return $this->response(function () use ($request, $token, $action): array {
            $receipt = $this->http->body($request, ['binding', 'preview_id', 'request_id', 'digest', 'snapshot_digest']);
            abort_unless(is_array($receipt['binding']), 422);
            $scope = $this->access->scope($request, $token, $receipt['binding']);
            $this->http->rate($request, $scope, $action);

            $context = $this->receipts->workspaceContext($scope, $receipt); // Principal/browser ownership before any workspace context read.
            $authority = fn () => $this->workspace->attach($this->access->scope($request, $token, $receipt['binding']),
                $request, $token, $receipt['binding'], $context, $action === 'confirm');
            $scope = $authority();

            return $this->orchestrator->{$action}($scope, $receipt, $authority);
        });
    }
}
