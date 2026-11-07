<?php

namespace App\Http\Controllers;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Marketing\TenantCustomerLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerProfileController extends Controller
{
    public function __invoke(Request $request, TenantCustomerLookup $lookup, CustomerOutreachDraft $outreach)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && app(CustomerProfileAccess::class)->allows($request->user(), $tenant), 403);
        $user = $request->user();
        $membership = $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->first();
        $canManageSquare = false;
        try {
            app(\App\Services\Marketing\SquareCustomerIdentityService::class)->authorize($tenant->id, $user);
            $canManageSquare = true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }
        $id = $request->route('profile');
        $query = $request->validate(['query' => 'nullable|string|min:2|max:120', 'event_id' => 'nullable|integer|min:1', 'page' => 'sometimes|integer|min:1|max:100000']);
        if (! $id) {
            $cohort = isset($query['event_id']) ? app(EventCustomerCohort::class)->read($tenant->id, (int) $query['event_id']) : null;
            $results = trim($query['query'] ?? '') !== '' || $cohort ? $lookup->search($tenant->id, $query['query'] ?? '', $cohort['profile_ids'] ?? null, (int) ($query['page'] ?? 1)) : null;

            return response()->view('customers.directory', ['tenant' => $tenant, 'query' => $query, 'results' => $results, 'events' => app(EventCustomerCohort::class)->options($tenant->id), 'context' => $cohort, 'canManageSquare' => $canManageSquare])->header('Cache-Control', 'private, no-store');
        }
        $detail = $lookup->details($tenant->id, (int) $id);
        $profile = MarketingProfile::forTenantId($tenant->id)->findOrFail($detail['customer']['id']);
        // Provider/card provenance is a private operator projection, never part of
        // the shared shopper, QR, purchase-summary or personalized draft payload.
        $squareSource = $canManageSquare ? app(\App\Services\Marketing\SquareCustomerSource::class)->forStaff($profile, $tenant->id) : null;
        $preferences = app(\App\Services\Marketing\CustomerPreferenceService::class)->forStaff($profile, $tenant->id);
        $canEditPreferences = $user->email_verified_at && $membership && in_array($membership->pivot->role, ['owner', 'tenant_owner', 'admin', 'manager'], true);
        $canReviewAttribution = app(\App\Services\Marketing\CustomerCommerceLinkService::class)->canLink($user, $tenant->id);
        $journey = $canReviewAttribution ? app(\App\Services\Marketing\CustomerEventValueService::class)->forProfile($profile, $tenant->id) : null;
        $canPrepare = false;
        try {
            $outreach->authorize($tenant, $user, $profile);
            $canPrepare = true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
        }
        $input = ['request_id' => (string) Str::uuid(), 'channel' => 'email', 'subject' => 'A note from {{company_name}}', 'body' => 'Hi {{first_name}}, thanks for being part of {{company_name}}.', 'heading' => '', 'button_label' => '', 'button_url' => ''];
        $preview = null;
        if ($request->isMethod('post')) {
            abort_unless($canPrepare, 403);
            $request->validate(['request_id' => 'required|uuid']);
            $input = array_replace($input, $request->only(array_keys($input)));
            if ($request->input('action') === 'save') {
                $campaign = $outreach->save($tenant, $user, $profile, $input);

                return redirect()->route('customers.outreach.review', ['profile' => $profile->id, 'campaign' => $campaign->id, 'tenant' => $tenant->slug])->with('toast', ['style' => 'success', 'message' => 'Personalized draft saved for review. No message sent.']);
            }
            $preview = $outreach->preview($tenant, $profile, $input);
        }

        return response()->view('customers.profile', ['tenant' => $tenant, 'profile' => $profile, 'detail' => $detail, 'canPrepare' => $canPrepare, 'form' => $input, 'preview' => $preview, 'preferences' => $preferences, 'canEditPreferences' => (bool) $canEditPreferences, 'canReviewAttribution' => $canReviewAttribution, 'journey' => $journey, 'squareSource' => $squareSource, 'explanationHistory' => $canPrepare ? app(\App\Services\Marketing\CustomerOutreachExplanation::class)->history($profile) : [], 'outreachDrafts' => $canPrepare ? \App\Models\MarketingCampaign::forTenantId($tenant->id)->where('source_label', 'customer_profile')->where('target_snapshot->profile_id', $profile->id)->latest('id')->limit(20)->get() : collect()])->header('Cache-Control', 'private, no-store');
    }
}
