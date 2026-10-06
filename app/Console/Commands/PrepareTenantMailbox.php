<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Mailbox\TenantMailboxService;
use Illuminate\Console\Command;

class PrepareTenantMailbox extends Command
{
    protected $signature = 'mailboxes:prepare {tenant-slug} {address} {--transport=sendgrid}';

    protected $description = 'Create a pending tenant mailbox and grant its existing workspace admins access';

    public function handle(TenantMailboxService $mail): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant-slug'))->firstOrFail();
        $address = strtolower(trim((string) $this->argument('address')));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }
        [$localPart, $domainName] = explode('@', $address, 2);
        $domain = $mail->createDomain($tenant, $domainName, (string) $this->option('transport'));
        $admins = $tenant->users()->wherePivot('membership_active', true)->wherePivotIn('role', ['admin', 'owner', 'tenant_owner'])->get();
        if ($admins->isEmpty()) {
            $this->error('No active workspace admin exists.');

            return self::FAILURE;
        }
        foreach ($admins as $admin) {
            $mail->createMailbox($domain, $localPart, $tenant->name, $admin);
        }
        $this->info($address.' is staged for '.$tenant->slug.'; DNS and transport verification are still required.');

        return self::SUCCESS;
    }
}
