<?php

namespace App\Console\Commands;

use App\Models\TenantMailbox;
use App\Services\Mailbox\StalwartMailboxSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTenantMailboxes extends Command
{
    protected $signature = 'mailboxes:sync {--mailbox= : Limit to one mailbox ID}';

    protected $description = 'Synchronize direct-server mail into tenant inboxes';

    public function handle(StalwartMailboxSyncService $sync): int
    {
        if (! config('mailbox.direct_enabled')) {
            $this->warn('Direct mail is disabled.');

            return self::SUCCESS;
        }
        $query = TenantMailbox::query()->forAllTenants()->with('domain')->where('status', 'ready')->whereNotNull('provider_account_id');
        if ($id = $this->option('mailbox')) {
            $query->whereKey((int) $id);
        }
        $errors = 0;
        $added = 0;
        foreach ($query->cursor() as $mailbox) {
            if ($mailbox->domain->transport !== 'direct') {
                continue;
            }
            try {
                $added += $sync->sync($mailbox);
            } catch (\Throwable $error) {
                $errors++;
                Log::error('Tenant mailbox sync failed', ['tenant_id' => $mailbox->tenant_id, 'mailbox_id' => $mailbox->id, 'error' => $error->getMessage()]);
            }
        }
        $this->info("Synchronized {$added} messages; {$errors} mailbox errors.");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
