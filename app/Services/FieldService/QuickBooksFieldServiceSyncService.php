<?php

namespace App\Services\FieldService;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksOnlineClient;
use Illuminate\Support\Str;

class QuickBooksFieldServiceSyncService
{
    public function __construct(
        protected QuickBooksFieldServiceImportService $importService
    ) {}

    /** @param array<int,string> $entities */
    public function sync(Tenant $tenant, QuickBooksOnlineClient $client, array $entities, bool $dryRun = false): array
    {
        $summary = $this->importService->emptySummary()
            + ['quickbooks_customers' => 0, 'quickbooks_invoices' => 0, 'quickbooks_estimates' => 0, 'quickbooks_items' => 0, 'quickbooks_attachments' => 0];

        $quickBooksCustomers = in_array('customers', $entities, true) ? $client->all('Customer') : [];
        $knownJobCustomerIds = collect($quickBooksCustomers)
            ->filter(fn (array $customer): bool => (bool) ($customer['Job'] ?? false)
                || filled($customer['ParentRef'] ?? null)
                || str_contains((string) ($customer['FullyQualifiedName'] ?? ''), ':'))
            ->pluck('Id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();

        if (in_array('customers', $entities, true)) {
            $rows = array_map(fn (array $customer): array => $this->customerRow($customer), $quickBooksCustomers);
            $summary = $this->mergeSummary($summary, $this->importService->importRows($tenant, $rows, 'customers', $dryRun));
            $summary['quickbooks_customers'] = count($rows);
        }

        if (in_array('estimates', $entities, true)) {
            $rows = $client->all('Estimate');
            foreach ($rows as $estimate) {
                $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksTransaction($tenant, $estimate, 'estimate', $dryRun, $knownJobCustomerIds));
            }
            $summary['quickbooks_estimates'] = count($rows);
        }

        if (in_array('invoices', $entities, true)) {
            $rows = $client->all('Invoice');
            foreach ($rows as $invoice) {
                $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksTransaction($tenant, $invoice, 'invoice', $dryRun, $knownJobCustomerIds));
            }
            $summary['quickbooks_invoices'] = count($rows);
        }

        if (in_array('items', $entities, true)) {
            $rows = $client->all('Item');
            foreach ($rows as $item) {
                $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksItem($tenant, $item, $dryRun));
            }
            $summary['quickbooks_items'] = count($rows);
        }

        if (in_array('attachments', $entities, true)) {
            $rows = $client->all('Attachable');
            $summary = $this->mergeSummary($summary, $this->importService->importQuickBooksAttachments($tenant, $rows, $dryRun));
            $summary['quickbooks_attachments'] = count($rows);
        }

        $summary['recommended_cards'] = $this->recommendedCards($summary);

        return $summary;
    }

    /** @return array<int,string> */
    public function defaultEntities(): array
    {
        return ['customers', 'estimates', 'invoices', 'items', 'attachments'];
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

    /** @param array<string,mixed> $customer */
    protected function customerRow(array $customer): array
    {
        $billing = (array) ($customer['BillAddr'] ?? []);

        return [
            'customer_id' => (string) ($customer['Id'] ?? ''),
            'customer' => (string) ($customer['DisplayName'] ?? $customer['FullyQualifiedName'] ?? $customer['CompanyName'] ?? ''),
            'company' => (string) ($customer['CompanyName'] ?? ''),
            'email' => (string) data_get($customer, 'PrimaryEmailAddr.Address', ''),
            'phone' => (string) data_get($customer, 'PrimaryPhone.FreeFormNumber', ''),
            'billing_address' => (string) ($billing['Line1'] ?? ''),
            'city' => (string) ($billing['City'] ?? ''),
            'state' => (string) ($billing['CountrySubDivisionCode'] ?? ''),
            'postal_code' => (string) ($billing['PostalCode'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $transaction */
    protected function transactionRow(array $transaction, string $type): array
    {
        $ship = (array) ($transaction['ShipAddr'] ?? $transaction['BillAddr'] ?? []);
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
