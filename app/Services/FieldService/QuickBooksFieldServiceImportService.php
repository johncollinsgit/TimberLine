<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use Illuminate\Support\Str;

class QuickBooksFieldServiceImportService
{
    /** @param array<int,array<string,string>> $rows */
    public function importRows(Tenant $tenant, array $rows, string $type = 'auto', bool $dryRun = false): array
    {
        $summary = $this->emptySummary();

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

        return $summary;
    }

    /** @return array{customers:int,jobs:int,items:int,skipped:int} */
    public function emptySummary(): array
    {
        return ['customers' => 0, 'jobs' => 0, 'items' => 0, 'skipped' => 0];
    }

    /** @param array<string,string> $row */
    public function guessType(array $row): string
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

    /** @param array<string,string> $row */
    public function profileForRow(Tenant $tenant, array $row): MarketingProfile
    {
        $externalId = $this->first($row, ['customer_id', 'id', 'name', 'customer']);
        $linkId = $this->sourceId((int) $tenant->id, $externalId ?: md5(json_encode($row) ?: ''));
        $existingLink = MarketingProfileLink::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('source_type', 'quickbooks_customer')
            ->where('source_id', $linkId)
            ->first();
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
            ['tenant_id' => (int) $tenant->id, 'source_type' => 'quickbooks_customer', 'source_id' => $linkId],
            [
                'marketing_profile_id' => (int) $profile->id,
                'source_meta' => $row,
                'match_method' => 'quickbooks_import',
                'confidence' => 1,
            ]
        );

        return $profile;
    }

    /** @param array<string,string> $row */
    public function jobForRow(Tenant $tenant, array $row): FieldServiceJob
    {
        $profile = $this->profileForRow($tenant, $row);
        $externalId = $this->sourceId((int) $tenant->id, $this->first($row, ['job_id', 'project_id', 'invoice_number', 'estimate_number', 'id']) ?: md5(json_encode($row) ?: ''));
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

    /** @param array<string,string> $row */
    public function materialForRow(Tenant $tenant, array $row): FieldServiceMaterial
    {
        $externalId = $this->sourceId((int) $tenant->id, $this->first($row, ['item_id', 'product_id', 'sku', 'id', 'name']) ?: md5(json_encode($row) ?: ''));
        $name = $this->first($row, ['item', 'product', 'name', 'description']) ?: 'QuickBooks item';

        return FieldServiceMaterial::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id, 'external_source' => 'quickbooks', 'external_id' => $externalId],
            [
                'name' => Str::limit($name, 255, ''),
                'quantity' => (float) ($this->first($row, ['quantity', 'qty']) ?: 1),
                'unit_cost' => is_numeric($this->first($row, ['cost', 'rate', 'price'])) ? (float) $this->first($row, ['cost', 'rate', 'price']) : null,
                'status' => 'needed',
                'notes' => 'Imported from QuickBooks.',
            ]
        );
    }

    protected function key(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    /** @param array<string,string> $row */
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

    /** @return array{0:?string,1:?string} */
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
