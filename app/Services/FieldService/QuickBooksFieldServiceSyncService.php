<?php

namespace App\Services\FieldService;

use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

class QuickBooksFieldServiceSyncService
{
    public function __construct(
        protected QuickBooksFieldServiceImportService $importService,
        protected QuickBooksGeneratorEquipmentService $generatorEquipment,
        protected WorkspaceAssetService $assets,
        protected FieldServiceJobLifecycleService $lifecycle,
    ) {}

    /** @param array<int,string> $entities */
    public function sync(
        Tenant $tenant,
        QuickBooksOnlineClient $client,
        array $entities,
        bool $dryRun = false,
        ?CarbonInterface $updatedSince = null
    ): array {
        $summary = $this->importService->emptySummary()
            + [
                'quickbooks_customers' => 0, 'quickbooks_invoices' => 0, 'quickbooks_estimates' => 0,
                'quickbooks_payments' => 0, 'quickbooks_purchases' => 0, 'quickbooks_bills' => 0,
                'quickbooks_items' => 0, 'quickbooks_attachments' => 0,
                'generator_installations_detected' => 0, 'generator_equipment_created' => 0, 'generator_equipment_updated' => 0,
                'generator_services_detected' => 0, 'generator_services_linked' => 0, 'generator_services_needing_review' => 0,
                'quickbooks_customers_active' => 0, 'quickbooks_customers_inactive' => 0,
                'quickbooks_customers_with_email' => 0, 'quickbooks_customers_missing_email' => 0,
                'quickbooks_customer_emails_inherited' => 0,
                'quickbooks_customers_with_phone' => 0, 'quickbooks_customers_missing_phone' => 0,
                'quickbooks_customer_phones_from_mobile' => 0,
                'quickbooks_customer_phones_from_alternate' => 0,
                'quickbooks_customer_phones_inherited' => 0,
            ];

        $fetch = fn (string $entity): array => $updatedSince
            ? $client->allSince($entity, $updatedSince)
            : $client->all($entity);
        $quickBooksCustomers = in_array('customers', $entities, true)
            ? ($updatedSince
                ? $client->allCustomersSince($updatedSince)
                : $client->allCustomers())
            : [];
        $knownJobCustomerIds = collect($quickBooksCustomers)
            ->filter(fn (array $customer): bool => (bool) ($customer['Job'] ?? false)
                || filled($customer['ParentRef'] ?? null)
                || str_contains((string) ($customer['FullyQualifiedName'] ?? ''), ':'))
            ->pluck('Id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->merge(MarketingProfileLink::query()->where('tenant_id', (int) $tenant->id)
                ->where('source_type', 'quickbooks_customer')->get(['source_meta'])
                ->filter(fn (MarketingProfileLink $link): bool => (bool) data_get($link->source_meta, 'is_job')
                    || filled(data_get($link->source_meta, 'parent_id'))
                    || str_contains((string) data_get($link->source_meta, 'fully_qualified_name', ''), ':'))
                ->map(fn (MarketingProfileLink $link): string => trim((string) data_get($link->source_meta, 'customer_id'))))
            ->filter()->unique()->values()
            ->all();

        if (in_array('customers', $entities, true)) {
            $rows = $this->customerRows($tenant, $quickBooksCustomers);
            $before = $this->customerReconciliation($tenant, $rows, $updatedSince === null);
            foreach ($before as $key => $value) {
                $summary[$key.'_before'] = $value;
            }

            $summary = $this->mergeSummary($summary, $this->importService->importRows($tenant, $rows, 'customers', $dryRun));
            $summary['quickbooks_customers'] = count($rows);
            $summary['quickbooks_customers_active'] = collect($rows)->where('active', true)->count();
            $summary['quickbooks_customers_inactive'] = count($rows) - $summary['quickbooks_customers_active'];
            $summary['quickbooks_customers_with_email'] = collect($rows)->filter(fn (array $row): bool => filled($row['email'] ?? null))->count();
            $summary['quickbooks_customers_missing_email'] = count($rows) - $summary['quickbooks_customers_with_email'];
            $summary['quickbooks_customer_emails_inherited'] = collect($rows)->filter(
                fn (array $row): bool => str_starts_with((string) ($row['email_source'] ?? ''), 'parent_')
            )->count();
            $summary['quickbooks_customers_with_phone'] = collect($rows)->filter(fn (array $row): bool => filled($row['phone'] ?? null))->count();
            $summary['quickbooks_customers_missing_phone'] = count($rows) - $summary['quickbooks_customers_with_phone'];
            $summary['quickbooks_customer_phones_from_mobile'] = collect($rows)->where('phone_source', 'mobile')->count();
            $summary['quickbooks_customer_phones_from_alternate'] = collect($rows)->where('phone_source', 'alternate_phone')->count();
            $summary['quickbooks_customer_phones_inherited'] = collect($rows)->filter(
                fn (array $row): bool => str_starts_with((string) ($row['phone_source'] ?? ''), 'parent_')
            )->count();

            $after = $dryRun ? $before : $this->customerReconciliation($tenant, $rows, $updatedSince === null);
            foreach ($after as $key => $value) {
                $summary[$key] = $value;
            }
        }

        if (in_array('estimates', $entities, true)) {
            $rows = $fetch('Estimate');
            foreach ($rows as $estimate) {
                $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksTransaction($tenant, $estimate, 'estimate', $dryRun, $knownJobCustomerIds));
            }
            $summary['quickbooks_estimates'] = count($rows);
        }

