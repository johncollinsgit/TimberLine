<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Real authenticated owner HTTP cleanup only; no schedule, provisioning or recipient capability. */
final class ReviewerWorkspaceMaintenanceController
{
    public function __construct(private Access $owners, private ReviewerWorkspaceStore $store,
        private ReviewerWorkspace $workspace, private ReviewerWorkspaceCollaborationPruner $comments,
        private ReviewerWorkspaceFilesPruner $files) {}

    public function prune(Request $request, string $workspace, string $kind): JsonResponse
    {
        try {
            abort_unless(config('service_proposals.reviewer_workspace.maintenance_enabled') === true, 404);
            abort_unless(in_array($kind, ['graph', 'comments', 'quarantine'], true), 404);
            // Read-level real-owner authority is deliberate: ordinary proposal,
            // workspace, comments and file writes can all remain disabled.
            $owner = $this->owners->owner($request);
            $context = $this->store->context(false);
            abort_unless((int) $owner->id === (int) $context['tenant']->id, 404);
            abort_unless(strlen($request->getContent()) <= 2048, 400);
            abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
                && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
            $input = app(ReviewerAiHttpPolicy::class)->body($request, [], ['limit', 'after', 'apply']);
            $limit = array_key_exists('limit', $input) ? $input['limit'] : 25;
            $after = $input['after'] ?? null;
            $apply = array_key_exists('apply', $input) ? $input['apply'] : false;
            abort_unless(is_int($limit) && $limit >= 1 && $limit <= 25
                && ($after === null || ReviewerWorkspaceSchema::uuid($after)) && is_bool($apply), 422);
            // Omitted apply is a preview. Only an explicit JSON boolean true
            // reaches the existing bounded erasers, which recheck expiry/owner.
            $dryRun = ! $apply;
            $result = match ($kind) {
                'graph' => $this->workspace->pruneExpired($request, $limit, $after, $dryRun),
                'comments' => $this->comments->prune($request, $limit, $after, $dryRun),
                'quarantine' => $this->files->prune($request, $limit, $after, $dryRun),
            };
            $response = response()->json(['resource' => $kind, 'dry_run' => $dryRun, 'result' => $result]);
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
                : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $status = in_array($status, [400, 403, 404, 405, 409, 410, 419, 422, 429, 503], true) ? $status : 503;
            $response = response()->json(['error' => 'workspace_maintenance_unavailable', 'automatic_retry' => false], $status);
        }
        $request->attributes->set('service_proposal_workspace_response', true);

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }
}
