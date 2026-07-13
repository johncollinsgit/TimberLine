<?php

namespace App\Services\FieldService;

use Illuminate\Support\Str;

class QuickBooksJobEvidenceClassifier
{
    /**
     * @param  array<string,mixed>  $transaction
     * @param  array<int,string>  $knownJobCustomerIds
     * @return array{qualifies:bool,reasons:array<int,string>}
     */
    public function classify(array $transaction, array $knownJobCustomerIds = []): array
    {
        $reasons = [];
        $customerId = trim((string) data_get($transaction, 'CustomerRef.value', ''));
        $customerName = trim((string) data_get($transaction, 'CustomerRef.name', ''));

        if ($customerId !== '' && in_array($customerId, $knownJobCustomerIds, true)) {
            $reasons[] = 'quickbooks_job_customer';
        }
        if ($customerName !== '' && str_contains($customerName, ':')) {
            $reasons[] = 'subcustomer_name';
        }
        if (filled(data_get($transaction, 'ProjectRef.value'))) {
            $reasons[] = 'project_reference';
        }
        if (filled($transaction['PrivateNote'] ?? null)) {
            $reasons[] = 'private_note';
        }
        if (filled(data_get($transaction, 'CustomerMemo.value'))) {
            $reasons[] = 'customer_memo';
        }
        if ($this->hasServiceAddressEvidence($transaction)) {
            $reasons[] = 'service_address';
        }
        if (collect((array) ($transaction['Line'] ?? []))->contains(
            fn (mixed $line): bool => filled(data_get($line, 'SalesItemLineDetail.ServiceDate'))
        )) {
            $reasons[] = 'service_date';
        }

        $reasons = array_values(array_unique($reasons));

        return ['qualifies' => $reasons !== [], 'reasons' => $reasons];
    }

    /** @param array<string,mixed> $transaction */
    protected function hasServiceAddressEvidence(array $transaction): bool
    {
        $shipping = $this->normalizedAddress((array) ($transaction['ShipAddr'] ?? []));
        if ($shipping === '') {
            return false;
        }

        $billing = $this->normalizedAddress((array) ($transaction['BillAddr'] ?? []));

        return $billing === '' || $shipping !== $billing;
    }

    /** @param array<string,mixed> $address */
    protected function normalizedAddress(array $address): string
    {
        return Str::of(implode(' ', array_filter([
            $address['Line1'] ?? null,
            $address['Line2'] ?? null,
            $address['City'] ?? null,
            $address['CountrySubDivisionCode'] ?? null,
            $address['PostalCode'] ?? null,
        ])))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
