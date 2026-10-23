<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ReviewerAiController
{
    public function __construct(private ReviewerAiAccess $access, private ReviewerAiOrchestrator $orchestrator, private ReviewerAiHttpPolicy $http) {}

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
            $body = $this->http->body($request, ['binding', 'request_id', 'question', 'snapshot']);
            abort_unless(is_array($body['binding']) && is_string($body['request_id']) && is_string($body['question']) && is_array($body['snapshot']), 422);
            $scope = $this->access->scope($request, $token, $body['binding'], true);
            $this->http->rate($request, $scope, 'preview');

            return $this->orchestrator->preview($scope, $body['question'], $body['snapshot'], $body['request_id'],
                fn () => $this->access->scope($request, $token, $body['binding']))['preview'];
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

            return $this->orchestrator->{$action}($scope, $receipt, fn () => $this->access->scope($request, $token, $receipt['binding']));
        });
    }
}
