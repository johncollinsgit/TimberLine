<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Fixed same-origin private JSON operations only. No upload/native URL proxy or implicit initializer. */
final class ReviewerWorkspaceCollaborationController
{
    public function __construct(private ReviewerWorkspaceDiscussion $discussion, private ReviewerAiHttpPolicy $http) {}

    private function respond(Request $request, callable $operation): JsonResponse
    {
        try {
            $response = response()->json($operation());
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $status = in_array($status, [400, 403, 404, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $response = response()->json($error instanceof ReviewerWorkspaceRejection ? $error->envelope()
                : ['error' => 'workspace_discussion_unavailable', 'automatic_retry' => false], $status);
        }
        $request->attributes->set('service_proposal_workspace_response', true);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    private function input(Request $request, array $fields, string $action): array
    {
        abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
            && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
        $input = $this->http->body($request, $fields);
        $key = 'proposal-collaboration:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->session()->getId().'|'.$action);
        $ip = 'proposal-collaboration-ip:'.hash('sha256', $request->getHost().'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, $action === 'comment' ? 30 : 60) || RateLimiter::tooManyAttempts($ip, 120), 429);
        RateLimiter::hit($key, 60);
        RateLimiter::hit($ip, 60);
        abort_unless(is_array($input['binding']) && is_array($input['selection']) && is_array($input['target']), 422);

        return $input;
    }

    public function show(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, function () use ($request, $token) {
            $input = $this->input($request, ['binding', 'selection', 'target', 'before'], 'show');
            abort_unless($input['before'] === null || is_string($input['before']), 422);

            return $this->discussion->show($request, $token, $input['binding'], $input['selection'], $input['target'], $input['before']);
        });
    }

    public function comment(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, fn () => $this->discussion->comment($request, $token, $this->input($request,
            ['binding', 'selection', 'target', 'request_id', 'expected_target_version', 'expected_thread_revision', 'expected_reviewer_name', 'body'], 'comment')));
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return $this->respond($request, function () use ($request, $token) {
            $input = $this->input($request, ['binding', 'selection', 'target', 'request_id', 'digest'], 'status');
            abort_unless(is_string($input['request_id']) && is_string($input['digest']), 422);

            return $this->discussion->status($request, $token, $input['binding'], $input['selection'], $input['target'], $input['request_id'], $input['digest']);
        });
    }
}
