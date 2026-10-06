<?php

namespace App\Services\Mailbox;

use App\Models\TenantMailbox;
use App\Models\TenantMailDomain;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StalwartProvisioningService
{
    public function __construct(private TenantMailboxService $mail) {}

    public function provisionDomain(TenantMailDomain $domain): TenantMailDomain
    {
        $this->enabled($domain);
        if (! $this->mail->domainOwnershipVerified($domain)) {
            throw new RuntimeException('Verify the domain ownership TXT record before provisioning.');
        }
        if ($domain->provider_domain_id) {
            return $domain;
        }
        $result = $this->call('x:Domain/set', ['create' => ['new1' => [
            'name' => $domain->domain,
            'aliases' => (object) [],
            'certificateManagement' => ['@type' => 'Manual'],
            'dkimManagement' => ['@type' => 'Automatic'],
            'dnsManagement' => ['@type' => 'Manual'],
            'subAddressing' => ['@type' => 'Enabled'],
        ]]]);
        $id = data_get($result, 'created.new1.id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Mail server did not create the domain. Check its management logs.');
        }
        $domain->update(['provider_domain_id' => $id]);

        return $domain->fresh();
    }

    public function provisionMailbox(TenantMailbox $mailbox): TenantMailbox
    {
        $domain = $this->provisionDomain($mailbox->domain);
        if ($mailbox->provider_account_id) {
            return $mailbox;
        }
        [$localPart] = explode('@', $mailbox->address, 2);
        $password = bin2hex(random_bytes(32));
        $result = $this->call('x:Account/set', ['create' => ['new1' => [
            '@type' => 'User', 'name' => $localPart, 'domainId' => $domain->provider_domain_id,
            'credentials' => [['@type' => 'Password', 'secret' => $password]],
            'aliases' => (object) [], 'memberGroupIds' => (object) [],
            'roles' => ['@type' => 'User'], 'permissions' => ['@type' => 'Inherit'],
            'quotas' => (object) [], 'encryptionAtRest' => ['@type' => 'Disabled'],
        ]]]);
        $id = data_get($result, 'created.new1.id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Mail server did not create the account. Check its management logs.');
        }
        $mailbox->update(['provider_account_id' => $id, 'provider_credentials' => ['password' => $password]]);

        return $mailbox->fresh();
    }

    private function enabled(TenantMailDomain $domain): void
    {
        if ($domain->transport !== 'direct') {
            throw new RuntimeException('This domain uses a different transport.');
        }
        if (! config('mailbox.direct_enabled')) {
            throw new RuntimeException('Direct mail provisioning is disabled until the server is ready.');
        }
    }

    private function call(string $method, array $arguments): array
    {
        $url = rtrim((string) config('mailbox.management_url'), '/');
        $key = (string) config('mailbox.management_api_key');
        if (! str_starts_with($url, 'https://') || $key === '') {
            throw new RuntimeException('Mail management endpoint is not configured.');
        }
        $response = Http::withToken($key)->acceptJson()->timeout(20)->post($url.'/api', [
            'using' => ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'],
            'methodCalls' => [[$method, $arguments, 'c1']],
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Mail server management request failed (HTTP '.$response->status().').');
        }
        $methodResponse = data_get($response->json(), 'methodResponses.0');
        if (! is_array($methodResponse) || ($methodResponse[0] ?? null) !== $method) {
            throw new RuntimeException('Mail server returned an invalid management response.');
        }
        $result = $methodResponse[1] ?? null;
        if (! is_array($result) || ! empty($result['notCreated'])) {
            throw new RuntimeException('Mail server rejected the new domain or account.');
        }

        return $result;
    }
}
