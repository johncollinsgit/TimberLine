<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Models\Tenant;
use App\Services\Marketing\CustomerCommerceLinkService;
use App\Services\Marketing\CustomerLocationDistance;
use App\Services\Marketing\EventAudienceDefinition;
use App\Services\Marketing\MarketingEmailUnsubscribeService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Services\Marketing\MarketingSmsEligibilityService;
use App\Services\PaymentsCore\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EventAudiencesController extends Controller
{
    /** MySQL may reorder JSON object keys; ordered filters and JSON values remain part of the intent. */
    private function rulesHash(array $rules): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };

        return Contract::hash($canonical($rules));
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $request->user()?->hasVerifiedEmail()
            && app(CustomerCommerceLinkService::class)->canLink($request->user(), $tenant->id), 403);
        app(\App\Services\Marketing\SquareCustomerIdentityService::class)->authorize($tenant->id, $request->user());

        return $tenant;
    }

    private function input(Request $request): array
    {
        return $request->validate(['kind' => 'required|in:event_buyers,captured_contacts,product_buyers,scent_buyers,previous_show_nearby,area_buyers_nearby',
            'event_id' => 'nullable|integer|min:1', 'series_id' => 'nullable|uuid', 'legacy_ids' => 'nullable|array|max:100', 'legacy_ids.*' => 'string|max:100',
            'item_key' => 'nullable|string|max:190', 'postal_code' => 'nullable|regex:/^[0-9]{5}$/D', 'radius_miles' => 'nullable|numeric|min:1|max:500']);
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $options = app(EventAudienceDefinition::class)->options($tenant->id);
        $segment = $request->filled('segment') ? MarketingSegment::forTenantId($tenant->id)->findOrFail((int) $request->query('segment')) : null;
        abort_if($segment && ! isset($segment->rules_json['_event_audience']), 404);
        $filters = $segment?->rules_json['_event_audience']['filters'] ?? [];
        $version = $segment ? Contract::hash([$segment->rules_json, $segment->name, $segment->status, (string) $segment->updated_at]) : null;
        $event = collect($options['events'])->firstWhere('id', (int) ($filters['event_id'] ?? $request->query('event_id')));
        $metadata = $event ? app(\App\Services\Inventory\StockLedger::class)->rows('event_links', $tenant->id)->where('event_id', $event['id'])->whereNotNull('reviewed_by')->first() : null;
        $postal = $metadata ? (json_decode($metadata->metadata, true)['postal_code'] ?? '') : '';

        return response()->view('marketing.event-audiences', compact('tenant', 'options', 'event', 'postal', 'segment', 'filters', 'version'))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request)
    {
        $tenant = $this->tenant($request);
        $input = $this->input($request);
        $request->validate(['preview_id' => 'required|uuid']);
        $rules = app(EventAudienceDefinition::class)->rules($tenant->id, $input);
        $options = app(EventAudienceDefinition::class)->optionsForInput($tenant->id, $input);
        $hash = Contract::hash($rules);
        $key = 'event-audience-preview:'.hash('sha256', implode(':', [$tenant->id, $request->user()->id, $request->session()->getId(), $request->input('preview_id')]));
        $result = Cache::lock($key.':lock', 120)->block(5, function () use ($tenant, $rules, $key, $hash, $input, $options): array {
            $state = Cache::get($key);
            $cohort = null;
            if (! $state) {
                $ids = app(EventAudienceDefinition::class)->candidateIds($tenant->id, $input, $cohort);
                $missingLocations = Cache::remember('audience-location-coverage-v1:'.$tenant->id, 5,
                    fn () => app(CustomerLocationDistance::class)->missingProfileCount($tenant->id));
                $state = ['hash' => $hash, 'ids' => $ids, 'offset' => 0, 'matched' => 0, 'email_eligible' => 0, 'sms_eligible' => 0,
                    'missing_location' => 0, 'workspace_missing_locations' => $missingLocations, 'missing_email_permission' => 0, 'missing_sms_permission' => 0, 'samples' => [], 'review_items' => [], 'as_of' => now()->toIso8601String()];
            }
            abort_unless(hash_equals($state['hash'], $hash), 409, 'The filters changed. Start a new audience preview.');
            $batch = array_slice($state['ids'], $state['offset'], 100);
            $profiles = MarketingProfile::forTenantId($tenant->id)->whereIn('id', $batch)->whereNull('archived_at')->whereNull('merged_into_profile_id')->get();
            $sms = app(MarketingSmsEligibilityService::class)->evaluateProfiles($profiles, $tenant->id);
            $segment = new MarketingSegment(['tenant_id' => $tenant->id, 'rules_json' => $rules]);
            $relation = match ($input['kind']) {
                'event_buyers' => 'purchased', 'captured_contacts' => 'captured', default => null
            };
            if ($relation && $batch) {
                $cohort ??= app(\App\Services\Marketing\EventCustomerCohort::class)->read($tenant->id, (int) $input['event_id'], selectedEvidence: true);
                $state['review_items'] = [...$state['review_items'], ...$cohort['review_items']];
            }
            foreach ($profiles as $profile) {
                // These exact typed rules contain only the selected event relationship.
                // The shared cohort already proved it through the original purchase readers.
                $evaluated = $relation ? ['matched' => isset($cohort['relations'][$profile->id][$relation]), 'metrics' => [], 'reasons' => []]
                    : app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile);
                if (! app(CustomerLocationDistance::class)->point($profile->postal_code, $profile->country)) {
                    $state['missing_location']++;
                }
                if (! $evaluated['matched']) {
                    continue;
                }
                $state['matched']++;
                $risk = app(\App\Services\Marketing\SquareCustomerSource::class)->legacyConsentRisk($profile);
                $email = $profile->accepts_email_marketing && $profile->email_opted_out_at === null && ! $risk['email']['review_required'] && filled($profile->normalized_email)
                    && ! app(MarketingEmailUnsubscribeService::class)->suppressed($tenant->id, $profile->normalized_email);
                $smsEligible = ($sms->get($profile->id)['eligible'] ?? false) === true && $profile->sms_opted_out_at === null && ! $risk['sms']['review_required'];
                $state['email_eligible'] += (int) $email;
                $state['sms_eligible'] += (int) $smsEligible;
                $state['missing_email_permission'] += (int) ! $email;
                $state['missing_sms_permission'] += (int) ! $smsEligible;
                if (count($state['samples']) < 25) {
                    $state['samples'][] = ['id' => $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone ?: 'Customer'),
                        'reasons' => app(EventAudienceDefinition::class)->reasons($input, $evaluated['metrics'], $options, $evaluated['reasons']), 'email_eligible' => $email, 'sms_eligible' => $smsEligible];
                }
                if ($evaluated['metrics']['purchase_coverage']['has_more'] ?? false) {
                    $state['review_items'][] = 'Some customer history exceeds the linked reporting window.';
                }
            }
            $state['offset'] += count($batch);
            $state['review_items'] = array_values(array_unique($state['review_items']));
            Cache::put($key, $state, 1200);

            return [...array_diff_key($state, ['ids' => true, 'hash' => true]), 'candidates' => count($state['ids']), 'complete' => $state['offset'] >= count($state['ids']),
                'basis' => 'Current explicitly linked confirmed purchases and saved locations. Eligibility includes recorded permissions and suppressions; sender readiness is checked when preparing a future message. ZIP distance is approximate.'];
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store')->header('X-Audience-Viewer', (string) $request->user()->id)->header('X-Audience-Tenant', (string) $tenant->id);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $input = $this->input($request);
        $request->validate(['name' => 'required|string|max:180', 'request_id' => 'required|uuid', 'segment_id' => 'nullable|integer|min:1', 'expected_version' => 'nullable|string|size:64']);
        $rules = app(EventAudienceDefinition::class)->rules($tenant->id, $input);
        $segment = \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $request, $rules) {
            if ($request->filled('segment_id')) {
                $segment = MarketingSegment::forTenantId($tenant->id)->whereKey($request->integer('segment_id'))->lockForUpdate()->firstOrFail();
                abort_unless(isset($segment->rules_json['_event_audience']), 404);
                $version = Contract::hash([$segment->rules_json, $segment->name, $segment->status, (string) $segment->updated_at]);
                if ($this->rulesHash($segment->rules_json) === $this->rulesHash($rules) && $segment->name === trim($request->input('name'))) {
                    return $segment;
                }
                abort_unless(is_string($request->input('expected_version')) && hash_equals($version, $request->input('expected_version')), 409, 'The saved audience changed. Refresh it before editing.');
                $segment->update(['name' => trim($request->input('name')), 'rules_json' => $rules, 'updated_by' => $request->user()->id]);

                return $segment;
            }
            $slug = 'event-audience-'.strtolower($request->input('request_id'));
            $segment = MarketingSegment::forTenantId($tenant->id)->firstOrCreate(['slug' => $slug], ['tenant_id' => $tenant->id,
                'name' => trim($request->input('name')), 'status' => 'draft', 'channel_scope' => 'any', 'rules_json' => $rules,
                'is_system' => false, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            abort_unless($this->rulesHash($segment->rules_json) === $this->rulesHash($rules) && $segment->name === trim($request->input('name')), 409, 'The original audience has different filters. Create a new audience.');

            return $segment;
        });

        return redirect()->route('marketing.event-audiences', ['segment' => $segment->id, 'tenant' => $tenant->slug])->with('toast', ['style' => 'success', 'message' => 'Dynamic audience saved. No messages were sent.']);

    }
}
