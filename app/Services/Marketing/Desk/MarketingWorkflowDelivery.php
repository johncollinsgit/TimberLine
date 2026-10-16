<?php

namespace App\Services\Marketing\Desk;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\MarketingAudiencePermission;
use App\Models\MarketingAutomationEnrollment;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingDeliveryAttempt;
use App\Models\MarketingDeliveryAuthorization;
use App\Models\MarketingProfile;
use App\Models\User;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingSmsExecutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingWorkflowDelivery
{
    public function send(ActionOperationContext $context, bool $dryRun): array
    {
        $tenantId = $context->execution->tenantId;
        abort_unless(app(MarketingDeskAccess::class)->available($tenantId), 403);
        $payload = array_replace_recursive($context->config, $context->input);
        $profile = MarketingProfile::forTenantId($tenantId)->findOrFail((int) ($payload['marketing_profile_id'] ?? 0));
        $channel = str_contains($context->componentKey, '.sms.') ? 'sms' : 'email';
        $audience = (string) ($payload['audience'] ?? '');
        $storeKey = (string) ($context->execution->triggerOutput['store_key'] ?? '');
        $workflow = AutomationWorkflow::forTenantId($tenantId)->findOrFail($context->execution->workflowId);
        $version = AutomationWorkflowVersion::forTenantId($tenantId)->where('automation_workflow_id', $workflow->id)->find($context->execution->workflowVersionId);
        abort_unless(app(MarketingChannelReadiness::class)->ready($tenantId, $channel), 422, 'Set up a verified '.$channel.' sender before testing or running marketing delivery.');
        if ($dryRun) {
            abort_unless(in_array($audience, ['retail', 'wholesale', 'services'], true) && filled($payload['body'] ?? '') && filled($payload['subject'] ?? ''), 422);
            abort_if(str_contains((string) $payload['body'], 'Write and approve your brand-specific'), 422, 'Replace the recipe guidance with your approved message before testing.');

            return ['status' => 'validated', 'dry_run' => true, 'profile_id' => $profile->id, 'channel' => $channel];
        }
        $approval = $version ? MarketingDeliveryAuthorization::forTenantId($tenantId)->where('workflow_id', $workflow->id)->where('definition_hash', $version->definition_hash)->where('active', true)->first() : null;
        abort_unless($approval && $approval->audience === $audience && (string) $approval->store_key === $storeKey && $workflow->status === 'active', 403, 'Approve this exact workflow and audience before delivery.');
        app(MarketingDeskAccess::class)->ensureDelivery($tenantId, User::find($approval->approved_by));
        $key = hash('sha256', $context->idempotencyKey);
        $attempt = DB::transaction(function () use ($tenantId, $profile, $workflow, $context, $audience, $storeKey, $channel, $key) {
            // Profile lock serializes channel frequency checks and enrollment cancellation.
            $profile = MarketingProfile::forTenantId($tenantId)->lockForUpdate()->findOrFail($profile->id);
            $existing = MarketingDeliveryAttempt::forTenantId($tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $enrollment = MarketingAutomationEnrollment::firstOrCreate([
                'workflow_id' => $workflow->id, 'marketing_profile_id' => $profile->id, 'event_key' => (string) ($context->execution->triggerOutput['marketing_event_key'] ?? hash('sha256', (string) $context->execution->runItemId)),
            ], ['tenant_id' => $tenantId, 'workflow_version_id' => $context->execution->workflowVersionId, 'audience' => $audience, 'store_key' => $storeKey, 'started_at' => now()]);
            $hasReply = \App\Models\MessagingConversation::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('last_inbound_at', '>=', $enrollment->started_at)->exists();
            $hasPurchase = \App\Models\CustomerExternalProfile::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('last_order_at', '>=', $enrollment->started_at)->exists();
            $permission = MarketingAudiencePermission::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('audience', $audience)->where('store_key', $storeKey)->where('channel', $channel)->whereNull('revoked_at')->exists();
            $timezone = app(MarketingWorkspaceClock::class)->timezone($tenantId);
            $hour = now($timezone)->hour;
            $since = $channel === 'email' ? now()->subDays(7) : now()->subDays(30);
            $recent = MarketingDeliveryAttempt::forTenantId($tenantId)->where('marketing_profile_id', $profile->id)->where('channel', $channel)->whereIn('status', ['sending', 'sent', 'unknown'])->where('created_at', '>=', $since)->count();
            $reason = match (true) {
                $hasReply => 'customer_replied',
                $hasPurchase => 'customer_purchased',
                $enrollment->status !== 'active' => $enrollment->stop_reason ?: 'enrollment_stopped',
                $profile->archived_at !== null || $profile->merged_into_profile_id !== null => 'inactive_profile',
                ! $permission => 'audience_consent_missing',
                ! $profile->getAttribute('accepts_'.$channel.'_marketing') || $profile->getAttribute($channel.'_opted_out_at') !== null => 'channel_consent_missing',
                $hour < 9 || $hour >= 18 => 'outside_send_window',
                $recent >= 2 => 'frequency_limit',
                default => null,
            };

            return MarketingDeliveryAttempt::create(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id, 'workflow_id' => $workflow->id, 'idempotency_key' => $key, 'channel' => $channel, 'status' => $reason ? 'suppressed' : 'sending', 'reason' => $reason]);
        }, 3);
        if (! $attempt->wasRecentlyCreated && in_array($attempt->status, ['sending', 'unknown'], true)) {
            throw new \App\Services\Automation\V2\WorkflowActionOutcomeUncertain('Review the original delivery outcome before any retry.');
        }
        if (! $attempt->wasRecentlyCreated || $attempt->status !== 'sending') {
            return ['status' => $attempt->status, 'reason' => $attempt->reason];
        }
        try {
            $campaign = MarketingCampaign::create(['tenant_id' => $tenantId, 'store_key' => $storeKey ?: null, 'name' => $workflow->name, 'slug' => 'automation-'.Str::lower((string) Str::ulid()), 'channel' => $channel, 'status' => 'approved', 'source_label' => 'marketing_studio', 'message_subject' => $payload['subject'], 'message_body' => $payload['body'], 'created_by' => $approval->approved_by]);
            $recipient = MarketingCampaignRecipient::create(['campaign_id' => $campaign->id, 'marketing_profile_id' => $profile->id, 'channel' => $channel, 'status' => 'approved', 'approved_by' => $approval->approved_by, 'approved_at' => now(), 'recommendation_snapshot' => ['suggested_message' => $payload['body']], 'scheduled_for' => now()]);
            $attempt->update(['campaign_id' => $campaign->id, 'recipient_id' => $recipient->id]);
            $service = $channel === 'email' ? app(MarketingEmailExecutionService::class) : app(MarketingSmsExecutionService::class);
            $result = $service->sendRecipient($recipient, ['tenant_id' => $tenantId, 'actor_id' => $approval->approved_by, 'batch_id' => $key, 'source_label' => 'marketing_studio']);
            if (($result['outcome'] ?? '') === 'failed') {
                throw new \App\Services\Automation\V2\WorkflowActionOutcomeUncertain('Provider delivery could not be confirmed.');
            }
            $status = ($result['outcome'] ?? '') === 'sent' && ! ($result['dry_run'] ?? false) ? 'sent' : 'suppressed';
            $attempt->update(['status' => $status, 'reason' => $result['reason'] ?? null, 'sent_at' => $status === 'sent' ? now() : null]);

            return ['status' => $status, 'reason' => $attempt->reason];
        } catch (\Throwable $exception) {
            $attempt->update(['status' => 'unknown', 'reason' => 'provider_outcome_unverified']);
            throw new \App\Services\Automation\V2\WorkflowActionOutcomeUncertain('Review the original delivery before retrying.', previous: $exception);
        }
    }

    public function stop(int $tenantId, int $profileId, string $reason): void
    {
        DB::transaction(function () use ($tenantId, $profileId, $reason): void {
            MarketingProfile::forTenantId($tenantId)->lockForUpdate()->findOrFail($profileId);
            MarketingAutomationEnrollment::forTenantId($tenantId)->where('marketing_profile_id', $profileId)->where('status', 'active')->update(['status' => 'stopped', 'stop_reason' => $reason]);
        }, 3);
    }
}
