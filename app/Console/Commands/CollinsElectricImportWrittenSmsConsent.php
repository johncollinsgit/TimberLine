<?php

namespace App\Console\Commands;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\MarketingConsentService;
use Illuminate\Console\Command;

class CollinsElectricImportWrittenSmsConsent extends Command
{
    protected $signature = 'collins-electric:import-written-sms-consent
        {--confirm-written-consent : Required acknowledgement that Collins Electric holds written consent}
        {--source-reference= : Human-readable evidence reference retained in the consent event}
        {--dry-run : Count eligible records without changing consent}';

    protected $description = 'Import Collins Electric written SMS consent with per-customer evidence while preserving explicit opt-outs.';

    public function handle(MarketingConsentService $consent): int
    {
        if (! (bool) $this->option('confirm-written-consent')) {
            $this->error('Pass --confirm-written-consent only after written consent has been verified.');

            return self::FAILURE;
        }
        $reference = trim((string) $this->option('source-reference'));
        if (mb_strlen($reference) < 12) {
            $this->error('--source-reference must describe where the written-consent evidence is retained.');

            return self::FAILURE;
        }
        $tenant = Tenant::query()->where('slug', 'collins-electric')->firstOrFail();
        $dryRun = (bool) $this->option('dry-run');
        $summary = ['scanned' => 0, 'eligible' => 0, 'opted_in' => 0, 'already_opted_in' => 0, 'skipped_no_phone' => 0, 'skipped_explicit_opt_out' => 0];

        MarketingProfile::query()->forTenantId((int) $tenant->id)->whereNull('merged_into_profile_id')->orderBy('id')->chunkById(250, function ($profiles) use ($consent, $tenant, $reference, $dryRun, &$summary): void {
            foreach ($profiles as $profile) {
                $summary['scanned']++;
                if (blank($profile->phone) && blank($profile->normalized_phone)) {
                    $summary['skipped_no_phone']++;

                    continue;
                }
                if ($profile->sms_opted_out_at !== null) {
                    $summary['skipped_explicit_opt_out']++;

                    continue;
                }
                $summary['eligible']++;
                if ($dryRun) {
                    continue;
                }
                $sourceId = 'collins-written-consent:'.$profile->id.':'.substr(sha1($reference), 0, 16);
                if ((bool) $profile->accepts_sms_marketing) {
                    $summary['already_opted_in']++;
                    MarketingConsentEvent::query()->firstOrCreate([
                        'tenant_id' => (int) $tenant->id, 'marketing_profile_id' => (int) $profile->id,
                        'channel' => 'sms', 'event_type' => 'confirmed', 'source_type' => 'written_consent_import', 'source_id' => $sourceId,
                    ], ['details' => ['evidence_reference' => $reference, 'confirmed_by' => 'Collins Electric account owner', 'confirmation_date' => now()->toDateString(), 'previous' => true, 'current' => true], 'occurred_at' => now()]);

                    continue;
                }
                if ($consent->setSmsConsent($profile, true, [
                    'tenant_id' => (int) $tenant->id, 'source_type' => 'written_consent_import', 'source_id' => $sourceId,
                    'details' => ['evidence_reference' => $reference, 'confirmed_by' => 'Collins Electric account owner', 'confirmation_date' => now()->toDateString()],
                ])) {
                    $summary['opted_in']++;
                }
            }
        });

        $this->line('tenant_id='.$tenant->id);
        $this->line('mode='.($dryRun ? 'dry-run' : 'live'));
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
