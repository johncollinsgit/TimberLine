<?php

namespace App\Proposals;

use App\Enterprise\ReviewerPresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Password-protected proposal review around fictional screens; never Enterprise authorization. */
class CampusReviewController
{
    public function __construct(private Access $access, private CampusReviewManifest $manifests, private ProposalService $proposals) {}

    private function version(Request $request, string $token, string $page): Version
    {
        abort_if($request->query->count() !== 0, 400);
        $version = $this->access->recipient($request, $token);
        abort_unless($this->access->unlocked($request, $version), 403, 'Unlock this exact proposal version first.');
        $edition = $this->manifests->resolveReviewEdition($version, $page);
        abort_if($page === 'workflows' && $edition['kind'] === 'current' && array_key_exists('review_workspace', $version->payload), 404,
            'Workflows are unavailable in this shared review workspace.');

        return $version;
    }

    public function show(Request $request, string $token, string $page = 'home')
    {
        $version = $this->access->recipient($request, $token);
        if (! $this->access->unlocked($request, $version)) {
            return redirect()->route('service-proposals.recipient.show', $token);
        }
        $version = $this->version($request, $token, $page);
        $context = $this->context($request, $version, $token, $page);
        $edition = $this->manifests->resolveReviewEdition($version, $page);
        if ($edition['kind'] === 'current' && array_key_exists('review_workspace', $version->payload)) {
            // Descriptor presence is an immutable mode choice. Disabled, missing
            // or invalid shared state must never become a browser-local demo.
            $binding = array_intersect_key($context, array_flip(['version_hash', 'build_id', 'source_hash']));
            $scope = app(ReviewerWorkspaceAccess::class)->scope($request, $token, $binding);
            $persona = collect(['demo_manager', 'manager', 'executive', 'requester'])
                ->first(fn ($value) => in_array($value, $scope['policy']['personas'], true));
            abort_unless(is_string($persona), 503, 'The reviewed initial perspective is unavailable.');
            $selection = ['persona' => $persona, 'area_ids' => $scope['policy']['area_ids'], 'person_id' => null];
            app(ReviewerWorkspace::class)->projection($request, $token, $binding, $selection);
            $context['reviewer_workspace'] = ['enabled' => true, ...$scope['descriptor'], 'binding' => $binding,
                'selection' => $selection, 'browser_context' => $scope['capability_hash'],
                'csrf' => $context['csrf_token'], 'endpoints' => []];
            foreach (['projection', 'facts', 'commands', 'status' => 'command-status'] as $key => $action) {
                $context['reviewer_workspace']['endpoints'][is_int($key) ? $action : $key] = route('service-proposals.recipient.workspace.'.$action, $token);
            }
            $context['reviewer_personas'] = $scope['policy']['personas'];
            if (array_key_exists('review_workspace_collaboration', $version->payload)
                && config('service_proposals.reviewer_workspace.collaboration.enabled') === true) {
                $context['reviewer_collaboration'] = app(ReviewerWorkspaceCollaborationAccess::class)
                    ->clientDescriptor($request, $token, $binding, $selection);
            }
            if (array_key_exists('review_workspace_attachments', $version->payload)
                && config('service_proposals.reviewer_workspace.attachments.enabled') === true) {
                abort_unless(array_key_exists('reviewer_collaboration', $context), 503);
                $context['reviewer_attachments'] = app(ReviewerWorkspaceAttachmentsAccess::class)
                    ->publicationDescriptor($request, $token, $binding, $selection);
            }
            if (array_key_exists('review_workspace_images', $version->payload)
                && config('service_proposals.reviewer_workspace.image_access.enabled') === true) {
                abort_unless(array_key_exists('reviewer_attachments', $context), 503);
                $context['reviewer_images'] = app(ReviewerWorkspaceImagesAccess::class)
                    ->publicationDescriptor($request, $token, $binding, $selection);
            }
            if (array_key_exists('review_workspace_downloads', $version->payload)
                && config('service_proposals.reviewer_workspace.downloads.enabled') === true) {
                abort_unless(array_key_exists('reviewer_attachments', $context), 503);
                $context['reviewer_downloads'] = app(ReviewerWorkspaceDownloadsAccess::class)
                    ->publicationDescriptor($request, $token, $binding, $selection);
            }
        }
        if ($edition['kind'] === 'current' && config('service_proposals.reviewer_ai.ui_enabled') === true && config('service_proposals.reviewer_ai.enabled') === true) {
            try {
                $scope = app(ReviewerAiAccess::class)->scope($request, $token, startBrowser: true);
                app(ReviewerAiConnection::class)->settings($scope);
                $context['reviewer_ai'] = ['enabled' => true, 'endpoints' => []];
                foreach (['preview', 'confirm', 'status', 'cancel'] as $action) {
                    $context['reviewer_ai']['endpoints'][$action] = route('service-proposals.recipient.ai.'.$action, $token);
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                // Optional AI admission never changes the proposal or native workspace grant.
                if (! in_array($error->getStatusCode(), [403, 404, 409, 410, 503], true)) {
                    throw $error;
                }
            }
        }
        $request->attributes->set('service_proposal_campus_review', true);

        $renderer = $edition['kind'] === 'legacy_browser_only' ? LegacyCampusRenderer::class : ReviewerPresentation::class;
        $response = app($renderer)->respond($request, $page === 'workflows', $context);
        // Rendering is buffered: repeat real access/edition checks before returning any content.
        $fresh = $this->version($request, $token, $page);
        abort_unless($fresh->id === $version->id && hash_equals($fresh->content_hash, $context['version_hash'])
            && $this->manifests->resolveReviewEdition($fresh, $page)['kind'] === $edition['kind'], 409);

        return $response;
    }

    public function review(Request $request, string $token, string $page)
    {
        return response()->json(['review' => $this->context($request, $this->version($request, $token, $page), $token, $page)]);
    }

    private function input(Request $request, array $extra, Version $version): array
    {
        $input = $request->isJson() ? $request->json()->all() : $request->request->all();
        abort_if(array_diff(array_keys($input), ['_token', 'version_hash', 'build_id', 'source_hash', ...array_keys($extra)]) !== [], 422,
            'Only the explicit campus review fields are accepted.');
        $rules = ['version_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'build_id' => ['required', 'string', 'max:120'], 'source_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], ...$extra];
        $data = validator($input, $rules)->validate();
        $manifest = $version->payload['campus_review'];
        abort_unless(hash_equals($version->content_hash, $data['version_hash'])
            && $data['build_id'] === $manifest['build_id'] && hash_equals($manifest['source_hash'], $data['source_hash']), 409,
            'The campus review version changed. Reload the exact page before continuing.');

        return $data;
    }

