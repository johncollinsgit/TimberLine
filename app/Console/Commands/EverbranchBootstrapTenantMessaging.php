<?php

namespace App\Console\Commands;

use App\Jobs\BootstrapTenantMessaging;
use App\Models\Tenant;
use Illuminate\Console\Command;

class EverbranchBootstrapTenantMessaging extends Command
{
    protected $signature = 'everbranch:bootstrap-tenant-messaging
        {tenant : Tenant ID or slug}
        {--reply-to= : Customer mailbox that receives email replies}
        {--with-sms : Create the isolated Twilio account and begin SMS setup}';

    protected $description = 'Queue idempotent Everbranch-managed email and optional SMS setup for one allowlisted tenant.';

    public function handle(): int
    {
        if (! (bool) config('features.tenant_messaging_auto_bootstrap') || ! (bool) config('features.tenant_messaging_provisioning')) {
            $this->error('Automatic tenant messaging and provider provisioning must both be enabled.');

            return self::FAILURE;
        }

        $identifier = trim((string) $this->argument('tenant'));
        $tenant = Tenant::query()->where('id', ctype_digit($identifier) ? (int) $identifier : 0)
            ->orWhere('slug', $identifier)->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $allowed = array_map('intval', (array) config('marketing.messaging.platform.automatic_tenant_ids', []));
        if (! in_array((int) $tenant->id, $allowed, true)) {
            $this->error('Tenant is not in MARKETING_MESSAGING_AUTOMATIC_TENANT_IDS.');

            return self::FAILURE;
        }

        $replyTo = strtolower(trim((string) $this->option('reply-to')));
        if ($replyTo !== '' && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $this->error('The --reply-to value must be a valid email address.');

            return self::FAILURE;
        }

        BootstrapTenantMessaging::dispatch(
            (int) $tenant->id,
            $replyTo !== '' ? $replyTo : null,
            (bool) $this->option('with-sms'),
        )->afterCommit();

        $this->info('Tenant messaging bootstrap queued for tenant '.$tenant->id.'.');

        return self::SUCCESS;
    }
}
