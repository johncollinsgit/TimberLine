<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnection;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeResource;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionEvidence;
use App\Services\Subscriptions\SubscriptionRechargeManualSettingsService;
use App\Services\Subscriptions\SubscriptionRechargeReadinessService;
use App\Services\Subscriptions\SubscriptionRechargeReadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionRechargeController extends Controller
{
    public function index(Request $request, SubscriptionRechargeReadService $reads, SubscriptionRechargeReadinessService $drafts, SubscriptionRechargeManualSettingsService $manual): View
    {
        $tenant = $this->tenant($request);
        $filters = $request->validate(['connection' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(['customer', 'address', 'subscription', 'store', 'payment_method'])],
            'q' => ['nullable', 'string', 'max:100']]);
        $connections = SubscriptionRechargeConnection::query()->forTenantId($tenant->id)->orderBy('id')->get();
        $connection = isset($filters['connection']) ? $connections->firstWhere('id', (int) $filters['connection']) : $connections->first();
        abort_if(isset($filters['connection']) && ! $connection, 404);
        $summary = $connection ? $drafts->summary($tenant->id, $request->user(), $connection->id) : null;
        $readiness = $connection ? $reads->readiness($tenant->id, $connection->id) : null;
        $type = $filters['type'] ?? 'customer';
        $resources = SubscriptionRechargeResource::query()->forTenantId($tenant->id)->where('connection_id', $connection?->id ?? 0)
            ->where('generation_id', $summary['generation_id'] ?? 'no-complete-capture')->where('resource_type', $type);
        if (filled($filters['q'] ?? null)) {
            $resources->where('provider_id', $filters['q']);
        }

        return view('subscriptions.recharge', ['tenant' => $tenant, 'connections' => $connections, 'connection' => $connection,
            'summary' => $summary, 'readiness' => $readiness, 'type' => $type, 'filters' => $filters,
            'manualSettings' => $connection ? $manual->summary($tenant->id, $request->user(), $connection->id) : null,
            'resources' => $resources->orderBy('id')->paginate(25)->withQueryString(), 'readsEnabled' => $reads->readsEnabled($tenant->id),
            'sources' => IntegrationConnection::query()->where('tenant_id', $tenant->id)->where('provider', 'recharge')
                ->where('metadata->subscription_shadow_setup', true)->orderByDesc('id')->limit(20)->get(),
            'requestUuid' => (string) Str::uuid()]);
    }

    /** Explicit local credential draft; the token never enters flash, ordinary metadata or response. */
    public function source(Request $request, SubscriptionRechargeReadService $reads): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $validator = Validator::make($request->only(['provider_store_id', 'identifier', 'platform', 'recharge_access_token', 'request_uuid']), [
            'provider_store_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'identifier' => ['required', 'string', 'max:190'], 'platform' => ['required', Rule::in(['shopify'])],
            'recharge_access_token' => ['required', 'string', 'min:16', 'max:4096', 'regex:/^[\x21-\x7E]+$/D'],
            'request_uuid' => ['required', 'uuid'],
        ]);
        // Avoid Laravel's automatic validation redirect: it flashes every input
        // except standard passwords, which would expose a new API-token field.
        if ($validator->fails()) {
            return $this->back($tenant)->withErrors(['setup' => 'Review the expected Recharge store identity and private API credential.']);
        }
        $data = $validator->validated();
        $identity = ['provider_store_id' => $data['provider_store_id'], 'identifier' => $data['identifier'], 'platform' => $data['platform']];
        $source = DB::transaction(function () use ($tenant, $request, $data, $identity) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $query = IntegrationConnection::query()->where('tenant_id', $tenant->id)->where('provider', 'recharge');
            $old = $query->where('metadata->subscription_source_request_uuid', strtolower($data['request_uuid']))->first();
            if ($old) {
                abort_unless((int) $old->connected_by_user_id === (int) $request->user()->id
                    && is_array($old->metadata['expected_identity'] ?? null)
                    && SubscriptionEvidence::canonical($old->metadata['expected_identity'] ?? []) === SubscriptionEvidence::canonical($identity)
                    && hash_equals((string) $old->access_token, $data['recharge_access_token']), 409, 'The setup request conflicts with its original draft.');

                return $old;
            }

            return IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => 'recharge',
                'external_account_id' => 'recharge-shadow-draft:'.strtolower($data['request_uuid']), 'external_account_label' => 'Recharge subscription source',
                'status' => IntegrationConnection::STATUS_PENDING, 'access_token' => $data['recharge_access_token'],
                'connected_by_user_id' => $request->user()->id,
                'metadata' => ['subscription_shadow_setup' => true, 'expected_identity' => $identity,
                    'subscription_source_request_uuid' => strtolower($data['request_uuid'])]]);
        }, 3);
        unset($data['recharge_access_token']);

        return $this->back($tenant)->with('recharge_notice', 'Private credential draft saved. Verify the expected store before importing. Billing and customer messages remain off.');
    }

    public function verify(Request $request, SubscriptionRechargeReadService $reads): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['source_id' => ['required', 'integer', 'min:1'], 'request_uuid' => ['required', 'uuid']]);
        $source = IntegrationConnection::query()->where('tenant_id', $tenant->id)->where('provider', 'recharge')->whereKey($data['source_id'])->firstOrFail();
        abort_unless(($source->metadata['subscription_shadow_setup'] ?? false) === true, 404);
        try {
            $result = $reads->verifyConnection($tenant->id, $request->user(), $source->id, $source->metadata['expected_identity'] ?? [], $data['request_uuid']);
        } catch (\App\Services\Subscriptions\SubscriptionProviderReadException) {
            return $this->back($tenant)->withErrors(['setup' => 'Recharge verification could not finish. Check the expected store, read scopes and credential. Existing verified records are retained.']);
        }

        return $this->back($tenant, $result['readiness']['connection_id'] ?? null)->with('recharge_notice', 'Recharge identity checked. The connection supports source preparation only; billing and messages remain off.');
    }

    public function capture(Request $request, SubscriptionRechargeReadService $reads): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['connection_id' => ['required', 'integer', 'min:1'], 'request_uuid' => ['required', 'uuid'], 'restart' => ['sometimes', 'boolean']]);
        $result = $reads->sync($tenant->id, $request->user(), (int) $data['connection_id'], $data['request_uuid'], ['limit' => 25, 'restart' => (bool) ($data['restart'] ?? false)]);
        $message = $result['status'] !== 'completed' ? 'The source batch could not finish. The last complete capture is retained for review.'
            : ($result['sync_complete'] ? 'The complete source capture passed its repeated-read and relationship checks. Prepare its local draft mapping next.' : 'Source batch saved. Continue until collection and verification both finish; the accepted totals remain unchanged meanwhile.');

        return $this->back($tenant, (int) $data['connection_id'])->with('recharge_notice', $message);
    }

    public function prepare(Request $request, SubscriptionRechargeReadinessService $drafts): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['connection_id' => ['required', 'integer', 'min:1'], 'generation_id' => ['required', 'uuid']]);
        $result = $drafts->prepareBatch($tenant->id, $request->user(), (int) $data['connection_id'], $data['generation_id']);

        return $this->back($tenant, (int) $data['connection_id'])->with('recharge_notice', $result['prepared_count'].' local drafts prepared; '.$result['remaining_count'].' remain. This does not activate billing or prove full settings parity.');
    }

    public function settings(Request $request, string $category, SubscriptionRechargeManualSettingsService $manual): View
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['connection' => ['required', 'integer', 'min:1']]);
        $record = $manual->latestForReview($tenant->id, $request->user(), (int) $data['connection'], $category);
        abort_unless($record, 404);

        return view('subscriptions.recharge-settings', ['tenant' => $tenant, 'record' => $record,
            'backUrl' => route('subscriptions.recharge.index', ['tenant' => $tenant->id, 'connection' => $data['connection']])]);
    }

    public function show(Request $request, int $resource, SubscriptionRechargeReadinessService $drafts): View
    {
        $tenant = $this->tenant($request);
        $row = SubscriptionRechargeResource::query()->forTenantId($tenant->id)->whereKey($resource)->firstOrFail();
        $summary = $drafts->summary($tenant->id, $request->user(), $row->connection_id);
        abort_unless($summary['generation_id'] === $row->generation_id, 404);
        $source = $row->snapshot ?? [];
        unset($source['_coverage']);
        $candidate = $row->mapping['candidate'] ?? [];

        return view('subscriptions.recharge-record', ['tenant' => $tenant, 'resource' => $row, 'source' => $source, 'candidate' => $candidate,
            'hasCandidate' => is_array($row->mapping), 'issues' => $row->mapping['issues'] ?? [],
            'backUrl' => route('subscriptions.recharge.index', ['tenant' => $tenant->id, 'connection' => $row->connection_id, 'type' => $row->resource_type])]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        app(SubscriptionAccessService::class)->assertManager($tenant->id, $request->user());

        return $tenant;
    }

    private function back(Tenant $tenant, ?int $connection = null): RedirectResponse
    {
        return redirect()->route('subscriptions.recharge.index', array_filter(['tenant' => $tenant->id, 'connection' => $connection]));
    }
}