    public function name(Request $request, string $token, string $page)
    {
        $version = $this->version($request, $token, $page);
        $data = $this->input($request, ['reviewer_name' => ['required', 'string', Rule::in($version->payload['reviewer_names'] ?? [])]], $version);
        $request->session()->put('service_proposal_name.'.$version->id, $data['reviewer_name']);
        $request->attributes->set('service_proposal_activity_completed', 'name_selected');

        return $this->saved($request, $version, $token, $page);
    }

    public function comment(Request $request, string $token, string $page)
    {
        $version = $this->version($request, $token, $page);
        abort_unless(config('service_proposals.writes_enabled'), 403);
        $name = $request->session()->get('service_proposal_name.'.$version->id);
        abort_unless(is_string($name) && in_array($name, $version->payload['reviewer_names'] ?? [], true), 403,
            'Choose your review display name first.');
        $data = $this->input($request, ['body' => ['required', 'string', 'max:4000'], 'comment_key' => ['required', 'uuid'],
            'expected_reviewer_name' => ['required', 'string', Rule::in($version->payload['reviewer_names'] ?? [])]], $version);
        abort_unless($data['expected_reviewer_name'] === $name, 409, 'The review display name changed. Review the retained comment before retrying.');
        $this->proposals->commentCampus($version, $name, $page, $data['body'], $data['comment_key'], $data);
        $request->attributes->set('service_proposal_activity_completed', 'comment_saved');

        return $this->saved($request, $version, $token, $page);
    }

