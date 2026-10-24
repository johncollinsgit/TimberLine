<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Actual authenticated owner HTTP only; no CLI, recipient or generic operator proxy. */
final class ReviewerWorkspaceInitializationController
{
    public function __construct(private ReviewerWorkspaceInitialization $initialization) {}

    public function handle(Request $request, string $workspace, string $action): JsonResponse
    {
        try {
            abort_unless(config('service_proposals.reviewer_workspace.owner_initialization.enabled') === true, 404);
            abort_unless(in_array($action, ['preview', 'apply', 'status'], true), 404);
            abort_unless($request->isMethod('POST') && $request->query->count() === 0
                && strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) === 'application/json'
                && strlen($request->getContent()) <= 300 * 1024, 400);
            abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
                && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
            abort_unless($request->hasSession() && is_string($request->header('X-CSRF-TOKEN'))
                && $request->session()->token() !== '' && hash_equals($request->session()->token(), $request->header('X-CSRF-TOKEN')), 419);
            $object = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
            abort_unless($object instanceof \stdClass && ($action === 'status' || ($object->seed ?? null) instanceof \stdClass)
                && ($action !== 'apply' || ($object->preview ?? null) instanceof \stdClass), 422);
            $input = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $result = $this->initialization->{$action}($request, $input);
            $response = response()->json($result);
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $status = in_array($status, [400, 403, 404, 405, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            // No admission failure proves non-application; status must resolve the exact original intent.
            $response = response()->json(['error' => 'workspace_initialization_unavailable', 'automatic_retry' => false, 'settled' => false], $status);
        }
        $request->attributes->set('service_proposal_workspace_response', true);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }
}
