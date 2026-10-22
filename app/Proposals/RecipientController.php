<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RecipientController
{
    public function __construct(private Access $access, private ProposalService $proposals) {}

    private function unlocked(Request $request, Version $version): bool
    {
        $grant = $request->session()->get('service_proposal_access.'.$version->id, []);

        return ($grant['hash'] ?? '') === $version->content_hash && ($grant['until'] ?? 0) > now()->timestamp;
    }

    public function show(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        if (! $this->unlocked($request, $version)) {
            return response()->view('service-proposals.unlock', compact('token'))->header('Cache-Control', 'private, no-store');
        }
        $requestKey = $request->session()->get('service_proposal_request.'.$version->id);
        if (! $requestKey) {
            $requestKey = Str::uuid()->toString();
            $request->session()->put('service_proposal_request.'.$version->id, $requestKey);
        }

        $comments = Comment::where('tenant_id', $version->tenant_id)->where('version_id', $version->id)->oldest()->limit(500)->get();
        $reviewerName = $request->session()->get('service_proposal_name.'.$version->id);
        $commentKey = Str::uuid()->toString();

        return response()->view('service-proposals.recipient', ['payload' => $version->payload, 'preview' => false, 'version' => $version, 'token' => $token, 'requestKey' => $requestKey, 'comments' => $comments, 'reviewerName' => $reviewerName, 'commentKey' => $commentKey])->header('Cache-Control', 'private, no-store');
    }

    public function unlock(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        $data = $request->validate(['password' => ['required', 'string', 'max:128']]);
        if (! Hash::check($data['password'], $version->password_hash)) {
            return back()->withErrors(['access' => 'Unable to unlock this proposal.']);
        }
        $request->session()->regenerate();
        $request->session()->put('service_proposal_access.'.$version->id, ['hash' => $version->content_hash, 'until' => min(now()->addMinutes(30)->timestamp, $version->expires_at->timestamp)]);

        return redirect()->route('service-proposals.recipient.show', $token);
    }

    public function requestAgreement(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        abort_unless(config('service_proposals.writes_enabled') && $this->unlocked($request, $version), 403);
        abort_unless(($version->payload['audience'] ?? 'client') === 'client', 403);
        $data = $request->validate(['version_hash' => ['required', 'string'], 'request_key' => ['required', 'uuid'], 'selected' => ['required', 'array', 'min:1'], 'selected.*' => ['uuid', 'distinct'], 'terms' => ['nullable', 'array'], 'terms.*' => ['nullable', 'integer'], 'acknowledge' => ['accepted']]);
        abort_unless(hash_equals($version->content_hash, $data['version_hash'])
            && hash_equals((string) $request->session()->get('service_proposal_request.'.$version->id), $data['request_key']), 409);
        $selected = array_map(fn ($id) => ['id' => $id, 'term_months' => isset($data['terms'][$id]) && $data['terms'][$id] !== '' ? (int) $data['terms'][$id] : null], $data['selected']);
        $review = $this->proposals->requestAgreement($version, $selected, $data['request_key'], (string) $request->ip());

        return response()->view('service-proposals.requested', ['selection' => $review->payload['selection'], 'review' => $review])->header('Cache-Control', 'private, no-store');
    }

    public function reviewSelection(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        abort_unless($this->unlocked($request, $version) && ($version->payload['audience'] ?? '') === 'client', 403);
        $data = $request->validate(['version_hash' => ['required', 'string'], 'request_key' => ['required', 'uuid'], 'selected' => ['required', 'array', 'min:1'], 'selected.*' => ['uuid', 'distinct'], 'terms' => ['nullable', 'array'], 'terms.*' => ['nullable', 'integer']]);
        abort_unless(hash_equals($version->content_hash, $data['version_hash'])
            && hash_equals((string) $request->session()->get('service_proposal_request.'.$version->id), $data['request_key']), 409);
        $selected = array_map(fn ($id) => ['id' => $id, 'term_months' => isset($data['terms'][$id]) && $data['terms'][$id] !== '' ? (int) $data['terms'][$id] : null], $data['selected']);
        $selection = $this->proposals->selection($version->payload, $selected);
        $requestKey = $data['request_key'];

        return response()->view('service-proposals.selection-review', compact('version', 'token', 'selection', 'selected', 'requestKey'))->header('Cache-Control', 'private, no-store');
    }

    public function reviewerName(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        abort_unless($this->unlocked($request, $version), 403);
        $data = $request->validate(['reviewer_name' => ['required', \Illuminate\Validation\Rule::in($version->payload['reviewer_names'] ?? [])]]);
        $request->session()->put('service_proposal_name.'.$version->id, $data['reviewer_name']);

        return redirect()->route('service-proposals.recipient.show', $token);
    }

    public function estimate(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        abort_unless($this->unlocked($request, $version) && ! empty($version->payload['client_details']), 403);
        $estimate = app(UsageEstimate::class)->calculate($request->only(['departments', 'low', 'high']));

        return redirect()->to(route('service-proposals.recipient.show', $token).'#usage-estimator')->with('proposal_estimate', ['version_id' => $version->id, 'content_hash' => $version->content_hash, 'result' => $estimate]);
    }

    public function comment(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        abort_unless(config('service_proposals.writes_enabled') && $this->unlocked($request, $version), 403);
        $name = $request->session()->get('service_proposal_name.'.$version->id);
        abort_unless(in_array($name, $version->payload['reviewer_names'] ?? [], true), 403, 'Choose your review display name first.');
        $data = $request->validate(['page' => ['required', 'in:overview,approach,scope,pricing'], 'body' => ['required', 'string', 'max:4000'], 'comment_key' => ['required', 'uuid']]);
        $this->proposals->comment($version, $name, $data['page'], $data['body'], $data['comment_key']);

        return redirect()->to(route('service-proposals.recipient.show', $token).'#review-comments')->with('status', 'Comment saved to this proposal version.');
    }
}
