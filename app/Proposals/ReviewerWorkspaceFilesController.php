<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Closed bounded JSON chunks only; no URL fetch, multipart proxy, native identity or binary-read route. */
final class ReviewerWorkspaceFilesController
{
    public function __construct(private ReviewerWorkspaceFiles $files) {}

    private function respond(Request $request, string $token, string $action, array $extra): JsonResponse
    {
        try {
            abort_unless($request->isMethod('POST') && strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) === 'application/json'
                && strlen($request->getContent()) <= 384 * 1024 && $request->query->count() === 0, 400);
            abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
                && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
            abort_unless($request->hasSession() && is_string($request->header('X-CSRF-TOKEN')) && $request->session()->token() !== ''
                && hash_equals($request->session()->token(), $request->header('X-CSRF-TOKEN')), 419);
            $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($input) && ! array_is_list($input), 422);
            ReviewerWorkspaceSchema::exact($input, array_merge(['binding', 'selection', 'target'], $extra));
            $key = 'proposal-work-files:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->session()->getId());
            $ip = 'proposal-work-files-ip:'.hash('sha256', $request->getHost().'|'.$request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 100) || RateLimiter::tooManyAttempts($ip, 200), 429);
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ip, 60);
            $response = response()->json($this->files->{$action}($request, $token, $input));
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $status = in_array($status, [400, 403, 404, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $response = response()->json(['error' => 'workspace_upload_unavailable', 'automatic_retry' => false], $status);
        }
        $request->attributes->set('service_proposal_workspace_response', true);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function show(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, $token, 'show', []);
    }

    public function begin(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, $token, 'begin', ['request_id', 'expected_target_version', 'expected_reviewer_name', 'name', 'size', 'mime', 'sha256']);
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, $token, 'status', ['request_id', 'digest']);
    }

    public function chunk(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, $token, 'chunk', ['upload_id', 'index', 'sha256', 'bytes']);
    }

    public function complete(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, $token, 'complete', ['upload_id']);
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, $token, 'cancel', ['upload_id']);
    }
}
