<?php

namespace App\Services\Mailbox;

use App\Models\TenantMailbox;
use App\Models\TenantMailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StalwartMailboxSyncService
{
    public function sync(TenantMailbox $mailbox): int
    {
        if ($mailbox->domain->transport !== 'direct' || ! $mailbox->provider_account_id) {
            return 0;
        }
        $password = (string) data_get($mailbox->provider_credentials, 'password');
        $base = rtrim((string) config('mailbox.management_url'), '/');
        if (! str_starts_with($base, 'https://') || $password === '') {
            throw new RuntimeException('Mailbox sync is not configured.');
        }
        $sessionResponse = Http::withBasicAuth($mailbox->address, $password)->acceptJson()->timeout(20)->get($base.'/.well-known/jmap');
        if ($sessionResponse->failed()) {
            throw new RuntimeException('Mailbox JMAP sign-in failed.');
        }
        $session = $sessionResponse->json();
        $accountId = data_get($session, 'primaryAccounts.urn:ietf:params:jmap:mail');
        $apiUrl = (string) data_get($session, 'apiUrl');
        if (! is_string($accountId) || $accountId === '' || ! $this->sameOrigin($base, $apiUrl)) {
            throw new RuntimeException('Mailbox JMAP session is invalid.');
        }
        $added = 0;
        $position = 0;
        $limit = 100;
        $after = $mailbox->last_synced_at?->copy()->subMinutes(5)->toIso8601String();
        do {
            $filter = $after ? ['after' => $after] : [];
            $query = $this->call($apiUrl, $mailbox->address, $password, [
                ['Email/query', ['accountId' => $accountId, 'filter' => $filter, 'sort' => [['property' => 'receivedAt', 'isAscending' => false]], 'position' => $position, 'limit' => $limit], 'q1'],
            ]);
            $ids = data_get($query, 'methodResponses.0.1.ids', []);
            if (! is_array($ids)) {
                throw new RuntimeException('Mailbox query failed.');
            }
            if ($ids) {
                $details = $this->call($apiUrl, $mailbox->address, $password, [[
                    'Email/get', ['accountId' => $accountId, 'ids' => $ids, 'properties' => ['id', 'from', 'to', 'subject', 'textBody', 'bodyValues', 'receivedAt', 'messageId'], 'fetchTextBodyValues' => true, 'maxBodyValueBytes' => 1_000_000], 'g1',
                ]]);
                $messages = data_get($details, 'methodResponses.0.1.list', []);
                if (! is_array($messages)) {
                    throw new RuntimeException('Mailbox message fetch failed.');
                }
                foreach ($messages as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $providerId = (string) ($item['id'] ?? '');
                    $from = strtolower((string) data_get($item, 'from.0.email', ''));
                    if ($providerId === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL) || $from === strtolower($mailbox->address)) {
                        continue;
                    }
                    $subject = mb_substr((string) ($item['subject'] ?? ''), 0, 255);
                    $body = collect((array) ($item['textBody'] ?? []))->map(fn ($part) => (string) data_get($item, 'bodyValues.'.(string) ($part['partId'] ?? '').'.value', ''))->implode("\n");
                    $occurred = CarbonImmutable::parse((string) ($item['receivedAt'] ?? now()->toIso8601String()));
                    $record = TenantMailMessage::query()->forTenantId($mailbox->tenant_id)->firstOrCreate(
                        ['tenant_mailbox_id' => $mailbox->id, 'direction' => 'inbound', 'provider_message_id' => 'jmap:'.$providerId],
                        ['tenant_id' => $mailbox->tenant_id, 'folder' => 'inbox', 'from_address' => $from, 'to_address' => $mailbox->address, 'subject' => $subject, 'text_body' => mb_substr($body, 0, 1_000_000), 'thread_key' => TenantMailboxService::threadKey($from, $subject), 'delivery_status' => 'received', 'occurred_at' => $occurred]
                    );
                    if ($record->wasRecentlyCreated) {
                        $added++;
                    }
                }
            }
            $position += count($ids);
            if ($position >= 10_000 && count($ids) === $limit) {
                throw new RuntimeException('Mailbox sync limit reached; operator review is required.');
            }
        } while (count($ids) === $limit);
        $mailbox->update(['last_synced_at' => now()]);

        return $added;
    }

    private function call(string $url, string $username, string $password, array $calls): array
    {
        $response = Http::withBasicAuth($username, $password)->acceptJson()->timeout(30)->post($url, [
            'using' => ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:mail'], 'methodCalls' => $calls,
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Mailbox JMAP request failed (HTTP '.$response->status().').');
        }
        $body = $response->json();
        if (! is_array($body) || data_get($body, 'methodResponses.0.0') === 'error') {
            throw new RuntimeException('Mailbox JMAP operation failed.');
        }

        return $body;
    }

    private function sameOrigin(string $base, string $url): bool
    {
        return parse_url($base, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($base, PHP_URL_HOST) === parse_url($url, PHP_URL_HOST);
    }
}
