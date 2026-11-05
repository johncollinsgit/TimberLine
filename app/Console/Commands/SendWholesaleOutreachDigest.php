<?php

namespace App\Console\Commands;

use App\Mail\WholesaleOutreachDigestMail;
use App\Models\MarketingEmailDelivery;
use App\Models\Tenant;
use App\Services\Wholesale\WholesaleOutreachDigest;
use App\Services\Wholesale\WholesaleReportingSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendWholesaleOutreachDigest extends Command
{
    protected $signature = 'wholesale:send-outreach-digest {--tenant= : Workspace slug} {--email= : Internal destination inbox} {--dry-run : Preview report without sending}';

    protected $description = 'Email up to ten wholesale reorder opportunities with personal Gmail draft buttons.';

    public function handle(WholesaleOutreachDigest $digest, WholesaleReportingSnapshot $reporting): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
        $email = trim((string) $this->option('email'));
        if (! $tenant || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid workspace and internal destination inbox are required.');

            return self::FAILURE;
        }
        try {
            $reporting->refresh((int) $tenant->id);
            $report = $digest->snapshot($tenant);
        } catch (\Throwable $error) {
            report($error);
            $report = [
                'tenant' => ['id' => (int) $tenant->id, 'name' => $tenant->name],
                'week' => now('America/New_York')->startOfWeek()->toDateString(),
                'generated_at' => now('America/New_York')->format('M j, Y g:i a T'),
                'rows' => [], 'import_at' => null, 'import_stale' => true, 'source_order_count' => null,
                'reporting_issue' => 'Current complete wholesale history could not be verified. Please review reporting health before contacting accounts.',
            ];
        }
        if ($this->option('dry-run')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if (! app()->runningUnitTests() && ! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            $this->error('Digest delivery requires a real mail transport.');

            return self::FAILURE;
        }
        $key = 'wholesale-outreach:'.$tenant->id.':'.$report['week'].':'.sha1(strtolower($email));
        $lock = Cache::lock($key, 600);
        if (! $lock->get()) {
            $this->info('This weekly digest is already being processed.');

            return self::SUCCESS;
        }
        try {
            // Reserve before provider HTTP. An uncertain send must be reconciled,
            // never automatically repeated, even after this cache lock expires.
            if (MarketingEmailDelivery::query()->forTenantId((int) $tenant->id)->where('batch_id', $key)->exists()) {
                $this->info('This weekly digest already has a delivery record.');

                return self::SUCCESS;
            }
            $delivery = MarketingEmailDelivery::query()->create([
                'tenant_id' => $tenant->id, 'email' => $email, 'batch_id' => $key,
                'campaign_type' => 'operator_recap', 'template_key' => 'wholesale_outreach_digest',
                'provider' => config('mail.default'), 'status' => 'sending',
                'metadata' => ['week' => $report['week'], 'candidate_count' => count($report['rows'])],
            ]);
            try {
                Mail::to($email)->send(new WholesaleOutreachDigestMail($report, (int) $delivery->id));
                $delivery->refresh();
                $delivery->update(['status' => $delivery->status === 'sending' ? 'sent' : $delivery->status, 'sent_at' => $delivery->sent_at ?: now()]);
            } catch (\Throwable $error) {
                report($error);
                $this->error('Digest delivery is uncertain. Reconcile its delivery record before retrying.');

                return self::FAILURE;
            }
        } finally {
            $lock->release();
        }
        $this->info('Weekly wholesale outreach digest sent to the internal inbox.');

        return $report['import_stale'] ? self::FAILURE : self::SUCCESS;
    }
}
