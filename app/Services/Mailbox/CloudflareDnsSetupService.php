<?php

namespace App\Services\Mailbox;

use App\Models\TenantMailDomain;
use App\Models\TenantMessagingSenderProfile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CloudflareDnsSetupService
{
    public function apply(TenantMailDomain $domain, string $token): string
    {
        if (trim($token) === '') {
            throw ValidationException::withMessages(['cloudflare' => 'Enter a zone-scoped Cloudflare API token.']);
        }
        $client = Http::withToken(trim($token))->acceptJson()->timeout(12);
        $zones = $client->get('https://api.cloudflare.com/client/v4/zones', ['name' => $domain->domain, 'per_page' => 50]);
        $zone = collect($zones->json('result', []))->first(fn (array $row): bool => strtolower((string) ($row['name'] ?? '')) === $domain->domain && ($row['status'] ?? null) === 'active');
        $zoneId = is_array($zone) ? (string) ($zone['id'] ?? '') : '';
        if (! $zones->successful() || ! $zones->json('success') || ! preg_match('/^[a-f0-9]{32}$/i', $zoneId)) {
            throw ValidationException::withMessages(['cloudflare' => 'This token cannot access the active Cloudflare zone for your mail domain. Give it Zone Read and DNS Write for that zone.']);
        }
        $base = 'https://api.cloudflare.com/client/v4/zones/'.$zoneId;
        $proof = collect($domain->dns_records)->firstWhere('type', 'TXT');
        $mx = collect($domain->dns_records)->firstWhere('type', 'MX');
        if (! is_array($proof) || ! is_array($mx)) {
            throw ValidationException::withMessages(['cloudflare' => 'Mail DNS records are missing. Contact Everbranch support.']);
        }
        $recordsUrl = $base.'/dns_records';
        $txtRecords = $client->get($recordsUrl, ['type' => 'TXT', 'name' => $proof['host'], 'per_page' => 100]);
        if (! $txtRecords->successful() || ! $txtRecords->json('success')) {
            throw ValidationException::withMessages(['cloudflare' => 'Could not read DNS records. Give this token Zone Read and DNS Write for this zone.']);
        }
        $hasProof = collect($txtRecords->json('result', []))->contains(fn (array $row): bool => $row['name'] === $proof['host'] && $row['content'] === $proof['value']);
        if (! $hasProof) {
            $created = $client->post($recordsUrl, ['type' => 'TXT', 'name' => $proof['host'], 'content' => $proof['value'], 'ttl' => 1]);
            if (! $created->successful() || ! $created->json('success')) {
                throw ValidationException::withMessages(['cloudflare' => 'Cloudflare did not accept the ownership TXT record. Check the token permissions and existing records.']);
            }
        }

        if (! $this->transportReady($domain)) {
            return 'Cloudflare ownership record added. Mail routing remains unchanged until Everbranch confirms the mail transport is ready.';
        }

        $mxRecords = $client->get($recordsUrl, ['type' => 'MX', 'name' => $domain->domain, 'per_page' => 100]);
        if (! $mxRecords->successful() || ! $mxRecords->json('success')) {
            throw ValidationException::withMessages(['cloudflare' => 'Ownership was added, but existing MX records could not be checked. Mail routing was not changed.']);
        }
        $existing = collect($mxRecords->json('result', []));
        $expected = strtolower(rtrim((string) $mx['value'], '.'));
        if ($existing->contains(fn (array $row): bool => strtolower(rtrim((string) ($row['content'] ?? ''), '.')) !== $expected)) {
            return 'Cloudflare ownership record added. Existing MX records belong to another mail service, so routing was left unchanged. Plan the mailbox migration with Everbranch.';
        }
        if ($existing->isNotEmpty()) {
            return 'Cloudflare ownership and MX records are already present. Check setup after DNS propagation.';
        }
        $created = $client->post($recordsUrl, ['type' => 'MX', 'name' => $domain->domain, 'content' => $mx['value'], 'priority' => (int) ($mx['priority'] ?? 10), 'ttl' => 1]);
        if (! $created->successful() || ! $created->json('success')) {
            throw ValidationException::withMessages(['cloudflare' => 'Ownership was added, but Cloudflare did not accept the MX record. Mail routing was not changed.']);
        }

        return 'Cloudflare ownership and MX records added. Check setup after DNS propagation.';
    }

    private function transportReady(TenantMailDomain $domain): bool
    {
        if ($domain->transport === 'direct') {
            return (bool) config('mailbox.direct_enabled') && filled($domain->provider_domain_id)
                && $domain->mailboxes()->whereNotNull('provider_account_id')->exists();
        }

        return filled(config('mailbox.inbound_token')) && TenantMessagingSenderProfile::query()->forTenantId($domain->tenant_id)
            ->where('authenticated_domain', $domain->domain)
            ->whereIn('from_email', $domain->mailboxes()->pluck('address'))
            ->where('verification_status', 'verified')->exists();
    }
}