        if (in_array('invoices', $entities, true)) {
            $rows = $fetch('Invoice');
            foreach ($rows as $invoice) {
                $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksTransaction($tenant, $invoice, 'invoice', $dryRun, $knownJobCustomerIds));
            }
            $summary['quickbooks_invoices'] = count($rows);
            foreach ($this->generatorEquipment->syncInvoices($tenant, $rows, $dryRun) as $key => $value) {
                $summary[$key] = (int) ($summary[$key] ?? 0) + $value;
            }
        }

        foreach (['payments' => 'Payment', 'purchases' => 'Purchase', 'bills' => 'Bill'] as $key => $entity) {
            if (! in_array($key, $entities, true)) {
                continue;
            }

            $rows = $fetch($entity);
            foreach ($rows as $row) {
                $summary = $this->mergeSummary(
                    $summary,
                    $this->importService->importQuickBooksFinancialTransaction($tenant, $row, rtrim($key, 's'), $dryRun)
                );
            }
            $summary['quickbooks_'.$key] = count($rows);
        }

        if (in_array('items', $entities, true)) {
            $rows = $fetch('Item');
            foreach ($rows as $item) {
                $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksItem($tenant, $item, $dryRun));
            }
            $summary['quickbooks_items'] = count($rows);
        }

        if (in_array('attachments', $entities, true)) {
            $rows = $fetch('Attachable');
            $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksAttachments($tenant, $rows, $dryRun));
            if (! $dryRun) {
                foreach ($rows as $attachable) {
                    try {
                        $this->assets->importQuickBooksAttachable($tenant, $client, $attachable);
                    } catch (Throwable) {
                        $summary['skipped']++;
                    }
                }
            }
            $summary['quickbooks_attachments'] = count($rows);
        }

        $summary['recommended_cards'] = $this->recommendedCards($summary);
        $summary['lifecycle'] = $dryRun
            ? $this->lifecycle->reconcileTenant($tenant, true)
            : $this->lifecycle->reconcileTenant($tenant);

        return $summary;
    }

    /** @return array<int,string> */
    public function defaultEntities(): array
    {
        return ['customers', 'estimates', 'invoices', 'payments', 'purchases', 'bills', 'items', 'attachments'];
    }

    /** @return array<int,array<string,string>> */
    public function recommendedCards(array $summary): array
    {
        $cards = [];

        if (($summary['jobs'] ?? 0) > 0) {
            $cards[] = ['key' => 'job_pipeline_value', 'title' => 'Open job pipeline', 'reason' => 'QuickBooks invoices/estimates can show active job value and quoting status.'];
            $cards[] = ['key' => 'job_status_mix', 'title' => 'Job status mix', 'reason' => 'Electrician work benefits from counts for quoting, signed, in process, and finished jobs.'];
        }

        if (($summary['items'] ?? 0) > 0) {
            $cards[] = ['key' => 'materials_spend', 'title' => 'Supplies used this month', 'reason' => 'QuickBooks items can feed material usage and cost visibility.'];
        }

        if (($summary['customers'] ?? 0) > 0) {
            $cards[] = ['key' => 'customer_followups', 'title' => 'Customer follow-ups', 'reason' => 'Imported customers make reminder, callback, and recurring-service queues useful.'];
        }

        if (($summary['quickbooks_invoices'] ?? 0) > 0 || ($summary['quickbooks_estimates'] ?? 0) > 0) {
            $cards[] = ['key' => 'quickbooks_reconciliation', 'title' => 'QuickBooks sync health', 'reason' => 'Show last sync, pulled records, and records that need review before field crews rely on them.'];
        }

        return $cards;
    }

    public function connectionLabel(IntegrationConnection $connection): string
    {
        return trim((string) ($connection->external_account_label ?: $connection->external_account_id ?: 'QuickBooks'));
    }

    /**
     * @param  array<int,array<string,mixed>>  $customers
     * @return array<int,array<string,mixed>>
     */
    protected function customerRows(Tenant $tenant, array $customers): array
    {
        $byId = [];
        foreach ($customers as $customer) {
            $id = trim((string) ($customer['Id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $customer;
            }
        }
        $links = MarketingProfileLink::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('source_type', 'quickbooks_customer')
            ->get(['source_id', 'marketing_profile_id', 'source_meta']);
        $links->each(function (MarketingProfileLink $link) use (&$byId): void {
            $meta = (array) $link->source_meta;
            $id = trim((string) ($meta['customer_id'] ?? ''));
            if ($id !== '' && ($meta['source_record_kind'] ?? null) === 'customer' && ! isset($byId[$id])) {
                $byId[$id] = $meta;
            }
        });
        $linksBySourceId = $links->keyBy('source_id');
        $profileLinkCounts = $links->countBy('marketing_profile_id');

        return array_map(function (array $customer) use ($tenant, $byId, $linksBySourceId, $profileLinkCounts): array {
            $row = $this->customerRow($customer, $byId);
            $sourceId = $this->importService->quickBooksCustomerSourceId(
                (int) $tenant->id,
                (string) $row['customer_id']
            );
            $link = $linksBySourceId->get($sourceId);
            $row['shared_profile'] = $link instanceof MarketingProfileLink
                && (int) $profileLinkCounts->get((int) $link->marketing_profile_id, 0) > 1;

            return $row;
        },
            $customers
        );
    }

    /**
     * @param  array<string,mixed>  $customer
     * @param  array<string,array<string,mixed>>  $customersById
     * @return array<string,mixed>
     */
    protected function customerRow(array $customer, array $customersById): array
    {
        $billing = (array) ($customer['BillAddr'] ?? []);
        $contact = $this->customerContact($customer, $customersById);

        return [
            'customer_id' => (string) ($customer['Id'] ?? ''),
            'customer' => (string) ($customer['DisplayName'] ?? $customer['FullyQualifiedName'] ?? $customer['CompanyName'] ?? ''),
            'company' => (string) ($customer['CompanyName'] ?? ''),
            'email' => $contact['email'],
            'email_source' => $contact['email_source'],
            'phone' => $contact['phone'],
            'phone_source' => $contact['phone_source'],
            'billing_address' => (string) ($billing['Line1'] ?? ''),
            'city' => (string) ($billing['City'] ?? ''),
            'state' => (string) ($billing['CountrySubDivisionCode'] ?? ''),
            'postal_code' => (string) ($billing['PostalCode'] ?? ''),
            'is_job' => (bool) ($customer['Job'] ?? false),
            'parent_id' => (string) data_get($customer, 'ParentRef.value', ''),
            'bill_with_parent' => (bool) ($customer['BillWithParent'] ?? false),
            'fully_qualified_name' => (string) ($customer['FullyQualifiedName'] ?? ''),
            'active' => (bool) ($customer['Active'] ?? true),
        ];
    }

    /**
     * Resolve only contact values explicitly supplied by QBO. A subcustomer that
     * QBO marks BillWithParent may inherit a missing value from its ParentRef,
     * with provenance retained in source_meta; no placeholder is manufactured.
     *
     * @param  array<string,mixed>  $customer
     * @param  array<string,array<string,mixed>>  $customersById
     * @param  array<string,bool>  $visited
     * @return array{email:string,email_source:string,phone:string,phone_source:string}
     */
    protected function customerContact(array $customer, array $customersById, array $visited = []): array
    {
        $id = trim((string) ($customer['Id'] ?? $customer['customer_id'] ?? ''));
        if ($id !== '') {
            if (isset($visited[$id])) {
                return ['email' => '', 'email_source' => 'missing', 'phone' => '', 'phone_source' => 'missing'];
            }
            $visited[$id] = true;
        }

        $email = trim((string) data_get($customer, 'PrimaryEmailAddr.Address', ''));
        $emailSource = $email !== '' ? 'primary_email' : 'missing';
        if ($email === '' && filled($customer['email'] ?? null)) {
            $email = trim((string) $customer['email']);
            $emailSource = trim((string) ($customer['email_source'] ?? 'primary_email')) ?: 'primary_email';
        }

        $phoneCandidates = [
            'primary_phone' => data_get($customer, 'PrimaryPhone.FreeFormNumber'),
            'mobile' => data_get($customer, 'Mobile.FreeFormNumber'),
            'alternate_phone' => data_get($customer, 'AlternatePhone.FreeFormNumber'),
        ];
        $phone = '';
        $phoneSource = 'missing';
        foreach ($phoneCandidates as $source => $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $phone = $candidate;
                $phoneSource = $source;
                break;
            }
        }
        if ($phone === '' && filled($customer['phone'] ?? null)) {
            $phone = trim((string) $customer['phone']);
            $phoneSource = trim((string) ($customer['phone_source'] ?? 'primary_phone')) ?: 'primary_phone';
        }

        $parentId = trim((string) (data_get($customer, 'ParentRef.value') ?: ($customer['parent_id'] ?? '')));
        $billWithParent = (bool) ($customer['BillWithParent'] ?? $customer['bill_with_parent'] ?? false);
        $parent = $parentId !== '' && $billWithParent ? ($customersById[$parentId] ?? null) : null;
        if (($email === '' || $phone === '') && is_array($parent)) {
            $parentContact = $this->customerContact($parent, $customersById, $visited);
            if ($email === '' && $parentContact['email'] !== '') {
                $email = $parentContact['email'];
                $emailSource = 'parent_'.$parentContact['email_source'];
            }
            if ($phone === '' && $parentContact['phone'] !== '') {
                $phone = $parentContact['phone'];
                $phoneSource = 'parent_'.$parentContact['phone_source'];
            }
        }

        return [
            'email' => $email,
            'email_source' => $emailSource,
            'phone' => $phone,
            'phone_source' => $phoneSource,
        ];
    }

    /**
     * Compare remote customer IDs and contacts to tenant-owned local links without
     * exposing customer values. Extra-link counts are meaningful only for a full
     * customer snapshot, never for an incremental batch.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    protected function customerReconciliation(Tenant $tenant, array $rows, bool $completeSnapshot): array
    {
        $tenantId = (int) $tenant->id;
        $remoteRows = collect($rows)
            ->filter(fn (array $row): bool => filled($row['customer_id'] ?? null))
            ->unique(fn (array $row): string => trim((string) $row['customer_id']))
            ->values();
        $remoteSourceIds = $remoteRows
            ->map(fn (array $row): string => $this->importService->quickBooksCustomerSourceId(
                $tenantId,
                trim((string) $row['customer_id'])
            ));
        $remoteRowsBySourceId = $remoteRows->keyBy(fn (array $row): string => $this->importService
            ->quickBooksCustomerSourceId($tenantId, trim((string) $row['customer_id'])));
        $links = MarketingProfileLink::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'quickbooks_customer')
            ->get(['source_id', 'marketing_profile_id']);
        $linksBySourceId = $links->keyBy('source_id');
        $profiles = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->whereIn('id', $links->pluck('marketing_profile_id')->unique())
            ->get(['id', 'email', 'phone'])
            ->keyBy('id');
        $matchedLinks = $links->filter(fn (MarketingProfileLink $link): bool => $remoteRowsBySourceId->has($link->source_id));
        $sharedProfileGroups = $matchedLinks
            ->groupBy('marketing_profile_id')
            ->filter(fn ($group): bool => $group->count() > 1);
        $sharedEmailConflictGroups = $sharedProfileGroups->filter(function ($group) use ($remoteRowsBySourceId): bool {
            return $group->map(fn (MarketingProfileLink $link): string => strtolower(trim((string) data_get(
                $remoteRowsBySourceId->get($link->source_id),
                'email'
            ))))->filter()->unique()->count() > 1;
        });
        $sharedPhoneConflictGroups = $sharedProfileGroups->filter(function ($group) use ($remoteRowsBySourceId): bool {
            return $group->map(fn (MarketingProfileLink $link): string => (string) preg_replace(
                '/[^0-9]+/',
                '',
                (string) data_get($remoteRowsBySourceId->get($link->source_id), 'phone')
            ))->filter()->unique()->count() > 1;
        });

        $emailExpected = 0;
        $emailMissing = 0;
        $emailDifferent = 0;
        $phoneExpected = 0;
        $phoneMissing = 0;
        $phoneDifferent = 0;
        foreach ($remoteRows as $row) {
            $sourceId = $this->importService->quickBooksCustomerSourceId($tenantId, (string) $row['customer_id']);
            $link = $linksBySourceId->get($sourceId);
            $profile = $link instanceof MarketingProfileLink
                ? $profiles->get((int) $link->marketing_profile_id)
                : null;

            $expectedEmail = strtolower(trim((string) ($row['email'] ?? '')));
            if ($expectedEmail !== '') {
                $emailExpected++;
                $localEmail = strtolower(trim((string) ($profile?->email ?? '')));
                if ($localEmail === '') {
                    $emailMissing++;
                } elseif ($localEmail !== $expectedEmail) {
                    $emailDifferent++;
                }
            }

            $expectedPhone = preg_replace('/[^0-9]+/', '', (string) ($row['phone'] ?? ''));
            if ($expectedPhone !== '') {
                $phoneExpected++;
                $localPhone = preg_replace('/[^0-9]+/', '', (string) ($profile?->phone ?? ''));
                if ($localPhone === '') {
                    $phoneMissing++;
                } elseif ($localPhone !== $expectedPhone) {
                    $phoneDifferent++;
                }
            }
        }

        $missingIdCount = collect($rows)->filter(fn (array $row): bool => blank($row['customer_id'] ?? null))->count();

        return [
            'quickbooks_customer_reconciliation_complete_snapshot' => $completeSnapshot ? 1 : 0,
            'quickbooks_customer_rows_missing_id' => $missingIdCount,
            'quickbooks_customer_duplicate_ids' => count($rows) - $remoteRows->count() - $missingIdCount,
            'quickbooks_customer_links_local' => $links->count(),
            'quickbooks_customer_links_matched' => $remoteSourceIds->filter(fn (string $id): bool => $linksBySourceId->has($id))->count(),
            'quickbooks_customer_links_missing' => $remoteSourceIds->reject(fn (string $id): bool => $linksBySourceId->has($id))->count(),
            'quickbooks_customer_links_extra' => $completeSnapshot
                ? $links->reject(fn (MarketingProfileLink $link): bool => $remoteSourceIds->contains($link->source_id))->count()
                : 0,
            'quickbooks_customer_profiles_linked' => $matchedLinks->pluck('marketing_profile_id')->unique()->count(),
            'quickbooks_customer_profiles_shared' => $sharedProfileGroups->count(),
            'quickbooks_customers_on_shared_profiles' => $sharedProfileGroups->sum(fn ($group): int => $group->count()),
            'quickbooks_customer_shared_profile_email_conflicts' => $sharedEmailConflictGroups->count(),
            'quickbooks_customer_shared_profile_phone_conflicts' => $sharedPhoneConflictGroups->count(),
            'quickbooks_customer_emails_expected' => $emailExpected,
            'quickbooks_customer_emails_missing_local' => $emailMissing,
            'quickbooks_customer_emails_different_local' => $emailDifferent,
            'quickbooks_customer_phones_expected' => $phoneExpected,
            'quickbooks_customer_phones_missing_local' => $phoneMissing,
            'quickbooks_customer_phones_different_local' => $phoneDifferent,
        ];
    }

    /** @param array<string,mixed> $transaction */
    protected function transactionRow(array $transaction, string $type): array
    {
        $ship = (array) ($transaction['ShipAddr'] ?? []);
        if (blank($ship['Line1'] ?? null)) {
            $ship = (array) ($transaction['BillAddr'] ?? []);
        }
        $customer = (array) ($transaction['CustomerRef'] ?? []);
        $docNumber = (string) ($transaction['DocNumber'] ?? $transaction['Id'] ?? '');
        $lineDescriptions = collect((array) ($transaction['Line'] ?? []))
            ->map(fn (mixed $line): string => trim((string) data_get($line, 'Description', '')))
            ->filter()
            ->take(5)
            ->implode('; ');

        return [
            'id' => (string) ($transaction['Id'] ?? ''),
            $type.'_number' => $docNumber,
            'transaction_type' => $type,
            'customer_id' => (string) ($customer['value'] ?? ''),
            'customer' => (string) ($customer['name'] ?? ''),
            'job' => trim(Str::headline($type).' '.$docNumber.' '.($customer['name'] ?? '')),
            'amount' => (string) ($transaction['TotalAmt'] ?? $transaction['Balance'] ?? ''),
            'balance' => (string) ($transaction['Balance'] ?? ''),
            'service_address' => (string) ($ship['Line1'] ?? ''),
            'service_city' => (string) ($ship['City'] ?? ''),
            'service_state' => (string) ($ship['CountrySubDivisionCode'] ?? ''),
            'service_zip' => (string) ($ship['PostalCode'] ?? ''),
            'memo' => (string) (data_get($transaction, 'CustomerMemo.value', '') ?: $lineDescriptions),
        ];
    }

    /** @param array<string,mixed> $item */
    protected function itemRow(array $item): array
    {
        return [
            'item_id' => (string) ($item['Id'] ?? ''),
            'name' => (string) ($item['Name'] ?? $item['Sku'] ?? ''),
            'description' => (string) ($item['Description'] ?? ''),
            'sku' => (string) ($item['Sku'] ?? ''),
            'quantity' => (string) ($item['QtyOnHand'] ?? 1),
            'cost' => (string) ($item['PurchaseCost'] ?? $item['UnitPrice'] ?? ''),
        ];
    }

    /** @param array<string,int|array<int,array<string,string>>> $left */
    protected function mergeSummary(array $left, array $right): array
    {
        foreach (array_keys($this->importService->emptySummary()) as $key) {
            $left[$key] = (int) ($left[$key] ?? 0) + (int) ($right[$key] ?? 0);
        }

        return $left;
    }
}
