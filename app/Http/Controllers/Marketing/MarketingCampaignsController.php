<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketingCampaignsController extends Controller
{
    public function index(): View
    {
        $campaigns = MarketingCampaign::query()
            ->with('segment:id,name')
            ->withCount('recipients')
            ->orderByDesc('updated_at')
            ->paginate(30);

        return view('marketing/campaigns/index', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaigns' => $campaigns,
        ]);
    }

    public function create(Request $request): View
    {
        $prefillSegmentId = (int) $request->query('segment_id', 0);
        $prefillSegmentId = $prefillSegmentId > 0 ? $prefillSegmentId : null;

        return view('marketing/campaigns/form', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaign' => new MarketingCampaign([
                'status' => 'draft',
                'channel' => 'sms',
                'objective' => 'winback',
                'attribution_window_days' => 7,
                'segment_id' => $prefillSegmentId,
            ]),
            'segments' => MarketingSegment::query()->whereIn('status', ['active', 'draft'])->orderBy('name')->get(['id', 'name']),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedCampaign($request);

        $campaign = MarketingCampaign::query()->create([
            ...$data,
            'slug' => Str::slug($data['name']),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Campaign created.']);
    }

    public function edit(MarketingCampaign $campaign): View
    {
        return view('marketing/campaigns/form', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaign' => $campaign,
            'segments' => MarketingSegment::query()->whereIn('status', ['active', 'draft'])->orderBy('name')->get(['id', 'name']),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $data = $this->validatedCampaign($request);
        $campaign->fill([
            ...$data,
            'slug' => Str::slug($data['name']),
            'updated_by' => auth()->id(),
        ])->save();

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Campaign updated.']);
    }

    public function show(MarketingCampaign $campaign): View
    {
        $campaign->load([
            'segment:id,name',
            'variants.template:id,name',
            'recommendations' => fn ($query) => $query->orderByDesc('id')->limit(10),
        ]);

        $recipientSummary = $campaign->recipients()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $approvalQueue = $campaign->recipients()
            ->with(['profile:id,first_name,last_name,email,phone', 'variant:id,name'])
            ->whereIn('status', ['queued_for_approval', 'approved', 'rejected'])
            ->orderByRaw("case when status = 'queued_for_approval' then 0 when status='approved' then 1 else 2 end")
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return view('marketing/campaigns/show', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaign' => $campaign,
            'recipientSummary' => $recipientSummary,
            'approvalQueue' => $approvalQueue,
            'templates' => MarketingMessageTemplate::query()
                ->where('is_active', true)
                ->where('channel', $campaign->channel)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function prepareRecipients(
        MarketingCampaign $campaign,
        Request $request,
        MarketingCampaignAudienceBuilder $audienceBuilder
    ): RedirectResponse {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $summary = $audienceBuilder->prepareRecipients($campaign, $data['limit'] ?? null);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'success',
                'message' => "Recipients prepared. queued={$summary['queued_for_approval']} skipped={$summary['skipped']}",
            ]);
    }

    public function addVariant(MarketingCampaign $campaign, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'variant_key' => ['nullable', 'string', 'max:20'],
            'template_id' => ['nullable', 'integer', 'exists:marketing_message_templates,id'],
            'message_text' => ['required', 'string', 'max:5000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_control' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,active,paused'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        MarketingCampaignVariant::query()->create([
            'campaign_id' => $campaign->id,
            'name' => $data['name'],
            'variant_key' => trim((string) ($data['variant_key'] ?? '')) ?: null,
            'template_id' => $data['template_id'] ?? null,
            'message_text' => $data['message_text'],
            'weight' => (int) ($data['weight'] ?? 100),
            'is_control' => (bool) ($data['is_control'] ?? false),
            'status' => (string) ($data['status'] ?? 'draft'),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Variant added.']);
    }

    public function updateVariant(
        MarketingCampaign $campaign,
        MarketingCampaignVariant $variant,
        Request $request
    ): RedirectResponse {
        abort_unless((int) $variant->campaign_id === (int) $campaign->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'variant_key' => ['nullable', 'string', 'max:20'],
            'template_id' => ['nullable', 'integer', 'exists:marketing_message_templates,id'],
            'message_text' => ['required', 'string', 'max:5000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_control' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,active,paused'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $variant->fill([
            'name' => $data['name'],
            'variant_key' => trim((string) ($data['variant_key'] ?? '')) ?: null,
            'template_id' => $data['template_id'] ?? null,
            'message_text' => $data['message_text'],
            'weight' => (int) ($data['weight'] ?? 100),
            'is_control' => (bool) ($data['is_control'] ?? false),
            'status' => (string) ($data['status'] ?? 'draft'),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Variant updated.']);
    }

    public function approveRecipient(
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
        Request $request,
        MarketingApprovalService $approvalService
    ): RedirectResponse {
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approvalService->approveRecipient($recipient, (int) auth()->id(), $data['notes'] ?? null);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Recipient approved.']);
    }

    public function rejectRecipient(
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
        Request $request,
        MarketingApprovalService $approvalService
    ): RedirectResponse {
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approvalService->rejectRecipient($recipient, (int) auth()->id(), $data['notes'] ?? null);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'warning', 'message' => 'Recipient rejected.']);
    }

    public function generateRecommendations(
        MarketingCampaign $campaign,
        MarketingRecommendationEngine $engine
    ): RedirectResponse {
        $result = $engine->generateForCampaign($campaign);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => "Generated {$result['created']} recommendations."]);
    }

    public function addProfileRecipient(
        MarketingCampaign $campaign,
        Request $request,
        MarketingRecommendationEngine $recommendationEngine
    ): RedirectResponse {
        $data = $request->validate([
            'marketing_profile_id' => ['required', 'integer', 'exists:marketing_profiles,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'variant_id' => ['nullable', 'integer', 'exists:marketing_campaign_variants,id'],
        ]);

        /** @var MarketingProfile $profile */
        $profile = MarketingProfile::query()->findOrFail((int) $data['marketing_profile_id']);

        $existing = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('marketing_profile_id', $profile->id)
            ->first();

        [$status, $reasons] = $this->eligibilityForChannel($profile, (string) $campaign->channel);
        $reasons[] = 'manual_add';
        if ($existing && in_array($existing->status, ['approved', 'rejected'], true)) {
            $status = $existing->status;
        }

        $variantId = null;
        if (!empty($data['variant_id'])) {
            $variantId = $campaign->variants()
                ->where('id', (int) $data['variant_id'])
                ->exists()
                ? (int) $data['variant_id']
                : null;
        }
        if ($variantId === null) {
            $variantId = $this->defaultVariantIdForCampaign($campaign);
        }

        $recipient = MarketingCampaignRecipient::query()->updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'marketing_profile_id' => $profile->id,
            ],
            [
                'segment_snapshot' => [
                    'segment_id' => $campaign->segment_id,
                    'segment_name' => $campaign->segment?->name,
                    'matched_at' => now()->toIso8601String(),
                    'reasons' => ['manual_add'],
                ],
                'recommendation_snapshot' => [
                    'score' => $profile->marketing_score,
                    'manual_add' => true,
                ],
                'variant_id' => $variantId,
                'channel' => $campaign->channel,
                'status' => $status,
                'reason_codes' => array_values(array_unique(array_filter($reasons))),
                'last_status_note' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]
        );

        if ($recipient->status === 'queued_for_approval') {
            $recommendationEngine->generateSendSuggestionForProfile($profile, $campaign);
        }

        return redirect()
            ->back()
            ->with('toast', [
                'style' => $recipient->status === 'queued_for_approval' ? 'success' : 'warning',
                'message' => $recipient->status === 'queued_for_approval'
                    ? 'Profile queued for approval.'
                    : 'Profile added but not eligible for this channel.',
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedCampaign(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:draft,ready_for_review,active,paused,completed,archived'],
            'channel' => ['required', 'in:sms,email'],
            'segment_id' => ['nullable', 'integer', 'exists:marketing_segments,id'],
            'objective' => ['nullable', 'in:winback,repeat_purchase,event_followup,consent_capture,review_request'],
            'attribution_window_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'coupon_code' => ['nullable', 'string', 'max:120'],
            'send_window_start' => ['nullable', 'date_format:H:i'],
            'send_window_end' => ['nullable', 'date_format:H:i'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        $sendWindow = null;
        if (!empty($data['send_window_start']) || !empty($data['send_window_end'])) {
            $sendWindow = [
                'start' => $data['send_window_start'] ?? null,
                'end' => $data['send_window_end'] ?? null,
            ];
        }

        $quietHours = null;
        if (!empty($data['quiet_hours_start']) || !empty($data['quiet_hours_end'])) {
            $quietHours = [
                'start' => $data['quiet_hours_start'] ?? null,
                'end' => $data['quiet_hours_end'] ?? null,
            ];
        }

        return [
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'status' => (string) $data['status'],
            'channel' => (string) $data['channel'],
            'segment_id' => $data['segment_id'] ?? null,
            'objective' => $data['objective'] ?? null,
            'attribution_window_days' => (int) ($data['attribution_window_days'] ?? 7),
            'coupon_code' => trim((string) ($data['coupon_code'] ?? '')) ?: null,
            'send_window_json' => $sendWindow,
            'quiet_hours_override_json' => $quietHours,
        ];
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    protected function eligibilityForChannel(MarketingProfile $profile, string $channel): array
    {
        $channel = strtolower(trim($channel));
        $reasons = [];
        $eligible = true;

        if ($channel === 'sms') {
            if (! $profile->accepts_sms_marketing) {
                $eligible = false;
                $reasons[] = 'sms_not_consented';
            }
            if (! $profile->normalized_phone) {
                $eligible = false;
                $reasons[] = 'missing_phone';
            }
        } elseif ($channel === 'email') {
            if (! $profile->accepts_email_marketing) {
                $eligible = false;
                $reasons[] = 'email_not_consented';
            }
            if (! $profile->normalized_email) {
                $eligible = false;
                $reasons[] = 'missing_email';
            }
        }

        return [$eligible ? 'queued_for_approval' : 'skipped', $reasons];
    }

    protected function defaultVariantIdForCampaign(MarketingCampaign $campaign): ?int
    {
        $variant = $campaign->variants()
            ->whereIn('status', ['active', 'draft'])
            ->orderByDesc('is_control')
            ->orderByDesc('weight')
            ->orderBy('id')
            ->first(['id']);

        return $variant ? (int) $variant->id : null;
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
            ];
        }

        return $items;
    }
}
