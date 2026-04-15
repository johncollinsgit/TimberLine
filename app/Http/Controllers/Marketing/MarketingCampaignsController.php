<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashRedemption;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingGroup;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingCampaignDeliveryDiagnostics;
use App\Services\Marketing\MarketingCampaignRewardIssuanceService;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\MarketingPerformanceAnalyticsService;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingSmsEligibilityService;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MarketingTenantOwnershipService;
use App\Services\Marketing\MarketingTimingRecommendationService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingCampaignsController extends Controller
{
    public function __construct(
        protected MarketingTenantOwnershipService $ownershipService
    ) {
    }

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        $strict = $this->strictTenantMode();

        $campaignsQuery = MarketingCampaign::query()
            ->with('segment:id,name')
            ->withCount([
                'recipients as recipients_count' => function ($query) use ($strict, $tenantId): void {
                    if ($strict && $tenantId !== null) {
                        $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
                    }
                },
            ])
            ->orderByDesc('updated_at');

        if ($strict && $tenantId !== null) {
            $campaignIds = $this->ownershipService->tenantCampaignIds($tenantId);
            if ($campaignIds->isEmpty()) {
                $campaignsQuery->whereRaw('1 = 0');
            } else {
                $campaignsQuery->whereIn('id', $campaignIds->all());
            }
        }

        $campaigns = $campaignsQuery->paginate(30);

        return view('marketing/campaigns/index', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaigns' => $campaigns,
        ]);
    }

    public function create(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        $strict = $this->strictTenantMode();
        $prefillSegmentId = (int) $request->query('segment_id', 0);
        $prefillSegmentId = $prefillSegmentId > 0 ? $prefillSegmentId : null;

        $segmentIds = collect();
        $groupIds = collect();
        if ($strict && $tenantId !== null) {
            $segmentIds = $this->ownershipService->tenantSegmentIds($tenantId);
            $groupIds = $this->ownershipService->tenantGroupIds($tenantId);
            if ($prefillSegmentId !== null && ! $segmentIds->contains($prefillSegmentId)) {
                $prefillSegmentId = null;
            }
        }

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
            'segments' => $this->segmentsQueryForTenant($strict, $segmentIds)->get(['id', 'name']),
            'groups' => $this->groupsQueryForTenant($strict, $groupIds)->get(['id', 'name', 'is_internal']),
            'selectedGroupIds' => [],
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $strict = $this->strictTenantMode();
        $data = $this->validatedCampaign($request);
        $groupIds = collect((array) ($data['group_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        unset($data['group_ids']);

        if ($strict && $tenantId !== null) {
            if ($groupIds === []) {
                throw ValidationException::withMessages([
                    'group_ids' => 'Campaign creation requires at least one tenant-owned group.',
                ]);
            }

            $allowedGroupIds = $this->ownershipService->tenantGroupIds($tenantId);
            $invalidGroupId = collect($groupIds)->first(fn (int $id): bool => ! $allowedGroupIds->contains($id));
            if ($invalidGroupId !== null) {
                throw ValidationException::withMessages([
                    'group_ids' => 'One or more selected groups are outside tenant ownership scope.',
                ]);
            }

            $segmentId = isset($data['segment_id']) && is_numeric($data['segment_id'])
                ? (int) $data['segment_id']
                : null;
            if ($segmentId !== null && $segmentId > 0 && ! $this->ownershipService->segmentOwnedByTenant($segmentId, $tenantId)) {
                throw ValidationException::withMessages([
                    'segment_id' => 'Selected segment is outside tenant ownership scope.',
                ]);
            }
        }

        $campaign = MarketingCampaign::query()->create([
            ...$data,
            'tenant_id' => $tenantId,
            'slug' => Str::slug($data['name']),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        $campaign->groups()->sync($groupIds);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Campaign created.']);
    }

    public function edit(Request $request, MarketingCampaign $campaign): View
    {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $strict = $this->strictTenantMode();
        $segmentIds = collect();
        $groupIds = collect();
        if ($strict && $tenantId !== null) {
            $segmentIds = $this->ownershipService->tenantSegmentIds($tenantId);
            $groupIds = $this->ownershipService->tenantGroupIds($tenantId);
        }

        return view('marketing/campaigns/form', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaign' => $campaign,
            'segments' => $this->segmentsQueryForTenant($strict, $segmentIds)->get(['id', 'name']),
            'groups' => $this->groupsQueryForTenant($strict, $groupIds)->get(['id', 'name', 'is_internal']),
            'selectedGroupIds' => $campaign->groups()->pluck('marketing_groups.id')->map(fn ($id) => (int) $id)->all(),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);
        $strict = $this->strictTenantMode();

        $data = $this->validatedCampaign($request);
        $groupIds = collect((array) ($data['group_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        unset($data['group_ids']);

        if ($strict && $tenantId !== null) {
            $allowedGroupIds = $this->ownershipService->tenantGroupIds($tenantId);
            $invalidGroupId = collect($groupIds)->first(fn (int $id): bool => ! $allowedGroupIds->contains($id));
            if ($invalidGroupId !== null) {
                throw ValidationException::withMessages([
                    'group_ids' => 'One or more selected groups are outside tenant ownership scope.',
                ]);
            }

            $segmentId = isset($data['segment_id']) && is_numeric($data['segment_id'])
                ? (int) $data['segment_id']
                : null;
            if ($segmentId !== null && $segmentId > 0 && ! $this->ownershipService->segmentOwnedByTenant($segmentId, $tenantId)) {
                throw ValidationException::withMessages([
                    'segment_id' => 'Selected segment is outside tenant ownership scope.',
                ]);
            }
        }

        $campaign->fill([
            ...$data,
            'slug' => Str::slug($data['name']),
            'updated_by' => auth()->id(),
        ])->save();
        $campaign->groups()->sync($groupIds);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', ['style' => 'success', 'message' => 'Campaign updated.']);
    }

    public function show(
        Request $request,
        MarketingCampaign $campaign,
        MarketingPerformanceAnalyticsService $performanceAnalyticsService,
        MarketingTimingRecommendationService $timingRecommendationService,
        MarketingEmailReadiness $readinessService,
        MarketingCampaignDeliveryDiagnostics $diagnosticsService,
        MarketingCampaignRewardIssuanceService $campaignRewardIssuanceService
    ): View
    {
        $tenantId = $this->resolveTenantId($request);
        $strict = $this->strictTenantMode();
        $this->assertCampaignAccess($campaign, $tenantId);

        $campaign->load([
            'segment:id,name',
            'groups:id,name,is_internal',
            'variants.template:id,name',
            'recommendations' => fn ($query) => $query->orderByDesc('id')->limit(10),
        ]);

        $recipientSummaryQuery = $campaign->recipients();
        if ($strict && $tenantId !== null) {
            $recipientSummaryQuery->whereHas('profile', fn ($query) => $query->forTenantId($tenantId));
        }
        $recipientSummary = $recipientSummaryQuery
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $approvalQueueQuery = $campaign->recipients()
            ->with(['profile:id,first_name,last_name,email,phone', 'variant:id,name', 'latestDelivery', 'latestEmailDelivery'])
            ->whereIn('status', ['queued_for_approval', 'approved', 'rejected', 'sending', 'sent', 'delivered', 'failed', 'undelivered', 'converted'])
            ->orderByRaw("case when status = 'queued_for_approval' then 0 when status='approved' then 1 when status='sending' then 2 when status='failed' then 3 else 4 end")
            ->orderByDesc('updated_at')
            ->limit(200);
        if ($strict && $tenantId !== null) {
            $approvalQueueQuery->whereHas('profile', fn ($query) => $query->forTenantId($tenantId));
        }
        $approvalQueue = $approvalQueueQuery->get();

        $deliveryLogQuery = $campaign->deliveries()
            ->with(['profile:id,first_name,last_name,email,phone', 'variant:id,name'])
            ->orderByDesc('id')
            ->limit(200);
        if ($strict && $tenantId !== null) {
            $deliveryLogQuery->whereHas('profile', fn ($query) => $query->forTenantId($tenantId));
        }
        $deliveryLog = $deliveryLogQuery->get();
        $emailDeliveryLog = MarketingEmailDelivery::query()
            ->whereIn('marketing_campaign_recipient_id', function ($query) use ($campaign, $strict, $tenantId): void {
                $query->select('mcr.id')
                    ->from('marketing_campaign_recipients as mcr')
                    ->where('mcr.campaign_id', $campaign->id);
                if ($strict && $tenantId !== null) {
                    $query->join('marketing_profiles as mp', 'mp.id', '=', 'mcr.marketing_profile_id')
                        ->where('mp.tenant_id', $tenantId);
                }
            })
            ->with(['profile:id,first_name,last_name,email,phone', 'recipient:id,status'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $emailReadiness = $readinessService->summary($tenantId);
        $diagnostics = $diagnosticsService->summarize($campaign, $emailReadiness, $emailDeliveryLog);
        $rewardIssuanceSummary = $campaignRewardIssuanceService->summaryForCampaign($campaign, $tenantId, 5);

        $conversionsQuery = $campaign->conversions();
        if ($strict && $tenantId !== null) {
            $conversionsQuery->whereHas('profile', fn ($query) => $query->forTenantId($tenantId));
        }

        $conversionSummary = [
            'count' => (int) (clone $conversionsQuery)->count(),
            'revenue' => (float) (clone $conversionsQuery)->sum('order_total'),
            'types' => (clone $conversionsQuery)
                ->selectRaw('attribution_type, count(*) as aggregate')
                ->groupBy('attribution_type')
                ->pluck('aggregate', 'attribution_type')
                ->all(),
        ];
        $conversionSourceKeys = (clone $conversionsQuery)
            ->get(['source_type', 'source_id'])
            ->map(fn ($row): string => strtolower(trim((string) $row->source_type)) . '|' . trim((string) $row->source_id))
            ->filter()
            ->unique()
            ->values();
        $rewardLinked = $conversionSourceKeys->isEmpty()
            ? collect()
            : CandleCashRedemption::query()
                ->where('status', 'redeemed')
                ->whereNotNull('external_order_source')
                ->whereNotNull('external_order_id')
                ->when($strict && $tenantId !== null, fn ($query) => $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId)))
                ->get(['id', 'platform', 'external_order_source', 'external_order_id'])
                ->filter(function (CandleCashRedemption $redemption) use ($conversionSourceKeys): bool {
                    $key = strtolower(trim((string) $redemption->external_order_source)) . '|' . trim((string) $redemption->external_order_id);

                    return $conversionSourceKeys->contains($key);
                });
        $rewardConversionSummary = [
            'assisted_count' => (int) $rewardLinked->count(),
            'by_platform' => $rewardLinked
                ->groupBy(fn (CandleCashRedemption $redemption): string => strtolower((string) ($redemption->platform ?: 'unknown')))
                ->map(fn ($group): int => (int) $group->count())
                ->all(),
        ];

        $performanceSummary = $performanceAnalyticsService->campaignSummary($campaign, 120, $tenantId);
        $timingInsight = $timingRecommendationService->bestInsightForCampaign($campaign);

        $templatesQuery = MarketingMessageTemplate::query()
            ->where('is_active', true)
            ->where('channel', $campaign->channel)
            ->orderBy('name');
        if ($strict && $tenantId !== null) {
            $templateIds = $this->ownershipService->tenantTemplateIds($tenantId);
            if ($templateIds->isEmpty()) {
                $templatesQuery->whereRaw('1 = 0');
            } else {
                $templatesQuery->whereIn('id', $templateIds->all());
            }
        }

        return view('marketing/campaigns/show', [
            'section' => MarketingSectionRegistry::section('campaigns'),
            'sections' => $this->navigationItems(),
            'campaign' => $campaign,
            'recipientSummary' => $recipientSummary,
            'approvalQueue' => $approvalQueue,
            'deliveryLog' => $deliveryLog,
            'emailDeliveryLog' => $emailDeliveryLog,
            'diagnostics' => $diagnostics,
            'conversionSummary' => $conversionSummary,
            'rewardConversionSummary' => $rewardConversionSummary,
            'performanceSummary' => $performanceSummary,
            'timingInsight' => $timingInsight,
            'templates' => $templatesQuery->get(['id', 'name']),
            'emailReadiness' => $emailReadiness,
            'rewardIssuanceSummary' => $rewardIssuanceSummary,
        ]);
    }

    public function sendApprovedSms(
        MarketingCampaign $campaign,
        Request $request,
        MarketingSmsExecutionService $executionService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $dryRun = (bool) ($data['dry_run'] ?? false);

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'limit' => (int) ($data['limit'] ?? 500),
            'dry_run' => $dryRun,
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Send run complete. processed=%d sent=%d failed=%d skipped=%d',
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    public function issueSubscriberReward(
        MarketingCampaign $campaign,
        Request $request,
        MarketingCampaignRewardIssuanceService $rewardIssuanceService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1', 'max:100'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $summary = $rewardIssuanceService->issueForCampaign($campaign, [
            'amount' => (int) ($data['amount'] ?? 5),
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        $dryRunLabel = (bool) ($summary['dry_run'] ?? false) ? 'Dry run ' : '';

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => ((int) ($summary['awarded'] ?? 0)) > 0 ? 'success' : 'warning',
                'message' => sprintf(
                    '%sreward grant complete. eligible=%d awarded=%d already_awarded=%d skipped=%d',
                    $dryRunLabel,
                    (int) ($summary['eligible_profiles'] ?? 0),
                    (int) ($summary['awarded'] ?? 0),
                    (int) ($summary['already_awarded'] ?? 0),
                    (int) ($summary['skipped'] ?? 0)
                ),
            ]);
    }

    public function sendApprovedEmail(
        MarketingCampaign $campaign,
        Request $request,
        MarketingEmailExecutionService $executionService,
        MarketingEmailReadiness $readinessService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $readiness = $readinessService->summary($tenantId);
        $effectiveDryRun = (bool) ($data['dry_run'] ?? false) || (bool) ($readiness['dry_run'] ?? false);
        $blocked = $this->blockEmailSend($readiness, $effectiveDryRun, $campaign);
        if ($blocked) {
            return $blocked;
        }

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'limit' => (int) ($data['limit'] ?? 500),
            'dry_run' => $effectiveDryRun,
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        $modeLabel = ($summary['dry_run'] ?? false)
            ? 'Dry run'
            : ((string) ($readiness['status'] ?? 'not_configured') === 'ready' ? 'Live send' : 'Configured send');

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    '%s run complete. processed=%d sent=%d failed=%d skipped=%d',
                    $modeLabel,
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    public function sendSelectedEmail(
        MarketingCampaign $campaign,
        Request $request,
        MarketingEmailExecutionService $executionService,
        MarketingEmailReadiness $readinessService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $data = $request->validate([
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer', 'exists:marketing_campaign_recipients,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $readiness = $readinessService->summary($tenantId);
        $effectiveDryRun = (bool) ($data['dry_run'] ?? false) || (bool) ($readiness['dry_run'] ?? false);
        $blocked = $this->blockEmailSend($readiness, $effectiveDryRun, $campaign);
        if ($blocked) {
            return $blocked;
        }

        $recipientIds = $this->validatedRecipientIdsForCampaign((array) $data['recipient_ids'], $campaign, $tenantId);

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'recipient_ids' => $recipientIds,
            'dry_run' => $effectiveDryRun,
            'limit' => count($recipientIds),
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        $modeLabel = ($summary['dry_run'] ?? false)
            ? 'Dry run'
            : ((string) ($readiness['status'] ?? 'not_configured') === 'ready' ? 'Live send' : 'Configured send');

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    '%s selected email send run complete. processed=%d sent=%d failed=%d skipped=%d',
                    $modeLabel,
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    public function sendSmokeTestEmail(
        MarketingCampaign $campaign,
        Request $request,
        MarketingEmailExecutionService $executionService,
        MarketingEmailReadiness $readinessService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $readiness = $readinessService->summary($tenantId);
        $effectiveDryRun = (bool) ($readiness['dry_run'] ?? false);
        $blocked = $this->blockEmailSend($readiness, $effectiveDryRun, $campaign);
        if ($blocked) {
            return $blocked;
        }

        $testEmail = trim((string) ($readiness['smoke_test_recipient_email'] ?? ''));
        if ($testEmail === '') {
            return redirect()
                ->route('marketing.campaigns.show', $campaign)
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Smoke test recipient not configured (set MARKETING_EMAIL_SMOKE_TEST_RECIPIENT).',
                ]);
        }

        $profileQuery = MarketingProfile::query()
            ->where('normalized_email', Str::lower($testEmail));
        if ($this->strictTenantMode() && $tenantId !== null) {
            $profileQuery->forTenantId($tenantId);
        }
        $profile = $profileQuery->first();
        if (! $profile) {
            $profile = MarketingProfile::query()->create([
                'tenant_id' => $tenantId,
                'email' => $testEmail,
                'normalized_email' => Str::lower($testEmail),
                'accepts_email_marketing' => true,
            ]);
        }

        $recipient = $campaign->recipients()
            ->where('channel', 'email')
            ->where('marketing_profile_id', $profile->id)
            ->first();

        if (! $recipient) {
            $variant = $campaign->variants()
                ->whereIn('status', ['active', 'draft'])
                ->orderByDesc('is_control')
                ->orderByDesc('weight')
                ->orderBy('id')
                ->first();

            $recipient = MarketingCampaignRecipient::query()->create([
                'campaign_id' => $campaign->id,
                'marketing_profile_id' => $profile->id,
                'variant_id' => $variant?->id,
                'channel' => 'email',
                'status' => 'approved',
                'scheduled_for' => now(),
            ]);
        } else {
            $recipient->forceFill([
                'status' => 'approved',
                'send_attempt_count' => 0,
                'reason_codes' => [],
                'failed_at' => null,
                'sent_at' => null,
                'last_status_note' => null,
            ])->save();
        }

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'recipient_ids' => [$recipient->id],
            'dry_run' => $effectiveDryRun,
            'limit' => 1,
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        $delivery = MarketingEmailDelivery::query()
            ->where('marketing_campaign_recipient_id', $recipient->id)
            ->orderByDesc('id')
            ->first();

        $modeLabel = ($summary['dry_run'] ?? false) || $effectiveDryRun
            ? 'Dry run smoke test'
            : 'Live smoke test';

        $message = sprintf(
            '%s completed for %s. processed=%d sent=%d failed=%d skipped=%d',
            $modeLabel,
            $testEmail,
            (int) $summary['processed'],
            (int) $summary['sent'],
            (int) $summary['failed'],
            (int) $summary['skipped']
        );

        if ($delivery) {
            $providerMessageId = trim((string) ($delivery->provider_message_id ?: $delivery->sendgrid_message_id ?: ''));
            if ($providerMessageId !== '') {
                $providerLabel = strtoupper(trim((string) ($delivery->provider ?: 'provider')));
                $message .= sprintf(' %s ID: %s.', $providerLabel, $providerMessageId);
            }
        }

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => isset($summary['failed']) && (int) $summary['failed'] > 0 ? 'warning' : 'success',
                'message' => $message,
            ]);
    }

    public function retryRecipientEmail(
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
        Request $request,
        MarketingEmailExecutionService $executionService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);
        if ($this->strictTenantMode() && $tenantId !== null && ! $this->ownershipService->recipientOwnedByTenant((int) $recipient->id, $tenantId)) {
            abort(404);
        }

        $data = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $result = $executionService->retryRecipient($recipient, [
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => ($result['outcome'] ?? 'skipped') === 'sent' ? 'success' : 'warning',
                'message' => sprintf(
                    'Email retry result: outcome=%s reason=%s',
                    (string) ($result['outcome'] ?? 'unknown'),
                    (string) ($result['reason'] ?? 'n/a')
                ),
            ]);
    }

    protected function blockEmailSend(array $readiness, bool $dryRun, MarketingCampaign $campaign): ?RedirectResponse
    {
        if ((string) ($readiness['status'] ?? 'not_configured') === 'ready' && (bool) ($readiness['can_send'] ?? false)) {
            return null;
        }

        $status = (string) ($readiness['status'] ?? 'not_configured');
        $missing = collect((array) ($readiness['missing_requirements'] ?? $readiness['missing_reasons'] ?? []))
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->values()
            ->all();
        $notes = collect((array) ($readiness['notes'] ?? []))
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->values()
            ->all();

        $reason = match ($status) {
            'unsupported' => $notes[0] ?? 'Selected provider does not support runtime email sends in this architecture.',
            'incomplete' => $missing !== []
                ? 'Email provider setup is incomplete: ' . implode(', ', $missing)
                : 'Email provider setup is incomplete for this tenant.',
            'error' => $missing[0] ?? ($notes[0] ?? 'Email readiness could not be validated.'),
            default => 'Email sending is disabled or not configured for this tenant.',
        };

        if ($dryRun) {
            $reason .= ' This run was requested as dry run, but provider readiness is still required.';
        }

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'warning',
                'message' => $reason,
            ]);
    }

    protected function currentTenantId(Request $request): ?int
    {
        return $this->ownershipService->resolveTenantId($request, false);
    }

    public function sendSelectedSms(
        MarketingCampaign $campaign,
        Request $request,
        MarketingSmsExecutionService $executionService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $data = $request->validate([
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer', 'exists:marketing_campaign_recipients,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $recipientIds = $this->validatedRecipientIdsForCampaign((array) $data['recipient_ids'], $campaign, $tenantId);

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'recipient_ids' => $recipientIds,
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'limit' => count($recipientIds),
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Selected send run complete. processed=%d sent=%d failed=%d skipped=%d',
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    public function retryRecipientSms(
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
        Request $request,
        MarketingSmsExecutionService $executionService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);
        if ($this->strictTenantMode() && $tenantId !== null && ! $this->ownershipService->recipientOwnedByTenant((int) $recipient->id, $tenantId)) {
            abort(404);
        }

        $data = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $result = $executionService->retryRecipient($recipient, [
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
            'tenant_id' => $tenantId,
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => ($result['outcome'] ?? 'skipped') === 'sent' ? 'success' : 'warning',
                'message' => sprintf(
                    'Retry result: outcome=%s reason=%s',
                    (string) ($result['outcome'] ?? 'unknown'),
                    (string) ($result['reason'] ?? 'n/a')
                ),
            ]);
    }

    public function prepareRecipients(
        MarketingCampaign $campaign,
        Request $request,
        MarketingCampaignAudienceBuilder $audienceBuilder
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

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
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

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

        if ($this->strictTenantMode() && $tenantId !== null && ! empty($data['template_id'])) {
            $templateId = (int) $data['template_id'];
            if (! $this->ownershipService->templateOwnedByTenant($templateId, $tenantId)) {
                throw ValidationException::withMessages([
                    'template_id' => 'Selected template is outside tenant ownership scope.',
                ]);
            }
        }

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
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);
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

        if ($this->strictTenantMode() && $tenantId !== null && ! empty($data['template_id'])) {
            $templateId = (int) $data['template_id'];
            if (! $this->ownershipService->templateOwnedByTenant($templateId, $tenantId)) {
                throw ValidationException::withMessages([
                    'template_id' => 'Selected template is outside tenant ownership scope.',
                ]);
            }
        }

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
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);
        if ($this->strictTenantMode() && $tenantId !== null && ! $this->ownershipService->recipientOwnedByTenant((int) $recipient->id, $tenantId)) {
            abort(404);
        }
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
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);
        if ($this->strictTenantMode() && $tenantId !== null && ! $this->ownershipService->recipientOwnedByTenant((int) $recipient->id, $tenantId)) {
            abort(404);
        }
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
        Request $request,
        MarketingRecommendationEngine $engine
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $result = $engine->generateForCampaign($campaign, [
            'tenant_id' => $tenantId,
        ]);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'success',
                'message' => "Generated {$result['created']} recommendations (potential {$result['potential']}).",
            ]);
    }

    public function addProfileRecipient(
        MarketingCampaign $campaign,
        Request $request,
        MarketingRecommendationEngine $recommendationEngine,
        MarketingSmsEligibilityService $smsEligibilityService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertCampaignAccess($campaign, $tenantId);

        $data = $request->validate([
            'marketing_profile_id' => ['required', 'integer', 'exists:marketing_profiles,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'variant_id' => ['nullable', 'integer', 'exists:marketing_campaign_variants,id'],
        ]);

        /** @var MarketingProfile $profile */
        $profile = MarketingProfile::query()->findOrFail((int) $data['marketing_profile_id']);
        if ($this->strictTenantMode() && $tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
            throw ValidationException::withMessages([
                'marketing_profile_id' => 'Selected profile is outside tenant ownership scope.',
            ]);
        }

        $existing = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('marketing_profile_id', $profile->id)
            ->first();

        $smsEvaluation = strtolower(trim((string) $campaign->channel)) === 'sms'
            ? $smsEligibilityService->evaluateProfile($profile, $tenantId)
            : null;
        [$status, $reasons] = $this->eligibilityForChannel($profile, (string) $campaign->channel, $smsEvaluation);
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
            $recommendationEngine->generateSendSuggestionForProfile($profile, $campaign, [
                'tenant_id' => $tenantId,
            ]);
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
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:marketing_groups,id'],
            'objective' => ['nullable', 'in:winback,repeat_purchase,event_followup,consent_capture,review_request,retention,reward_issuance'],
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
            'group_ids' => array_map('intval', (array) ($data['group_ids'] ?? [])),
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
    protected function eligibilityForChannel(MarketingProfile $profile, string $channel, ?array $smsEvaluation = null): array
    {
        $channel = strtolower(trim($channel));
        $reasons = [];
        $eligible = true;

        if ($channel === 'sms') {
            if ($smsEvaluation !== null && $smsEvaluation !== []) {
                $eligible = (bool) ($smsEvaluation['eligible'] ?? false);
                $reasons = collect((array) ($smsEvaluation['reason_codes'] ?? []))
                    ->map(fn ($value): string => strtolower(trim((string) $value)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [$eligible ? 'queued_for_approval' : 'skipped', $reasons];
            }

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
     * @param array<int,mixed> $recipientIds
     * @return array<int,int>
     */
    protected function validatedRecipientIdsForCampaign(array $recipientIds, MarketingCampaign $campaign, ?int $tenantId): array
    {
        $requestedIds = collect($recipientIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            return [];
        }

        $authorizedQuery = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('id', $requestedIds->all());

        if ($this->strictTenantMode() && $tenantId !== null) {
            $authorizedQuery->whereHas('profile', fn ($query) => $query->forTenantId($tenantId));
        }

        $authorizedIds = $authorizedQuery
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        if ($authorizedIds->count() !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'Selected recipients are outside campaign or tenant ownership scope.',
            ]);
        }

        return $authorizedIds->all();
    }

    protected function strictTenantMode(): bool
    {
        return $this->ownershipService->strictModeEnabled();
    }

    protected function resolveTenantId(Request $request): ?int
    {
        return $this->ownershipService->resolveTenantId($request, $this->strictTenantMode());
    }

    protected function assertCampaignAccess(MarketingCampaign $campaign, ?int $tenantId): void
    {
        if (! $this->strictTenantMode() || $tenantId === null) {
            return;
        }

        if (! $this->ownershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId)) {
            abort(404);
        }
    }

    /**
     * @param Collection<int,int> $segmentIds
     */
    protected function segmentsQueryForTenant(bool $strict, Collection $segmentIds): \Illuminate\Database\Eloquent\Builder
    {
        $query = MarketingSegment::query()
            ->whereIn('status', ['active', 'draft'])
            ->orderBy('name');

        if ($strict) {
            if ($segmentIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $segmentIds->all());
            }
        }

        return $query;
    }

    /**
     * @param Collection<int,int> $groupIds
     */
    protected function groupsQueryForTenant(bool $strict, Collection $groupIds): \Illuminate\Database\Eloquent\Builder
    {
        $query = MarketingGroup::query()
            ->orderBy('name');

        if ($strict) {
            if ($groupIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $groupIds->all());
            }
        }

        return $query;
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
