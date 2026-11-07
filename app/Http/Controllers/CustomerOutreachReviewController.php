<?php

namespace App\Http\Controllers;

use App\Models\LandlordOperatorAction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerOutreachExplanation;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A tenant-owned review command surface over existing campaigns, approvals and senders. */
class CustomerOutreachReviewController extends Controller
{
    private const REQUEST = 'customer_outreach_review_request';

    private const RESULT = 'customer_outreach_review_result';

    public function __invoke(Request $request)
    {
        [$tenant, $actor, $profile, $campaign, $recipient] = $this->scope($request);
        $actionResult = null;
        try {
            if ($request->isMethod('post')) {
                $command = $request->validate([
                    'action' => 'required|in:approve,send', 'request_id' => 'required|uuid',
                    'reviewed_content_hash' => 'required|string|size:64',
                    'confirm_approval' => 'exclude_unless:action,approve|required|accepted',
                    'confirm_send' => 'exclude_unless:action,send|required|accepted',
                ]);
                $hash = hash('sha256', json_encode([$campaign->id, $profile->id, $command['action'], $command['reviewed_content_hash']], JSON_THROW_ON_ERROR));
                $actionResult = app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($request, $command, $hash, $tenant) {
                    Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                    [$tenant, $actor, $profile, $campaign, $recipient] = $this->scope($request, true);
                    $prior = $this->requestAudit($tenant->id, $command['request_id']);
                    if ($prior) {
                        abort_unless(data_get($prior->context, 'command_hash') === $hash && (int) $prior->actor_user_id === (int) $actor->id, 409, 'This review request ID belongs to another action.');

                        $completed = LandlordOperatorAction::forTenantId($tenant->id)->where('action_type', self::RESULT)->where('target_id', $command['request_id'])->first();

                        return [...((array) $prior->result), ...((array) $completed?->result), 'handoff' => false, 'replayed' => true];
                    }
                    $review = $this->review($tenant, $profile, $campaign, $recipient);
                    abort_unless(hash_equals($review['content_hash'], $command['reviewed_content_hash']) && $review['content_valid'], 409, 'The prepared content changed. Prepare a new draft before approval or sending.');
                    abort_if($review['integrity_blockers'] !== [], 409, 'The original customer or preparation evidence needs a new reviewed draft.');
                    $result = ['action' => $command['action'], 'handoff' => false, 'replayed' => false];
                    if ($command['action'] === 'approve') {
                        if ($recipient->status === 'approved' && $recipient->approved_at && $recipient->approved_by) {
                            $result['outcome'] = 'already_approved';
                        } else {
                            abort_unless($review['can_approve'], 409, 'This recipient state cannot be reset by approval. Preserve its original decision or delivery.');
                            app(MarketingApprovalService::class)->approveRecipient($recipient, (int) $actor->id, 'Frozen customer-profile content explicitly reviewed. Approval does not send or grant consent.');
                            $result['outcome'] = 'approved';
                        }
                    } elseif (! $review['can_send']) {
                        $result = [...$result, 'outcome' => 'blocked', 'reason_codes' => $review['send_blockers']];
                    } else {
                        // Durable review reservation precedes the existing sender. A crash cannot authorize a replacement send.
                        $result = [...$result, 'outcome' => 'handoff_reserved', 'handoff' => true];
                    }
                    app(LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, self::REQUEST,
                        $result['outcome'], 'customer_outreach_review_request', $command['request_id'],
                        ['command_hash' => $hash, 'campaign_id' => (int) $campaign->id, 'profile_id' => (int) $profile->id,
                            'recipient_id' => (int) $recipient->id, 'action' => $command['action'], 'content_hash' => $review['content_hash']],
                        ['explicitly_confirmed' => true], null, null, $result);

                    return $result;
                });
                if (($actionResult['handoff'] ?? false) && ! ($actionResult['replayed'] ?? false)) {
                    // Never hold journal/identity locks across provider I/O; the existing sender rechecks its own admission.
                    $recipient = MarketingCampaignRecipient::where('campaign_id', $campaign->id)->whereKey($recipient->id)->firstOrFail();
                    try {
                        $sender = $campaign->channel === 'email' ? app(MarketingEmailExecutionService::class) : app(MarketingSmsExecutionService::class);
                        $sent = $sender->sendRecipient($recipient, ['tenant_id' => (int) $tenant->id, 'actor_id' => (int) $actor->id,
                            'source_label' => 'customer_profile', 'batch_id' => 'customer-review-'.$command['request_id']]);
                        $actionResult = ['action' => 'send', 'handoff' => false, 'outcome' => (string) ($sent['outcome'] ?? 'unknown'),
                            'reason' => $sent['reason'] ?? null, 'reason_codes' => array_values((array) ($sent['reason_codes'] ?? [])),
                            'delivery_id' => isset($sent['delivery_id']) ? (int) $sent['delivery_id'] : null,
                            'dry_run' => (bool) ($sent['dry_run'] ?? false)];
                    } catch (\Throwable) {
                        $actionResult = ['action' => 'send', 'handoff' => false, 'outcome' => 'unknown', 'reason' => 'existing_sender_handoff_unconfirmed'];
                    }
                    app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $actor, $profile, $campaign, $recipient, $command, $actionResult) {
                        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                        app(LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, self::RESULT,
                            $actionResult['outcome'], 'customer_outreach_review_request', $command['request_id'],
                            ['campaign_id' => (int) $campaign->id, 'profile_id' => (int) $profile->id, 'recipient_id' => (int) $recipient->id], [], null, null, $actionResult);
                    });
                }
                if (! $request->expectsJson()) {
                    return $this->redirect($tenant, $profile, $campaign)->with('status', $command['action'] === 'approve'
                        ? 'Content approval recorded. No message sent and no consent changed.'
                        : 'Original send review outcome: '.($actionResult['outcome'] ?? 'unknown').'. Check its delivery state before any further action.');
                }
                [$tenant, $actor, $profile, $campaign, $recipient] = $this->scope($request);
            }
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422)->header('Cache-Control', 'private, no-store');
            }

            return $this->redirect($tenant, $profile, $campaign)->withInput($request->except('_token'))->withErrors($exception->errors(), 'outreachReview');
        } catch (HttpException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode())->header('Cache-Control', 'private, no-store');
            }
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return $this->redirect($tenant, $profile, $campaign)->withInput($request->except('_token'))->withErrors(['review' => $exception->getMessage()], 'outreachReview');
        }
        $review = $this->review($tenant, $profile, $campaign, $recipient);
        if ($request->expectsJson()) {
            return response()->json(['review' => $review, 'action_result' => $actionResult])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('customers.outreach-review', compact('tenant', 'profile', 'campaign', 'review'))->header('Cache-Control', 'private, no-store');
    }

    private function scope(Request $request, bool $lock = false): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $request->user()?->fresh();
        abort_unless($tenant instanceof Tenant && $actor instanceof User && $actor->is_active && $actor->email_verified_at
            && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'customers'), 403);
        $membership = $actor->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->first();
        abort_unless($membership && in_array(strtolower((string) $membership->pivot->role), ['owner', 'tenant_owner', 'admin'], true), 403);
        $profileId = $request->route('profile');
        $campaignId = $request->route('campaign');
        $profileId = $profileId instanceof MarketingProfile ? $profileId->id : $profileId;
        $campaignId = $campaignId instanceof MarketingCampaign ? $campaignId->id : $campaignId;
        abort_unless(is_numeric($profileId) && is_numeric($campaignId), 404);
        $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $profileId);
        abort_unless($profile, 404);
        if ($lock) {
            $profile = MarketingProfile::forTenantId($tenant->id)->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        }
        $profile = app(CustomerOutreachDraft::class)->authorize($tenant, $actor, $profile);
        $campaign = MarketingCampaign::forTenantId($tenant->id)->whereKey((int) $campaignId)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($campaign->source_label === 'customer_profile' && in_array($campaign->channel, ['email', 'sms'], true)
            && (int) data_get($campaign->target_snapshot, 'profile_id') === (int) $profile->id, 404);
        $recipients = $campaign->recipients()->when($lock, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        $variants = $campaign->variants()->when($lock, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        abort_unless($recipients->count() === 1 && $variants->count() === 1, 409, 'This review requires exactly one original recipient and variant.');
        $recipient = $recipients->first();
        abort_unless((int) $recipient->marketing_profile_id === (int) $profile->id && (int) $recipient->variant_id === (int) $variants->first()->id
            && $recipient->channel === $campaign->channel, 404);
        $recipient->setRelation('campaign', $campaign)->setRelation('profile', $profile)->setRelation('variant', $variants->first());

        return [$tenant, $actor, $profile, $campaign, $recipient];
    }

    private function review(Tenant $tenant, MarketingProfile $profile, MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): array
    {
        $body = (string) $recipient->variant->message_text;
        $valid = $body === (string) $campaign->message_body && ($campaign->channel === 'email'
            ? app(CustomerOutreachDraft::class)->emailContent($campaign, $profile, $body) !== null
            : app(CustomerOutreachDraft::class)->smsBodyMatches($campaign, $profile, $body));
        $contentHash = hash('sha256', json_encode([$campaign->id, $profile->id, $campaign->channel, $campaign->message_subject, $campaign->message_body,
            $campaign->message_html, $recipient->variant_id, $body, $campaign->target_snapshot], JSON_THROW_ON_ERROR));
        try {
            $decision = app(CustomerOutreachExplanation::class)->dispatchDecision($campaign, $profile);
        } catch (\Throwable) {
            $decision = ['allowed' => false, 'reason_codes' => ['outreach_preparation_evidence_unavailable']];
        }
        $integrity = array_values(array_filter($decision['reason_codes'], fn ($code) => str_starts_with($code, 'outreach_preparation_')
            || str_starts_with($code, 'outreach_prepared_') || $code === 'outreach_personalization_evidence_missing'));
        if (! $valid) {
            $integrity[] = 'outreach_prepared_content_changed';
        }
        $deliveryExists = $recipient->emailDeliveries()->exists() || $recipient->deliveries()->exists();
        $approvalRows = $recipient->approvals()->latest('id')->limit(20)->get();
        $rejected = $recipient->rejected_at !== null || $recipient->rejected_by !== null || $recipient->approvals()->where('status', 'rejected')->exists();
        $latestApproval = $approvalRows->first();
        $approvalRecorded = $latestApproval?->status === 'approved' && $latestApproval?->approval_type === 'send'
            && (int) $latestApproval?->approver_id === (int) $recipient->approved_by;
        $campaignSendable = in_array($campaign->status, ['draft', 'queued', 'approved', 'scheduled'], true);
        $deliveries = $recipient->emailDeliveries()->forTenantId($tenant->id)->where('marketing_profile_id', $profile->id)->latest('id')->limit(20)->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'channel' => 'email', 'status' => $row->status, 'created_at' => $row->created_at?->toIso8601String(), 'sent_at' => $row->sent_at?->toIso8601String(), 'delivered_at' => $row->delivered_at?->toIso8601String()])
            ->concat($recipient->deliveries()->forTenantId($tenant->id)->where('marketing_profile_id', $profile->id)->latest('id')->limit(20)->get()
                ->map(fn ($row) => ['id' => (int) $row->id, 'channel' => 'sms', 'status' => $row->send_status, 'created_at' => $row->created_at?->toIso8601String(), 'sent_at' => $row->sent_at?->toIso8601String(), 'delivered_at' => $row->delivered_at?->toIso8601String()]))->values()->all();
        $requests = LandlordOperatorAction::forTenantId($tenant->id)->where('action_type', self::REQUEST)->where('context->campaign_id', (int) $campaign->id)
            ->where('context->recipient_id', (int) $recipient->id)->where('context->action', 'send')->get();
        $completed = LandlordOperatorAction::forTenantId($tenant->id)->where('action_type', self::RESULT)->whereIn('target_id', $requests->pluck('target_id'))->get()->keyBy('target_id');
        $unresolved = $requests->contains(fn ($entry) => data_get($entry->result, 'outcome') === 'handoff_reserved'
            && (! $completed->has($entry->target_id) || data_get($completed->get($entry->target_id)?->result, 'outcome') === 'unknown'));
        $knownAccepted = collect($deliveries)->contains(fn ($row) => in_array($row['status'], ['sent', 'delivered', 'opened', 'clicked'], true));
        if ($knownAccepted) {
            $unresolved = false;
        }
        $sendBlockers = array_values(array_unique([...$decision['reason_codes'], ...$integrity]));
        if (! in_array($recipient->status, ['approved', 'scheduled'], true) || ! $recipient->approved_at || ! $recipient->approved_by || ! $approvalRecorded) {
            $sendBlockers[] = 'content_approval_required';
        }
        if ($rejected) {
            $sendBlockers[] = 'original_approval_rejected';
        }
        if (! $campaignSendable) {
            $sendBlockers[] = 'campaign_not_sendable';
        }
        if ($recipient->scheduled_for?->isFuture()) {
            $sendBlockers[] = 'recipient_scheduled_for_later';
        }
        if ($deliveryExists || $recipient->send_attempt_count > 0) {
            $sendBlockers[] = 'original_delivery_already_exists';
        }
        if ($unresolved) {
            $sendBlockers[] = 'original_send_handoff_unconfirmed';
        }
        $approvals = $approvalRows->map(fn ($row) => ['id' => $row->id, 'status' => $row->status,
            'approved_at' => $row->approved_at?->toIso8601String(), 'rejected_at' => $row->rejected_at?->toIso8601String()])->all();

        return ['campaign_id' => (int) $campaign->id, 'profile_id' => (int) $profile->id, 'channel' => $campaign->channel,
            'campaign_status' => $campaign->status, 'recipient_status' => $recipient->status, 'subject' => $campaign->message_subject,
            'body' => $campaign->message_body, 'html' => $campaign->channel === 'email' ? $campaign->message_html : null,
            'content_hash' => $contentHash, 'content_valid' => $valid, 'integrity_blockers' => array_values(array_unique($integrity)),
            'decision' => $decision, 'prepared_evidence' => $campaign->target_snapshot['explanation'] ?? null,
            'approvals' => $approvals, 'deliveries' => $deliveries, 'send_handoff_unconfirmed' => $unresolved,
            'can_approve' => $valid && $integrity === [] && ! $deliveryExists && ! $unresolved && ! $rejected && $recipient->send_attempt_count === 0
                && in_array($recipient->status, ['draft', 'queued_for_approval', 'skipped'], true)
                && $campaignSendable,
            'can_send' => $sendBlockers === [], 'send_blockers' => array_values(array_unique($sendBlockers)),
            'approval_request_id' => (string) Str::uuid(), 'send_request_id' => (string) Str::uuid()];
    }

    private function requestAudit(int $tenantId, string $requestId): ?LandlordOperatorAction
    {
        return LandlordOperatorAction::forTenantId($tenantId)->where('action_type', self::REQUEST)
            ->where('target_type', 'customer_outreach_review_request')->where('target_id', $requestId)->first();
    }

    private function redirect(Tenant $tenant, MarketingProfile $profile, MarketingCampaign $campaign)
    {
        return redirect()->route('customers.outreach.review', ['tenant' => $tenant->slug, 'profile' => $profile->id, 'campaign' => $campaign->id])->header('Cache-Control', 'private, no-store');
    }
}
