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
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\MarketingPerformanceAnalyticsService;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MarketingTimingRecommendationService;
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
            'groups' => MarketingGroup::query()->orderBy('name')->get(['id', 'name', 'is_internal']),
            'selectedGroupIds' => [],
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedCampaign($request);
        $groupIds = collect((array) ($data['group_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        unset($data['group_ids']);

        $campaign = MarketingCampaign::query()->create([
            ...$data,
            'slug' => Str::slug($data['name']),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
        $campaign->groups()->sync($groupIds);

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
            'groups' => MarketingGroup::query()->orderBy('name')->get(['id', 'name', 'is_internal']),
            'selectedGroupIds' => $campaign->groups()->pluck('marketing_groups.id')->map(fn ($id) => (int) $id)->all(),
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, MarketingCampaign $campaign): RedirectResponse
    {
        $data = $this->validatedCampaign($request);
        $groupIds = collect((array) ($data['group_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        unset($data['group_ids']);

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
        MarketingCampaign $campaign,
        MarketingPerformanceAnalyticsService $performanceAnalyticsService,
        MarketingTimingRecommendationService $timingRecommendationService,
        MarketingEmailReadiness $readinessService,
        MarketingCampaignDeliveryDiagnostics $diagnosticsService
    ): View
    {
        $campaign->load([
            'segment:id,name',
            'groups:id,name,is_internal',
            'variants.template:id,name',
            'recommendations' => fn ($query) => $query->orderByDesc('id')->limit(10),
        ]);

        $recipientSummary = $campaign->recipients()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $approvalQueue = $campaign->recipients()
            ->with(['profile:id,first_name,last_name,email,phone', 'variant:id,name', 'latestDelivery', 'latestEmailDelivery'])
            ->whereIn('status', ['queued_for_approval', 'approved', 'rejected', 'sending', 'sent', 'delivered', 'failed', 'undelivered', 'converted'])
            ->orderByRaw("case when status = 'queued_for_approval' then 0 when status='approved' then 1 when status='sending' then 2 when status='failed' then 3 else 4 end")
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        $deliveryLog = $campaign->deliveries()
            ->with(['profile:id,first_name,last_name,email,phone', 'variant:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        $emailDeliveryLog = MarketingEmailDelivery::query()
            ->whereIn('marketing_campaign_recipient_id', function ($query) use ($campaign): void {
                $query->select('id')
                    ->from('marketing_campaign_recipients')
                    ->where('campaign_id', $campaign->id);
            })
            ->with(['profile:id,first_name,last_name,email,phone', 'recipient:id,status'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $emailReadiness = $readinessService->summary();
        $diagnostics = $diagnosticsService->summarize($campaign, $emailReadiness, $emailDeliveryLog);

        $conversionSummary = [
            'count' => (int) $campaign->conversions()->count(),
            'revenue' => (float) $campaign->conversions()->sum('order_total'),
            'types' => $campaign->conversions()
                ->selectRaw('attribution_type, count(*) as aggregate')
                ->groupBy('attribution_type')
                ->pluck('aggregate', 'attribution_type')
                ->all(),
        ];
        $conversionSourceKeys = $campaign->conversions()
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

        $performanceSummary = $performanceAnalyticsService->campaignSummary($campaign, 120);
        $timingInsight = $timingRecommendationService->bestInsightForCampaign($campaign);

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
            'templates' => MarketingMessageTemplate::query()
                ->where('is_active', true)
                ->where('channel', $campaign->channel)
                ->orderBy('name')
                ->get(['id', 'name']),
            'emailReadiness' => $emailReadiness,
        ]);
    }

    public function sendApprovedSms(
        MarketingCampaign $campaign,
        Request $request,
        MarketingSmsExecutionService $executionService
    ): RedirectResponse {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $dryRun = (bool) ($data['dry_run'] ?? false);

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'limit' => (int) ($data['limit'] ?? 500),
            'dry_run' => $dryRun,
            'actor_id' => (int) auth()->id(),
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

    public function sendApprovedEmail(
        MarketingCampaign $campaign,
        Request $request,
        MarketingEmailExecutionService $executionService,
        MarketingEmailReadiness $readinessService
    ): RedirectResponse {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'limit' => (int) ($data['limit'] ?? 500),
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
        ]);

        $status = $readinessService->summary();
        $modeLabel = ($summary['dry_run'] ?? false)
            ? 'Dry run'
            : ($status['status'] === 'ready_for_live_send' ? 'Live send' : 'Configured send');

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
        $data = $request->validate([
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer', 'exists:marketing_campaign_recipients,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $dryRun = (bool) ($data['dry_run'] ?? false);
        $readiness = $readinessService->summary();
        $blocked = $this->blockEmailSend($readiness, $dryRun, $campaign);
        if ($blocked) {
            return $blocked;
        }

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'recipient_ids' => array_map('intval', (array) $data['recipient_ids']),
            'dry_run' => $dryRun,
            'limit' => count((array) $data['recipient_ids']),
            'actor_id' => (int) auth()->id(),
        ]);

        $modeLabel = ($summary['dry_run'] ?? false)
            ? 'Dry run'
            : ($readiness['status'] === 'ready_for_live_send' ? 'Live send' : 'Configured send');

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
        $readiness = $readinessService->summary();
        $blocked = $this->blockEmailSend($readiness, false, $campaign);
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

        $profile = MarketingProfile::query()->firstOrCreate([
            'normalized_email' => Str::lower($testEmail),
        ], [
            'email' => $testEmail,
            'accepts_email_marketing' => true,
        ]);

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
            'dry_run' => (bool) ($readiness['dry_run'] ?? false),
            'limit' => 1,
            'actor_id' => (int) auth()->id(),
        ]);

        $delivery = MarketingEmailDelivery::query()
            ->where('marketing_campaign_recipient_id', $recipient->id)
            ->orderByDesc('id')
            ->first();

        $modeLabel = ($summary['dry_run'] ?? false) || $readiness['dry_run']
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

        if ($delivery && $delivery->sendgrid_message_id) {
            $message .= sprintf(' SendGrid ID: %s.', $delivery->sendgrid_message_id);
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
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);

        $data = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $result = $executionService->retryRecipient($recipient, [
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
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
        if ($dryRun || $readiness['status'] === 'ready_for_live_send' || $readiness['status'] === 'dry_run_only') {
            return null;
        }

        $reason = $readiness['status'] === 'disabled'
            ? 'Email sending is currently disabled (MARKETING_EMAIL_ENABLED=false).'
            : 'Email misconfigured: ' . implode(', ', $readiness['missing_reasons']);

        return redirect()
            ->route('marketing.campaigns.show', $campaign)
            ->with('toast', [
                'style' => 'warning',
                'message' => $reason,
            ]);
    }

    public function sendSelectedSms(
        MarketingCampaign $campaign,
        Request $request,
        MarketingSmsExecutionService $executionService
    ): RedirectResponse {
        $data = $request->validate([
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer', 'exists:marketing_campaign_recipients,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $summary = $executionService->sendApprovedForCampaign($campaign, [
            'recipient_ids' => array_map('intval', (array) $data['recipient_ids']),
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'limit' => count((array) $data['recipient_ids']),
            'actor_id' => (int) auth()->id(),
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
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);

        $data = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $result = $executionService->retryRecipient($recipient, [
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
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
            ->with('toast', [
                'style' => 'success',
                'message' => "Generated {$result['created']} recommendations (potential {$result['potential']}).",
            ]);
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
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:marketing_groups,id'],
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
