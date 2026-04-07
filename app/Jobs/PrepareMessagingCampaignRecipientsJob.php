<?php

namespace App\Jobs;

use App\Services\Marketing\EmbeddedMessagingCampaignDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareMessagingCampaignRecipientsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string,mixed>  $target
     * @param  array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:?string,source_type:string}>  $recipients
     * @param  array<int,int>  $forceSendProfileIds
     * @param  array<string,mixed>|null  $emailTemplate
     */
    public function __construct(
        public int $campaignId,
        public ?int $tenantId,
        public ?string $storeKey,
        public string $channel,
        public array $target,
        public array $recipients,
        public string $body,
        public ?string $subject = null,
        public ?string $senderKey = null,
        public ?int $actorId = null,
        public ?string $sourceLabel = null,
        public array $forceSendProfileIds = [],
        public ?array $emailTemplate = null,
        public ?string $htmlBody = null,
        public mixed $scheduleFor = null,
        public bool $shortenLinks = false
    ) {
    }

    public function handle(EmbeddedMessagingCampaignDispatchService $dispatchService): void
    {
        $dispatchService->prepareCampaignRecipients(
            campaignId: $this->campaignId,
            tenantId: $this->tenantId,
            storeKey: $this->storeKey,
            channel: $this->channel,
            target: $this->target,
            recipients: $this->recipients,
            body: $this->body,
            subject: $this->subject,
            senderKey: $this->senderKey,
            actorId: $this->actorId,
            sourceLabel: $this->sourceLabel,
            forceSendProfileIds: $this->forceSendProfileIds,
            emailTemplate: $this->emailTemplate,
            htmlBody: $this->htmlBody,
            scheduleFor: $this->scheduleFor,
            shortenLinks: $this->shortenLinks,
        );
    }
}