    private function saved(Request $request, Version $version, string $token, string $page)
    {
        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'review' => $this->context($request, $version, $token, $page)]);
        }

        return redirect()->route('service-proposals.recipient.campus', [$token, $page]);
    }

    public function context(Request $request, Version $version, string $token, string $page): array
    {
        // Fresh access is checked even for the projection following a command.
        $version = $this->version($request, $token, $page);
        $edition = $this->manifests->resolveReviewEdition($version, $page);
        $manifest = $edition['manifest'];
        $pages = $edition['kind'] === 'current' && array_key_exists('review_workspace', $version->payload)
            ? array_values(array_filter($manifest['pages'], fn ($entry) => $entry['id'] !== 'workflows')) : $manifest['pages'];
        $rows = Comment::where('tenant_id', $version->tenant_id)->where('version_id', $version->id)
            ->latest()->orderByDesc('id')->limit(2001)->get();
        $comments = $rows->take(2000)->filter(function ($comment) use ($manifest, $version, $page): bool {
            $data = $comment->payload;

            return ($data['version_hash'] ?? null) === $version->content_hash && ($data['page'] ?? null) === 'campus:'.$page
                && ($data['campus_review']['build_id'] ?? null) === $manifest['build_id']
                && ($data['campus_review']['source_hash'] ?? null) === $manifest['source_hash']
                && ($data['campus_review']['client_reference'] ?? null) === $manifest['client_reference'];
        });
        $visible = $comments->take(200)->reverse()->map(fn ($comment) => ['name' => $comment->payload['name'],
            'page' => $page, 'body' => $comment->payload['body'], 'created_at' => $comment->created_at->utc()->toIso8601String()])->values()->all();
        $urls = ['proposal' => route('service-proposals.recipient.show', $token)];
        foreach ($pages as $entry) {
            $args = [$token, $entry['id']];
            $urls['pages'][$entry['id']] = route('service-proposals.recipient.campus', $args);
            $urls['data_pages'][$entry['id']] = route('service-proposals.recipient.campus.review', $args);
            $urls['name_pages'][$entry['id']] = route('service-proposals.recipient.campus.name', $args);
            $urls['comment_pages'][$entry['id']] = route('service-proposals.recipient.campus.comment', $args);
        }
        foreach (['current' => 'pages', 'data' => 'data_pages', 'name' => 'name_pages', 'comment' => 'comment_pages'] as $key => $group) {
            $urls[$key] = $urls[$group][$page];
        }
        $name = $request->session()->get('service_proposal_name.'.$version->id);

        return ['activity_url' => route('service-proposals.recipient.activity', $token), 'version_id' => $version->id, 'version_hash' => $version->content_hash, 'build_id' => $manifest['build_id'],
            'source_hash' => $manifest['source_hash'], 'client_reference' => $manifest['client_reference'], 'page' => $page, 'pages' => $pages,
            'reviewer_name' => in_array($name, $version->payload['reviewer_names'] ?? [], true) ? $name : null,
            'reviewer_names' => $version->payload['reviewer_names'] ?? [], 'comments' => $visible,
            'comment_key' => (string) Str::uuid(), 'csrf_token' => csrf_token(), 'writes_enabled' => (bool) config('service_proposals.writes_enabled'), 'urls' => $urls,
            'coverage' => ['scanned_comments' => min(2000, $rows->count()), 'scan_limit' => 2000, 'visible_limit' => 200,
                'partial' => $rows->count() > 2000 || $comments->count() > 200]];
    }
}
