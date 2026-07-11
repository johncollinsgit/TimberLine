<?php

namespace App\Console\Commands;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FieldServiceImportQuickBooks extends Command
{
    protected $signature = 'field-service:import-quickbooks
        {file : CSV/XLSX export from QuickBooks}
        {--tenant-id= : Tenant ID to import into}
        {--tenant= : Tenant slug to import into}
        {--type=auto : auto|customers|jobs|items}
        {--dry-run : Parse and summarize without writing}';

    protected $description = 'Import QuickBooks CSV/XLSX exports into field-service customers, jobs, and materials.';

    public function handle(): int
    {
        $tenant = $this->tenant();
        if (! $tenant instanceof Tenant) {
            $this->error('Pass --tenant-id or --tenant with a valid Collins/Everbranch workspace.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error('Import file not found: '.$path);

            return self::FAILURE;
        }

        $type = strtolower(trim((string) $this->option('type'))) ?: 'auto';
        if (! in_array($type, ['auto', 'customers', 'jobs', 'items'], true)) {
            $this->error('Invalid --type. Use auto, customers, jobs, or items.');

            return self::FAILURE;
        }

        $rows = $this->rows($path);
        $dryRun = (bool) $this->option('dry-run');
        $summary = ['customers' => 0, 'jobs' => 0, 'items' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $rowType = $type === 'auto' ? $this->guessType($row) : $type;

            if ($rowType === 'customers') {
                if (! $dryRun) {
                    $this->profileForRow($tenant, $row);
                }
                $summary['customers']++;
                continue;
            }

            if ($rowType === 'jobs') {
                if (! $dryRun) {
                    $this->jobForRow($tenant, $row);
                }
                $summary['jobs']++;
                continue;
            }

            if ($rowType === 'items') {
                if (! $dryRun) {
                    $this->materialForRow($tenant, $row);
                }
                $summary['items']++;
                continue;
            }

            $summary['skipped']++;
        }

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live');
        $this->line('tenant='.$tenant->slug);
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }

    protected function tenant(): ?Tenant
    {
        $tenantId = $this->option('tenant-id');
        if (is_numeric($tenantId)) {
            return Tenant::query()->find((int) $tenantId);
        }

        $slug = strtolower(trim((string) $this->option('tenant')));
        if ($slug !== '') {
            return Tenant::query()->where('slug', $slug)->first();
        }

        return null;
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function rows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            $handle = fopen($path, 'r');
            if (! is_resource($handle)) {
                return [];
            }

            $headers = array_map(fn ($value): string => $this->key((string) $value), fgetcsv($handle) ?: []);
            $rows = [];
            while (($line = fgetcsv($handle)) !== false) {
                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $row[$header] = trim((string) ($line[$index] ?? ''));
                    }
                }
                if (array_filter($row) !== []) {
                    $rows[] = $row;
                }
            }
            fclose($handle);

