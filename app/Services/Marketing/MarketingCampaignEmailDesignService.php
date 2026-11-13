<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use Illuminate\Validation\ValidationException;

class MarketingCampaignEmailDesignService
{
    public function snapshotForCampaign(MarketingCampaign $campaign): array
    {
        $html = trim((string) $campaign->message_html);

        return ['html_sha256' => $html !== '' ? hash('sha256', $html) : null];
    }

    public function sameSelection(mixed $left, mixed $right): bool
    {
        try {
            return $this->selection($left) === $this->selection($right);
        } catch (ValidationException) {
            return false;
        }
    }

    public function forApproval(MarketingCampaignRecipient $recipient): array
    {
        [$campaign, $currentRecipient] = $this->currentPair($recipient);
        $design = $this->snapshotForCampaign($campaign);
        $prepared = data_get($currentRecipient->recommendation_snapshot, 'email_design');
        $priorApproval = $this->selection(data_get($currentRecipient->recommendation_snapshot, 'email_design_approval'));
        $this->require($this->sameSelection($design, $prepared)
            && ! ($prepared === null && $priorApproval['html_sha256'] !== null),
            'Prepare the current email design before approving this recipient.');

        return $design;
    }

    public function assertApproved(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): void
    {
        [$currentCampaign, $currentRecipient] = $this->currentPair($recipient);
        $this->require($currentCampaign->id === $campaign->id
            && $currentCampaign->tenant_id === $campaign->tenant_id
            && (int) $currentRecipient->marketing_profile_id === (int) $recipient->marketing_profile_id,
            'The email campaign ownership or recipient identity changed.');
        $this->assertLockedApproved($currentCampaign, $currentRecipient);

        // The executor must compose the same current campaign body that passed
        // this check, even when its caller supplied previously loaded models.
        $campaign->setRawAttributes($currentCampaign->getAttributes(), true);
        $recipient->setRawAttributes($currentRecipient->getAttributes(), true);
    }

    /** Validates the supplied rows without rereading; send claims hold both locks. */
    public function assertLockedApproved(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): void
    {
        $this->require((int) $recipient->campaign_id === (int) $campaign->id
            && $campaign->channel === 'email' && $recipient->channel === 'email', 'The email campaign or recipient changed.');
        $design = $this->snapshotForCampaign($campaign);
        $prepared = $this->selection(data_get($recipient->recommendation_snapshot, 'email_design'));
        $approved = $this->selection(data_get($recipient->recommendation_snapshot, 'email_design_approval'));

        // Legacy plaintext recipients have no HTML approval to invalidate.
        if ($design['html_sha256'] !== null || $prepared['html_sha256'] !== null || $approved['html_sha256'] !== null) {
            $this->require($design === $prepared && $design === $approved
                && in_array($recipient->status, ['approved', 'scheduled', 'failed', 'undelivered'], true)
                && $recipient->approved_by !== null && $recipient->approved_at !== null,
                'Prepare and approve the current email design before sending.');
        }
    }

    protected function currentPair(MarketingCampaignRecipient $recipient): array
    {
        $currentRecipient = MarketingCampaignRecipient::query()->find($recipient->id);
        $campaign = $currentRecipient?->campaign()->first();
        $this->require($campaign !== null && $currentRecipient !== null
            && $campaign->channel === 'email' && $currentRecipient->channel === 'email', 'The email campaign or recipient is missing.');

        return [$campaign, $currentRecipient];
    }

    protected function selection(mixed $value): array
    {
        if ($value === null) {
            return ['html_sha256' => null];
        }
        $this->require(is_array($value) && array_key_exists('html_sha256', $value), 'The email design review snapshot is invalid.');
        $hash = $value['html_sha256'];
        $this->require($hash === null || (is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1), 'The email design checksum is invalid.');

        return ['html_sha256' => $hash];
    }

    protected function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['email_design' => $message]);
        }
    }
}
