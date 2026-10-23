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
        $this->manifests->assertCurrent($version, $page);

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
        if (config('service_proposals.reviewer_ai.ui_enabled') === true && config('service_proposals.reviewer_ai.enabled') === true) {
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

        return app(ReviewerPresentation::class)->respond($request, $page === 'workflows', $context);
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
        $manifest = $this->manifests->assertCurrent($version, $page);
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
        foreach ($manifest['pages'] as $entry) {
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

        return ['version_id' => $version->id, 'version_hash' => $version->content_hash, 'build_id' => $manifest['build_id'],
            'source_hash' => $manifest['source_hash'], 'client_reference' => $manifest['client_reference'], 'page' => $page, 'pages' => $manifest['pages'],
            'reviewer_name' => in_array($name, $version->payload['reviewer_names'] ?? [], true) ? $name : null,
            'reviewer_names' => $version->payload['reviewer_names'] ?? [], 'comments' => $visible,
            'comment_key' => (string) Str::uuid(), 'csrf_token' => csrf_token(), 'writes_enabled' => (bool) config('service_proposals.writes_enabled'), 'urls' => $urls,
            'coverage' => ['scanned_comments' => min(2000, $rows->count()), 'scan_limit' => 2000, 'visible_limit' => 200,
                'partial' => $rows->count() > 2000 || $comments->count() > 200]];
    }
}
