<?php

namespace App\Console\Commands;

use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Services\FieldService\FieldServiceWorkCandidateService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class NormalizeCollinsJobDrafts extends Command
{
    protected $signature = 'field-service:normalize-job-drafts {tenant=collins-electric} {--apply : Persist safe title and address normalization}';

    protected $description = 'Normalize untouched QuickBooks invoice-generated job titles and seed Job Draft address data.';

    public function handle(FieldServiceWorkCandidateService $drafts): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->firstOrFail();
        $apply = (bool) $this->option('apply');
        $created = $apply ? $drafts->syncFromFinancialDocuments($tenant) : 0;
        $changed = 0;

        FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->where('external_source', 'quickbooks')
            ->whereRaw('lower(title) like ?', ['%invoice%'])
            ->with('financialDocuments:id,tenant_id,field_service_job_id,document_type,document_number,external_id,metadata')
            ->orderBy('id')->each(function (FieldServiceJob $job) use ($apply, &$changed): void {
                $name = trim((string) $job->customer_name);
                $document = $job->financialDocuments->first(function ($candidate) use ($job, $name): bool {
                    if (strtolower((string) $candidate->document_type) !== 'invoice') {
                        return false;
                    }

                    $legacyTitle = Str::limit('Invoice '.((string) ($candidate->document_number ?: $candidate->external_id)).' · '.$name, 255, '');

                    return hash_equals($legacyTitle, (string) $job->title);
                });
                if ($document === null) {
                    return;
                }
                $title = Str::limit(($name !== '' ? $name : 'Customer').' job', 255, '');
                $address = (array) data_get($document?->metadata, 'quickbooks.service_address', []);
                $updates = ['title' => $title];
                foreach (['line_1' => 'service_address_line_1', 'line_2' => 'service_address_line_2', 'city' => 'service_city', 'state' => 'service_state', 'postal_code' => 'service_postal_code', 'country' => 'service_country'] as $source => $column) {
                    if (blank($job->{$column}) && filled($address[$source] ?? null)) {
                        $updates[$column] = $address[$source];
                    }
                }
                $changed++;
                $this->line(($apply ? 'APPLY ' : 'DRY-RUN ').$job->id.': '.$job->title.' -> '.$title);
                if ($apply) {
                    $job->forceFill($updates)->save();
                }
            });

        $this->info(($apply ? 'Applied' : 'Would apply')." {$changed} job normalization(s); {$created} Job Draft(s) created.");

        return self::SUCCESS;
    }
}
