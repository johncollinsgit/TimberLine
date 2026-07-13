<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceFinancialDocumentAttachment;
use App\Models\FieldServiceFinancialDocumentLine;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServicePriceBookItem;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuickBooksFieldServiceImportService
{
    public function __construct(
        protected QuickBooksJobEvidenceClassifier $jobEvidenceClassifier
    ) {}

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

    /** @return array<string,int> */
    public function emptySummary(): array
    {
        return [
            'customers' => 0,
            'jobs' => 0,
            'jobs_created' => 0,
            'jobs_updated' => 0,
            'items' => 0,
            'documents' => 0,
            'documents_created' => 0,
            'documents_updated' => 0,
            'documents_linked' => 0,
            'documents_needing_review' => 0,
            'lines' => 0,
            'attachments' => 0,
            'skipped' => 0,
        ];
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

        $name = $this->first($row, ['customer', 'customer_name', 'name', 'display_name', 'company']);
        [$firstName, $lastName] = $this->splitName($name ?: 'QuickBooks Customer');
        $email = strtolower($this->first($row, ['email', 'email_address']));
        $phone = $this->first($row, ['phone', 'phone_number', 'mobile']);

        $profile = $existingLink instanceof MarketingProfileLink
            ? $existingLink->marketingProfile()->first()
            : null;
        $profile ??= $email !== ''
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
        } else {
            $updates = array_filter([
                'first_name' => $name !== '' ? $firstName : null,
                'last_name' => $name !== '' ? $lastName : null,
                'email' => $email !== '' ? $email : null,
                'normalized_email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'address_line_1' => $this->first($row, ['billing_address', 'address', 'street']) ?: null,
                'city' => $this->first($row, ['city']) ?: null,
                'state' => $this->first($row, ['state']) ?: null,
                'postal_code' => $this->first($row, ['zip', 'postal_code']) ?: null,
            ], static fn (mixed $value): bool => $value !== null);
            if ($updates !== []) {
                $profile->forceFill($updates)->save();
            }
        }

        MarketingProfileLink::query()->updateOrCreate(
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

    /** @param array<string,mixed> $transaction
     * @return array{customers:int,jobs:int,items:int,documents:int,lines:int,attachments:int,skipped:int}
     */
    public function importQuickBooksTransaction(
        Tenant $tenant,
        array $transaction,
        string $type,
        bool $dryRun = false,
        array $knownJobCustomerIds = []
    ): array {
        $summary = $this->emptySummary();
        $externalId = trim((string) ($transaction['Id'] ?? ''));
        if ($externalId === '') {
            $summary['skipped']++;

            return $summary;
        }

        $evidence = $this->jobEvidenceClassifier->classify($transaction, $knownJobCustomerIds);
        $summary['documents']++;
        $summary['lines'] += count((array) ($transaction['Line'] ?? []));
        $summary['jobs'] += $evidence['qualifies'] ? 1 : 0;
        $summary['documents_needing_review'] += $evidence['qualifies'] ? 0 : 1;
        if ($dryRun) {
            return $summary;
        }

        return DB::transaction(function () use ($tenant, $transaction, $type, $externalId, $summary, $evidence): array {
            $customer = (array) ($transaction['CustomerRef'] ?? []);
            $profile = $this->profileForRow($tenant, [
                'customer_id' => (string) ($customer['value'] ?? ''),
                'customer' => (string) ($customer['name'] ?? 'QuickBooks Customer'),
                'email' => (string) data_get($transaction, 'BillEmail.Address', ''),
                'phone' => '',
                'billing_address' => (string) data_get($transaction, 'BillAddr.Line1', ''),
                'city' => (string) data_get($transaction, 'BillAddr.City', ''),
                'state' => (string) data_get($transaction, 'BillAddr.CountrySubDivisionCode', ''),
                'postal_code' => (string) data_get($transaction, 'BillAddr.PostalCode', ''),
            ]);

            $existingDocument = FieldServiceFinancialDocument::query()
                ->forTenantId((int) $tenant->id)
                ->where('source', 'quickbooks')
                ->where('document_type', $type)
                ->where('external_id', $externalId)
                ->first();
            $jobResolution = $this->jobForQuickBooksTransaction($tenant, $profile, $transaction, $type, $evidence);
            $job = $jobResolution['job'];
            $summary['documents_created'] += $existingDocument instanceof FieldServiceFinancialDocument ? 0 : 1;
            $summary['documents_updated'] += $existingDocument instanceof FieldServiceFinancialDocument ? 1 : 0;
            $summary['documents_linked'] += $job instanceof FieldServiceJob ? 1 : 0;
            $summary['documents_needing_review'] = $job instanceof FieldServiceJob ? 0 : 1;
            $summary['jobs_created'] += $jobResolution['created'] ? 1 : 0;
            $summary['jobs_updated'] += $jobResolution['updated'] ? 1 : 0;

            $document = FieldServiceFinancialDocument::query()->updateOrCreate(
                [
                    'tenant_id' => (int) $tenant->id,
                    'source' => 'quickbooks',
                    'document_type' => $type,
                    'external_id' => $externalId,
                ],
                [
                    'marketing_profile_id' => (int) $profile->id,
                    'field_service_job_id' => $job?->id,
                    'document_number' => trim((string) ($transaction['DocNumber'] ?? '')) ?: null,
                    'status' => $this->transactionStatus($transaction, $type),
                    'transaction_date' => $transaction['TxnDate'] ?? null,
                    'due_date' => $transaction['DueDate'] ?? $transaction['ExpirationDate'] ?? null,
                    'total_amount' => is_numeric($transaction['TotalAmt'] ?? null) ? (float) $transaction['TotalAmt'] : null,
                    'balance' => is_numeric($transaction['Balance'] ?? null) ? (float) $transaction['Balance'] : null,
                    'currency' => trim((string) data_get($transaction, 'CurrencyRef.value', '')) ?: null,
                    'private_note' => trim((string) ($transaction['PrivateNote'] ?? '')) ?: null,
                    'customer_memo' => trim((string) data_get($transaction, 'CustomerMemo.value', '')) ?: null,
                    'linked_transactions' => array_values((array) ($transaction['LinkedTxn'] ?? [])),
                    'metadata' => [
                        'quickbooks' => [
                            'sync_token' => (string) ($transaction['SyncToken'] ?? ''),
                            'email_status' => (string) ($transaction['EmailStatus'] ?? ''),
                            'print_status' => (string) ($transaction['PrintStatus'] ?? ''),
                            'job_link_status' => $job instanceof FieldServiceJob ? 'linked' : 'needs_review',
                            'job_link_reason' => $jobResolution['reason'],
                            'job_evidence' => $evidence['reasons'],
                        ],
                    ],
                ]
            );

            FieldServiceFinancialDocumentLine::query()
                ->where('tenant_id', (int) $tenant->id)
                ->where('field_service_financial_document_id', (int) $document->id)
                ->delete();
            foreach ((array) ($transaction['Line'] ?? []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $detail = (array) ($line['SalesItemLineDetail'] ?? []);
                FieldServiceFinancialDocumentLine::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_financial_document_id' => (int) $document->id,
                    'source_line_id' => trim((string) ($line['Id'] ?? '')) ?: null,
                    'sort_order' => (int) $index,
                    'detail_type' => trim((string) ($line['DetailType'] ?? '')) ?: null,
                    'item_external_id' => trim((string) data_get($detail, 'ItemRef.value', '')) ?: null,
                    'item_name' => trim((string) data_get($detail, 'ItemRef.name', '')) ?: null,
                    'description' => trim((string) ($line['Description'] ?? '')) ?: null,
                    'quantity' => is_numeric($detail['Qty'] ?? null) ? (float) $detail['Qty'] : null,
                    'unit_price' => is_numeric($detail['UnitPrice'] ?? null) ? (float) $detail['UnitPrice'] : null,
                    'amount' => is_numeric($line['Amount'] ?? null) ? (float) $line['Amount'] : null,
                    'metadata' => [
                        'tax_code' => data_get($detail, 'TaxCodeRef.value'),
                        'class' => data_get($detail, 'ClassRef.name'),
                        'service_date' => $detail['ServiceDate'] ?? null,
                    ],
                ]);
            }

            if ($job instanceof FieldServiceJob) {
                $this->syncQuickBooksNotes($tenant, $job, $transaction, $type, $externalId);
            }

            return $summary;
        });
    }

    /** @param array<string,mixed> $item
     * @return array{customers:int,jobs:int,items:int,documents:int,lines:int,attachments:int,skipped:int}
     */
    public function importQuickBooksItem(Tenant $tenant, array $item, bool $dryRun = false): array
    {
        $summary = $this->emptySummary();
        $externalId = trim((string) ($item['Id'] ?? ''));
        if ($externalId === '') {
            $summary['skipped']++;

            return $summary;
        }
        $summary['items']++;
        if ($dryRun) {
            return $summary;
        }

        FieldServicePriceBookItem::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id, 'source' => 'quickbooks', 'external_id' => $externalId],
            [
                'name' => trim((string) ($item['Name'] ?? $item['Sku'] ?? 'QuickBooks item')),
                'item_type' => trim((string) ($item['Type'] ?? '')) ?: null,
                'sku' => trim((string) ($item['Sku'] ?? '')) ?: null,
                'description' => trim((string) ($item['Description'] ?? '')) ?: null,
                'unit_price' => is_numeric($item['UnitPrice'] ?? null) ? (float) $item['UnitPrice'] : null,
                'purchase_cost' => is_numeric($item['PurchaseCost'] ?? null) ? (float) $item['PurchaseCost'] : null,
                'active' => (bool) ($item['Active'] ?? true),
                'taxable' => array_key_exists('Taxable', $item) ? (bool) $item['Taxable'] : null,
                'metadata' => [
                    'quantity_on_hand' => is_numeric($item['QtyOnHand'] ?? null) ? (float) $item['QtyOnHand'] : null,
                    'income_account' => data_get($item, 'IncomeAccountRef.name'),
                    'expense_account' => data_get($item, 'ExpenseAccountRef.name'),
                ],
            ]
        );

        return $summary;
    }

    /** @param array<string,mixed> $transaction */
    public function importQuickBooksFinancialTransaction(
        Tenant $tenant,
        array $transaction,
        string $type,
        bool $dryRun = false
    ): array {
        $summary = $this->emptySummary();
        $externalId = trim((string) ($transaction['Id'] ?? ''));
        if ($externalId === '') {
            $summary['skipped']++;

            return $summary;
        }

        $summary['documents']++;
        $summary['lines'] += count((array) ($transaction['Line'] ?? []));
        if ($dryRun) {
            return $summary;
        }

        $existing = FieldServiceFinancialDocument::query()
            ->forTenantId((int) $tenant->id)
            ->where('source', 'quickbooks')
            ->where('document_type', $type)
            ->where('external_id', $externalId)
            ->first();
        $summary[$existing ? 'documents_updated' : 'documents_created']++;

        $document = DB::transaction(function () use ($tenant, $transaction, $type, $externalId): FieldServiceFinancialDocument {
            $document = FieldServiceFinancialDocument::query()->updateOrCreate(
                [
                    'tenant_id' => (int) $tenant->id,
                    'source' => 'quickbooks',
                    'document_type' => $type,
                    'external_id' => $externalId,
                ],
                [
                    'document_number' => trim((string) ($transaction['DocNumber'] ?? '')) ?: null,
                    'status' => strtolower(trim((string) ($transaction['TxnStatus'] ?? ''))) ?: null,
                    'transaction_date' => $transaction['TxnDate'] ?? null,
                    'due_date' => $transaction['DueDate'] ?? null,
                    'total_amount' => is_numeric($transaction['TotalAmt'] ?? null) ? (float) $transaction['TotalAmt'] : null,
                    'balance' => is_numeric($transaction['Balance'] ?? null) ? (float) $transaction['Balance'] : null,
                    'currency' => trim((string) data_get($transaction, 'CurrencyRef.value', '')) ?: null,
                    'private_note' => trim((string) ($transaction['PrivateNote'] ?? '')) ?: null,
                    'customer_memo' => trim((string) data_get($transaction, 'CustomerMemo.value', '')) ?: null,
                    'linked_transactions' => array_values((array) ($transaction['LinkedTxn'] ?? [])),
                    'metadata' => [
                        'quickbooks' => [
                            'sync_token' => (string) ($transaction['SyncToken'] ?? ''),
                            'account' => data_get($transaction, 'AccountRef.name'),
                            'account_id' => data_get($transaction, 'AccountRef.value'),
                            'vendor' => data_get($transaction, 'VendorRef.name') ?: data_get($transaction, 'EntityRef.name'),
                            'payment_type' => $transaction['PaymentType'] ?? null,
                            'job_link_status' => 'accounting_only',
                        ],
                    ],
                ]
            );

            FieldServiceFinancialDocumentLine::query()
                ->where('tenant_id', (int) $tenant->id)
                ->where('field_service_financial_document_id', (int) $document->id)
                ->delete();
            foreach ((array) ($transaction['Line'] ?? []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $itemDetail = (array) ($line['ItemBasedExpenseLineDetail'] ?? $line['SalesItemLineDetail'] ?? []);
                $accountDetail = (array) ($line['AccountBasedExpenseLineDetail'] ?? []);
                FieldServiceFinancialDocumentLine::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_financial_document_id' => (int) $document->id,
                    'source_line_id' => trim((string) ($line['Id'] ?? '')) ?: null,
                    'sort_order' => (int) $index,
                    'detail_type' => trim((string) ($line['DetailType'] ?? '')) ?: null,
                    'item_external_id' => trim((string) data_get($itemDetail, 'ItemRef.value', '')) ?: null,
                    'item_name' => trim((string) (data_get($itemDetail, 'ItemRef.name') ?: data_get($accountDetail, 'AccountRef.name', ''))) ?: null,
                    'description' => trim((string) ($line['Description'] ?? '')) ?: null,
                    'quantity' => is_numeric($itemDetail['Qty'] ?? null) ? (float) $itemDetail['Qty'] : null,
                    'unit_price' => is_numeric($itemDetail['UnitPrice'] ?? null) ? (float) $itemDetail['UnitPrice'] : null,
                    'amount' => is_numeric($line['Amount'] ?? null) ? (float) $line['Amount'] : null,
                    'metadata' => [
                        'account_id' => data_get($accountDetail, 'AccountRef.value'),
                        'account_name' => data_get($accountDetail, 'AccountRef.name'),
                        'customer_id' => data_get($accountDetail, 'CustomerRef.value') ?: data_get($itemDetail, 'CustomerRef.value'),
                        'customer_name' => data_get($accountDetail, 'CustomerRef.name') ?: data_get($itemDetail, 'CustomerRef.name'),
                    ],
                ]);
            }

            return $document;
        });

        return $summary;
    }

    /** @param array<int,array<string,mixed>> $attachables
     * @return array{customers:int,jobs:int,items:int,documents:int,lines:int,attachments:int,skipped:int}
     */
    public function importQuickBooksAttachments(Tenant $tenant, array $attachables, bool $dryRun = false): array
    {
        $summary = $this->emptySummary();
        foreach ($attachables as $attachable) {
            $attachmentId = trim((string) ($attachable['Id'] ?? ''));
            foreach ((array) ($attachable['AttachableRef'] ?? []) as $reference) {
                $type = strtolower(trim((string) data_get($reference, 'EntityRef.type', '')));
                $externalId = trim((string) data_get($reference, 'EntityRef.value', ''));
                if ($attachmentId === '' || $externalId === '' || ! in_array($type, ['invoice', 'estimate', 'purchase', 'bill', 'payment'], true)) {
                    $summary['skipped']++;

                    continue;
                }

                $summary['attachments']++;
                if ($dryRun) {
                    continue;
                }

                $document = FieldServiceFinancialDocument::query()
                    ->forTenantId((int) $tenant->id)
                    ->where('source', 'quickbooks')
                    ->where('document_type', $type)
                    ->where('external_id', $externalId)
                    ->first();
                if (! $document instanceof FieldServiceFinancialDocument) {
                    $summary['skipped']++;

                    continue;
                }

                FieldServiceFinancialDocumentAttachment::query()->updateOrCreate(
                    [
                        'field_service_financial_document_id' => (int) $document->id,
                        'external_id' => $attachmentId,
                    ],
                    [
                        'tenant_id' => (int) $tenant->id,
                        'file_name' => trim((string) ($attachable['FileName'] ?? '')) ?: null,
                        'content_type' => trim((string) ($attachable['ContentType'] ?? '')) ?: null,
                        'file_size' => is_numeric($attachable['Size'] ?? null) ? (int) $attachable['Size'] : null,
                        'note' => trim((string) ($attachable['Note'] ?? '')) ?: null,
                        'metadata' => ['category' => $attachable['Category'] ?? null],
                    ]
                );
            }
        }

        return $summary;
    }

    /** @param array<string,mixed> $transaction
     * @param  array{qualifies:bool,reasons:array<int,string>}  $evidence
     * @return array{job:?FieldServiceJob,created:bool,updated:bool,reason:string}
     */
    protected function jobForQuickBooksTransaction(
        Tenant $tenant,
        MarketingProfile $profile,
        array $transaction,
        string $type,
        array $evidence
    ): array {
        foreach ((array) ($transaction['LinkedTxn'] ?? []) as $linked) {
            $linkedType = strtolower(trim((string) ($linked['TxnType'] ?? '')));
            $linkedId = trim((string) ($linked['TxnId'] ?? ''));
            if ($linkedId === '' || ! in_array($linkedType, ['estimate', 'invoice'], true)) {
                continue;
            }
            $linkedDocument = FieldServiceFinancialDocument::query()
                ->forTenantId((int) $tenant->id)
                ->where('source', 'quickbooks')
                ->where('document_type', $linkedType)
                ->where('external_id', $linkedId)
                ->whereNotNull('field_service_job_id')
                ->first();
            if ($linkedDocument?->job instanceof FieldServiceJob) {
                return ['job' => $linkedDocument->job, 'created' => false, 'updated' => false, 'reason' => 'linked_transaction'];
            }
        }

        if (! $evidence['qualifies']) {
            return ['job' => null, 'created' => false, 'updated' => false, 'reason' => 'insufficient_operational_evidence'];
        }

        $externalId = trim((string) ($transaction['Id'] ?? ''));
        $documentNumber = trim((string) ($transaction['DocNumber'] ?? $externalId));
        $customerName = trim((string) data_get($transaction, 'CustomerRef.name', '')) ?: trim($profile->first_name.' '.$profile->last_name);
        $ship = (array) ($transaction['ShipAddr'] ?? $transaction['BillAddr'] ?? []);

        $job = FieldServiceJob::query()->firstOrNew(
            [
                'tenant_id' => (int) $tenant->id,
                'external_source' => 'quickbooks',
                'external_id' => 'quickbooks:'.$type.':'.$externalId,
            ]
        );
        $created = ! $job->exists;
        $job->forceFill([
            'marketing_profile_id' => (int) $profile->id,
            'title' => Str::limit(Str::headline($type).' '.$documentNumber.' · '.$customerName, 255, ''),
            'status' => $type === 'estimate' ? 'quoted' : 'open',
            'customer_name' => $customerName,
            'customer_email' => $profile->email,
            'customer_phone' => $profile->phone,
            'service_address_line_1' => trim((string) ($ship['Line1'] ?? '')) ?: null,
            'service_address_line_2' => trim((string) ($ship['Line2'] ?? '')) ?: null,
            'service_city' => trim((string) ($ship['City'] ?? '')) ?: null,
            'service_state' => trim((string) ($ship['CountrySubDivisionCode'] ?? '')) ?: null,
            'service_postal_code' => trim((string) ($ship['PostalCode'] ?? '')) ?: null,
            'description' => $this->primaryWorkDescription($transaction),
            'metadata' => [
                'quickbooks' => [
                    'document_type' => $type,
                    'external_id' => $externalId,
                    'document_number' => $documentNumber,
                    'job_evidence' => $evidence['reasons'],
                ],
                'pipeline_stage' => $type === 'estimate' ? 'quoted' : 'open',
                'gross_revenue' => is_numeric($transaction['TotalAmt'] ?? null) ? (float) $transaction['TotalAmt'] : null,
                'outstanding_balance' => is_numeric($transaction['Balance'] ?? null) ? (float) $transaction['Balance'] : null,
            ],
        ])->save();

        return ['job' => $job, 'created' => $created, 'updated' => ! $created, 'reason' => implode(',', $evidence['reasons'])];
    }

    /** @param array<string,mixed> $transaction */
    protected function primaryWorkDescription(array $transaction): ?string
    {
        $parts = collect([
            trim((string) data_get($transaction, 'CustomerMemo.value', '')),
            ...collect((array) ($transaction['Line'] ?? []))
                ->pluck('Description')
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->take(5)
                ->all(),
        ])->filter()->unique()->values();

        return $parts->isEmpty() ? null : Str::limit($parts->implode("\n"), 4000, '');
    }

    /** @param array<string,mixed> $transaction */
    protected function syncQuickBooksNotes(Tenant $tenant, FieldServiceJob $job, array $transaction, string $type, string $externalId): void
    {
        $notes = [
            'private_note' => trim((string) ($transaction['PrivateNote'] ?? '')),
            'customer_memo' => trim((string) data_get($transaction, 'CustomerMemo.value', '')),
        ];
        foreach ((array) ($transaction['Line'] ?? []) as $index => $line) {
            $description = trim((string) data_get($line, 'Description', ''));
            if ($description !== '') {
                $notes['line_'.($line['Id'] ?? $index)] = $description;
            }
        }

        foreach (array_filter($notes) as $noteType => $body) {
            $key = $type.':'.$externalId.':'.$noteType;
            $note = FieldServiceJobNote::query()
                ->forTenantId((int) $tenant->id)
                ->where('field_service_job_id', (int) $job->id)
                ->where('metadata->quickbooks_note_key', $key)
                ->first();
            $values = [
                'body' => $body,
                'noted_at' => data_get($transaction, 'MetaData.LastUpdatedTime') ?: now(),
                'metadata' => [
                    'source' => 'quickbooks',
                    'quickbooks_note_key' => $key,
                    'document_type' => $type,
                    'document_external_id' => $externalId,
                    'note_type' => $noteType,
                    'visibility' => $noteType === 'private_note' ? 'owner' : 'team',
                ],
            ];
            $note instanceof FieldServiceJobNote
                ? $note->forceFill($values)->save()
                : FieldServiceJobNote::query()->create($values + [
                    'tenant_id' => (int) $tenant->id,
                    'field_service_job_id' => (int) $job->id,
                ]);
        }
    }

    /** @param array<string,mixed> $transaction */
    protected function transactionStatus(array $transaction, string $type): string
    {
        if ($type === 'invoice') {
            return ((float) ($transaction['Balance'] ?? 0)) > 0 ? 'open' : 'paid';
        }

        return strtolower(trim((string) ($transaction['TxnStatus'] ?? ''))) ?: 'estimate';
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