            return $rows;
        }

        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, true);
        $headers = [];
        $rows = [];
        foreach ($sheet as $index => $line) {
            if ($index === 1) {
                $headers = array_map(fn ($value): string => $this->key((string) $value), array_values($line));
                continue;
            }

            $row = [];
            foreach (array_values($line) as $column => $value) {
                $header = $headers[$column] ?? '';
                if ($header !== '') {
                    $row[$header] = trim((string) $value);
                }
            }
            if (array_filter($row) !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function guessType(array $row): string
    {
        $keys = implode(' ', array_keys($row));
        if (str_contains($keys, 'product') || str_contains($keys, 'item') || isset($row['sku'])) {
            return 'items';
        }
        if (str_contains($keys, 'invoice') || str_contains($keys, 'estimate') || str_contains($keys, 'project') || str_contains($keys, 'job')) {
            return 'jobs';
        }

        return 'customers';
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function profileForRow(Tenant $tenant, array $row): MarketingProfile
    {
        $externalId = $this->first($row, ['customer_id', 'id', 'name', 'customer']);
        $linkId = $this->sourceId((int) $tenant->id, $externalId ?: md5(json_encode($row)));
        $existingLink = MarketingProfileLink::query()->where('source_type', 'quickbooks_customer')->where('source_id', $linkId)->first();
        if ($existingLink instanceof MarketingProfileLink) {
            return $existingLink->marketingProfile()->firstOrFail();
        }

        $name = $this->first($row, ['customer', 'customer_name', 'name', 'display_name', 'company']);
        [$firstName, $lastName] = $this->splitName($name ?: 'QuickBooks Customer');
        $email = strtolower($this->first($row, ['email', 'email_address']));
        $phone = $this->first($row, ['phone', 'phone_number', 'mobile']);

        $profile = $email !== ''
            ? MarketingProfile::query()->forTenantId((int) $tenant->id)->where('normalized_email', $email)->first()
            : null;

        if (! $profile instanceof MarketingProfile) {
            $profile = MarketingProfile::query()->create([
                'tenant_id' => (int) $tenant->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email ?: null,
                'normalized_email' => $email ?: null,
                'phone' => $phone ?: null,
                'address_line_1' => $this->first($row, ['billing_address', 'address', 'street']),
                'city' => $this->first($row, ['city']),
                'state' => $this->first($row, ['state']),
                'postal_code' => $this->first($row, ['zip', 'postal_code']),
                'source_channels' => ['quickbooks_import'],
            ]);
        }

        MarketingProfileLink::query()->firstOrCreate(
            ['source_type' => 'quickbooks_customer', 'source_id' => $linkId],
            [
                'tenant_id' => (int) $tenant->id,
                'marketing_profile_id' => (int) $profile->id,
                'source_meta' => $row,
                'match_method' => 'quickbooks_import',
                'confidence' => 1,
            ]
        );

        return $profile;
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function jobForRow(Tenant $tenant, array $row): FieldServiceJob
    {
        $profile = $this->profileForRow($tenant, $row);
        $externalId = $this->sourceId((int) $tenant->id, $this->first($row, ['job_id', 'project_id', 'invoice_number', 'estimate_number', 'id']) ?: md5(json_encode($row)));
        $title = $this->first($row, ['job', 'project', 'invoice', 'estimate', 'title', 'description']) ?: 'QuickBooks job';

        return FieldServiceJob::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id, 'external_source' => 'quickbooks', 'external_id' => $externalId],
            [
                'marketing_profile_id' => (int) $profile->id,
                'title' => Str::limit($title, 255, ''),
                'status' => 'open',
                'customer_name' => trim($profile->first_name.' '.$profile->last_name),
                'customer_email' => $profile->email,
                'customer_phone' => $profile->phone,
                'service_address_line_1' => $this->first($row, ['service_address', 'ship_to', 'address', 'street']),
                'service_city' => $this->first($row, ['service_city', 'city']),
                'service_state' => $this->first($row, ['service_state', 'state']),
                'service_postal_code' => $this->first($row, ['service_zip', 'zip', 'postal_code']),
                'description' => $this->first($row, ['memo', 'description', 'note']),
                'metadata' => [
                    'quickbooks_import' => [
                        'amount' => $this->first($row, ['amount', 'total', 'balance']),
                        'type' => $this->first($row, ['type', 'transaction_type']),
                        'raw' => $row,
                    ],
                ],
            ]
        );
    }

    /**
     * @param  array<string,string>  $row
     */
    protected function materialForRow(Tenant $tenant, array $row): FieldServiceMaterial
    {
        $externalId = $this->sourceId((int) $tenant->id, $this->first($row, ['item_id', 'product_id', 'sku', 'id', 'name']) ?: md5(json_encode($row)));
        $name = $this->first($row, ['item', 'product', 'name', 'description']) ?: 'QuickBooks item';

        return FieldServiceMaterial::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id, 'external_source' => 'quickbooks', 'external_id' => $externalId],
            [
                'name' => Str::limit($name, 255, ''),
                'quantity' => (float) ($this->first($row, ['quantity', 'qty']) ?: 1),
                'unit_cost' => is_numeric($this->first($row, ['cost', 'rate', 'price'])) ? (float) $this->first($row, ['cost', 'rate', 'price']) : null,
                'status' => 'needed',
                'notes' => 'Imported from QuickBooks export.',
            ]
        );
    }

    protected function key(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    /**
     * @param  array<string,string>  $row
     * @param  array<int,string>  $keys
     */
    protected function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    protected function sourceId(int $tenantId, string $externalId): string
    {
        return $tenantId.':'.Str::limit(trim($externalId), 150, '');
    }
}
