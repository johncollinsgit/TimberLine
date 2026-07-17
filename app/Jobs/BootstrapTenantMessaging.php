<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantMessagingAccount;
use App\Services\Marketing\Messaging\TenantMessagingProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BootstrapTenantMessaging implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $tenantId,
        public ?string $replyToEmail = null,
        public bool $includeSms = false,
    ) {
        $this->onQueue((string) config('marketing.messaging.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'tenant-messaging-bootstrap:'.$this->tenantId;
    }

    public function handle(TenantMessagingProvisioningService $provisioning): void
    {
        $tenant = Tenant::query()->with(['accessProfile', 'users'])->findOrFail($this->tenantId);
        $replyTo = strtolower(trim((string) ($this->replyToEmail
            ?: data_get($tenant->accessProfile?->metadata, 'admin.primary_contact_email')
            ?: $tenant->users->first()?->email
            ?: config('services.sendgrid.managed_reply_to'))));

        $provisioning->bootstrap($tenant, $replyTo, $this->includeSms);
        $smsAccount = $this->includeSms
            ? TenantMessagingAccount::query()->forAllTenants()->where('tenant_id', $this->tenantId)->where('channel', 'sms')->first()
            : null;
        if ($smsAccount && (array) $smsAccount->compliance_profile !== []) {
            $provisioning->submitTollFreeVerification($this->tenantId);
        }
    }
}
