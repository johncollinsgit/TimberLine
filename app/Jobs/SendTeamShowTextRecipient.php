<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\TeamOperationRecord;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\TeamShowTextCampaigns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class SendTeamShowTextRecipient implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public int $campaignId, public int $recipientId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('show-text:'.$this->recipientId))->dontRelease()->expireAfter(120)];
    }

    public function handle(MarketingSmsExecutionService $sms): void
    {
        $campaign = MarketingCampaign::forTenantId($this->tenantId)->where('source_label', 'team_show_text')->find($this->campaignId);
        $recipient = $campaign?->recipients()->whereHas('profile', fn ($q) => $q->forTenantId($this->tenantId))->find($this->recipientId);
        if (! $recipient || $recipient->status !== 'approved' || $recipient->send_attempt_count) {
            return;
        }
        if (! TeamShowTextCampaigns::enabledFor($this->tenantId) || ! TeamOperationRecord::query()
            ->where('tenant_id', $this->tenantId)->where('area_id', data_get($campaign->target_snapshot, 'team_show_text.area_id'))
            ->where('kind', 'event')->whereKey(data_get($campaign->target_snapshot, 'team_show_text.event_id'))
            ->whereNotIn('status', ['cancelled', 'withdrawn'])->exists()) {
            $this->markUnclaimed('The show or show texting was turned off before sending.');
            $this->complete();

            return;
        }

        $sms->sendRecipient($recipient, [
            'tenant_id' => $this->tenantId, 'actor_id' => $recipient->approved_by,
            'batch_id' => 'show-text-'.$this->campaignId, 'source_label' => 'team_show_text',
        ]);
        // The MMS guard can return a validation result before reserving an attempt.
        $this->markUnclaimed('The message could not be sent with this photo or approval.');
        $this->complete();
    }

    public function failed(?\Throwable $exception): void
    {
        $this->markUnclaimed('The send could not start.');
        $this->complete();
    }

    private function markUnclaimed(string $note): void
    {
        MarketingCampaignRecipient::query()->whereKey($this->recipientId)->where('campaign_id', $this->campaignId)
            ->where('status', 'approved')->where('send_attempt_count', 0)->update(['status' => 'failed', 'last_status_note' => $note, 'failed_at' => now()]);
    }

    private function complete(): void
    {
        DB::transaction(function (): void {
            $campaign = MarketingCampaign::forTenantId($this->tenantId)->where('source_label', 'team_show_text')
                ->whereKey($this->campaignId)->lockForUpdate()->first();
            if ($campaign && ! $campaign->recipients()->whereIn('status', ['approved', 'queued_for_approval', 'scheduled', 'sending'])->exists()) {
                $campaign->update(['status' => 'completed', 'completed_at' => now()]);
            }
        });
    }
}
