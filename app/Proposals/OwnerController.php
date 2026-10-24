<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Illuminate\Http\Request;

class OwnerController
{
    public function __construct(private Access $access, private ProposalService $proposals) {}

    public function index(Request $request)
    {
        $tenant = $this->access->owner($request);
        $drafts = Draft::where('tenant_id', $tenant->id)->latest()->limit(100)->get();
        $assets = $this->proposals->assets($tenant);
        $reviews = Review::where('tenant_id', $tenant->id)->latest()->limit(100)->get();
        $versions = Version::where('tenant_id', $tenant->id)->latest()->limit(100)->get();
        $comments = Comment::where('tenant_id', $tenant->id)->latest()->limit(100)->get();

        return response()->view('service-proposals.manager', compact('tenant', 'drafts', 'assets', 'reviews', 'versions', 'comments'))->header('Cache-Control', 'private, no-store');
    }

    public function edit(Request $request, string $workspace, ?string $draftId = null)
    {
        $tenant = $this->access->owner($request);
        $draft = $draftId ? $this->proposals->draft($tenant, $draftId) : null;
        $assets = $this->proposals->assets($tenant);

        $publishKey = \Illuminate\Support\Str::uuid()->toString();

        return response()->view('service-proposals.edit', compact('tenant', 'draft', 'assets', 'publishKey'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $workspace, ?string $draftId = null)
    {
        $tenant = $this->access->owner($request, true);
        $input = $request->all();
        $input['reviewer_names'] = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('reviewer_names_text', '')))));
        $draft = $this->proposals->saveDraft($tenant, $request->user()->id, $input, $draftId);

        return redirect()->route('service-proposals.owner.edit', [$workspace, $draft->id])->with('status', 'Draft saved. Nothing was published or sent.');
    }

    public function asset(Request $request, string $workspace, string $kind, ?string $assetId = null)
    {
        $tenant = $this->access->owner($request, true);
        $input = $request->all();
        if ($kind === 'catalog') {
            $input['terms_months'] = $request->input('terms_months', []);
            $input['charges'] = [];
            foreach (['setup' => 'one_time', 'recurring' => $request->input('cadence')] as $key => $cadence) {
                $amount = trim((string) $request->input($key.'_amount', ''));
                if ($amount !== '') {
                    $request->validate([$key.'_amount' => ['regex:/^\d{1,6}(\.\d{1,2})?$/']]);
                    $parts = explode('.', $amount);
                    $input['charges'][] = ['label' => $request->input($key.'_label', $key === 'setup' ? 'One-time service' : 'Recurring service'), 'cadence' => $cadence,
                        'amount_cents' => ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0')];
                }
            }
        }
        $this->proposals->saveAsset($tenant, $request->user()->id, $kind, $input, $assetId);

        return redirect()->route('service-proposals.owner.index', [$workspace])->with('status', ucfirst($kind).' saved in this workspace.');
    }

    public function leesMcRae(Request $request, string $workspace)
    {
        $tenant = $this->access->owner($request, true);
        $draft = $this->proposals->leesMcRaeDraft($tenant, $request->user()->id);

        return redirect()->route('service-proposals.owner.edit', [$workspace, $draft->id]);
    }

    public function intake(Request $request, string $workspace)
    {
        $tenant = $this->access->owner($request, true);
        $data = $request->validate(['source' => ['required', 'in:ai_draft,voice_draft'], 'suggestion' => ['required', 'array']]);
        $draft = app(DraftIntake::class)->create($tenant, $request->user()->id, $data['source'], $data['suggestion']);

        return response()->json(['draft_id' => $draft->id, 'status' => 'draft', 'review_url' => route('service-proposals.owner.edit', [$workspace, $draft->id]), 'published' => false, 'sent' => false], 201);
    }

    public function preview(Request $request, string $workspace, string $draftId)
    {
        $tenant = $this->access->owner($request);
        $draft = $this->proposals->draft($tenant, $draftId);

        $financial = ! empty($draft->payload['client_details']) ? app(FinancialPlanning::class)->view($request, $draft->payload,
            'owner:'.$tenant->id.':'.$request->user()->id.':'.$draft->id.':'.$draft->revision,
            route('service-proposals.owner.financial', [$workspace, $draft->id])) : null;

        return response()->view('service-proposals.recipient', ['payload' => $draft->payload, 'draft' => $draft, 'preview' => true, 'version' => null, 'token' => null, 'financial' => $financial])->header('Cache-Control', 'private, no-store');
    }

    public function financial(Request $request, string $workspace, string $draftId)
    {
        $tenant = $this->access->owner($request);
        $draft = $this->proposals->draft($tenant, $draftId);
        abort_unless(! empty($draft->payload['client_details']), 403);
        $planning = app(FinancialPlanning::class);
        $redirect = redirect()->to(route('service-proposals.owner.preview', [$workspace, $draftId, 'section' => 'scope']).'#usage-estimator');
        try {
            $planning->apply($request, $draft->payload, 'owner:'.$tenant->id.':'.$request->user()->id.':'.$draft->id.':'.$draft->revision);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $redirect->withErrors(['financial' => $exception->validator->errors()->all()])->withInput($planning->feedback($request));
        }

        return $redirect;
    }

    public function publish(Request $request, string $workspace, string $draftId)
    {
        $tenant = $this->access->owner($request, true);
        $data = $request->validate(['revision' => ['required', 'integer'], 'password' => ['required', 'string', 'min:12', 'max:128'], 'days' => ['required', 'integer', 'min:1', 'max:30'], 'publish_key' => ['required', 'uuid'], 'confirm' => ['accepted'], 'audience' => ['required', 'in:client,reviewer']]);
        // Use trusted runtime configuration, never the owner URL or request Host.
        // Refuse a broken recipient handoff before creating an immutable version.
        $recipientBase = MountUrls::url('/service-proposals');
        abort_unless($recipientBase !== null
            && in_array(strtolower(parse_url($recipientBase, PHP_URL_HOST)), config('service_proposals.recipient_hosts', []), true),
            503, 'Configure a canonical HTTPS recipient host and mount before publishing.');

        $result = $this->proposals->publish($tenant, $request->user()->id, $draftId, (int) $data['revision'], $data['password'], (int) $data['days'], $data['publish_key'], $data['audience']);

        // Flash only the one-time token, never the recipient password.
        return redirect()->route('service-proposals.owner.edit', [$workspace, $draftId])->with('private_proposal_path', $recipientBase.'/'.$result['token']);
    }

    public function revoke(Request $request, string $workspace, string $versionId)
    {
        $tenant = $this->access->owner($request, true);
        Version::where('tenant_id', $tenant->id)->findOrFail($versionId)->update(['revoked_at' => now()]);

        return redirect()->route('service-proposals.owner.index', [$workspace])->with('status', 'Private access revoked. Published evidence is retained.');
    }
}
