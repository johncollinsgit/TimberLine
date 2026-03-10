<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingRecommendation;
use App\Models\MarketingSendApproval;

class MarketingApprovalService
{
    public function approveRecipient(MarketingCampaignRecipient $recipient, int $approverId, ?string $notes = null): MarketingCampaignRecipient
    {
        $recipient->forceFill([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'last_status_note' => $notes,
        ])->save();

        MarketingSendApproval::query()->create([
            'campaign_recipient_id' => $recipient->id,
            'approval_type' => 'send',
            'status' => 'approved',
            'approver_id' => $approverId,
            'approved_at' => now(),
            'notes' => $notes,
        ]);

        return $recipient->fresh();
    }

    public function rejectRecipient(MarketingCampaignRecipient $recipient, int $approverId, ?string $notes = null): MarketingCampaignRecipient
    {
        $recipient->forceFill([
            'status' => 'rejected',
            'rejected_by' => $approverId,
            'rejected_at' => now(),
            'last_status_note' => $notes,
        ])->save();

        MarketingSendApproval::query()->create([
            'campaign_recipient_id' => $recipient->id,
            'approval_type' => 'send',
            'status' => 'rejected',
            'approver_id' => $approverId,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        return $recipient->fresh();
    }

    public function approveRecommendation(MarketingRecommendation $recommendation, int $approverId, ?string $notes = null): MarketingRecommendation
    {
        $recommendation->forceFill([
            'status' => 'approved',
            'reviewed_by' => $approverId,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        MarketingSendApproval::query()->create([
            'recommendation_id' => $recommendation->id,
            'approval_type' => $this->approvalTypeForRecommendation($recommendation->type),
            'status' => 'approved',
            'approver_id' => $approverId,
            'approved_at' => now(),
            'notes' => $notes,
        ]);

        return $recommendation->fresh();
    }

    public function rejectRecommendation(MarketingRecommendation $recommendation, int $approverId, ?string $notes = null): MarketingRecommendation
    {
        $recommendation->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $approverId,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        MarketingSendApproval::query()->create([
            'recommendation_id' => $recommendation->id,
            'approval_type' => $this->approvalTypeForRecommendation($recommendation->type),
            'status' => 'rejected',
            'approver_id' => $approverId,
            'rejected_at' => now(),
            'notes' => $notes,
        ]);

        return $recommendation->fresh();
    }

    public function dismissRecommendation(MarketingRecommendation $recommendation, int $approverId, ?string $notes = null): MarketingRecommendation
    {
        $recommendation->forceFill([
            'status' => 'dismissed',
            'reviewed_by' => $approverId,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        return $recommendation->fresh();
    }

    protected function approvalTypeForRecommendation(string $recommendationType): string
    {
        return match ($recommendationType) {
            'send_suggestion' => 'send',
            'copy_improvement' => 'copy_change',
            'timing_suggestion' => 'timing_change',
            default => 'send',
        };
    }
}
