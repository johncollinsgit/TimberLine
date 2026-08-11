<?php

namespace App\Services\Integrations\Instagram;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class InstagramApiClient
{
    public function __construct(
        protected IntegrationConnection $connection,
        protected string $apiBase,
        protected string $apiVersion,
    ) {
    }

    /**
     * Send a reply to an Instagram sender. This client is intentionally used by
     * the response inbox only; it is not a campaign or prospecting sender.
     *
     * @return array<string,mixed>
     */
    public function sendTextMessage(string $recipientId, string $body): array
    {
        $recipientId = trim($recipientId);
        if ($recipientId === '') {
            throw ValidationException::withMessages([
                'body' => ['Instagram reply is missing the customer conversation identity.'],
            ]);
        }

        $response = $this->request()
            ->post($this->endpoint($this->accountId().'/messages'), [
                'recipient_id' => $recipientId,
                'message' => ['text' => $body],
            ])
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /** @return array<string,mixed> */
    public function profile(): array
    {
        $response = $this->request()
            ->get($this->endpoint('me'), ['fields' => 'user_id,id,username'])
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    protected function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(15)
            ->withToken((string) $this->connection->access_token);
    }

    protected function accountId(): string
    {
        $accountId = trim((string) $this->connection->external_account_secret);
        if ($accountId === '') {
            throw ValidationException::withMessages([
                'body' => ['The Instagram connection is incomplete. Reconnect the account before replying.'],
            ]);
        }

        return $accountId;
    }

    protected function endpoint(string $path): string
    {
        $prefix = trim($this->apiVersion, '/');

        return rtrim($this->apiBase, '/').($prefix !== '' ? '/'.$prefix : '').'/'.ltrim($path, '/');
    }
}
