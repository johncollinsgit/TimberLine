<?php

namespace App\Services\Integrations\Instagram;

use App\Models\IntegrationConnection;
use App\Services\Marketing\MessagingConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InstagramWebhookService
{
    public function __construct(protected MessagingConversationService $conversationService)
    {
    }

    public function verifies(Request $request): bool
    {
        $token = (string) config('services.instagram.webhook_verify_token');
        // PHP normalizes dots in query-string keys to underscores. Accept the
        // normalized form Meta reaches in Laravel as well as the literal key.
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode'));
        $verifyToken = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token'));

        return $token !== ''
            && $mode === 'subscribe'
            && hash_equals($token, $verifyToken);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function assertValidSignature(Request $request, array $payload): void
    {
        $secret = (string) config('services.instagram.client_secret');
        $signature = trim((string) $request->header('X-Hub-Signature-256'));
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            throw ValidationException::withMessages([
                'signature' => ['Instagram webhook signature is invalid.'],
            ]);
        }

        abort_unless(data_get($payload, 'object') === 'instagram', 422, 'Unsupported webhook object.');
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function ingest(array $payload): int
    {
        $processed = 0;
        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ((array) data_get($entry, 'messaging', []) as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $accountId = trim((string) (data_get($event, 'recipient.id') ?: data_get($entry, 'id')));
                $senderId = trim((string) data_get($event, 'sender.id'));
                $messageId = trim((string) data_get($event, 'message.mid'));
                $body = trim((string) data_get($event, 'message.text'));
                if ($accountId === '' || $senderId === '' || $messageId === '') {
                    continue;
                }

                $connection = $this->connectionForAccount($accountId);
                if (! $connection instanceof IntegrationConnection || $senderId === $accountId) {
                    continue;
                }
                if ($body === '' && filled(data_get($event, 'message.attachments'))) {
                    $body = '[Instagram attachment]';
                }
                if ($body === '') {
                    continue;
                }

                $timestamp = $this->timestamp(data_get($event, 'timestamp'));
                $conversation = $this->conversationService->findOrCreateInstagramConversation(
                    tenantId: (int) $connection->tenant_id,
                    storeKey: null,
                    profile: null,
                    instagramAccountId: $accountId,
                    instagramSenderId: $senderId,
                    context: [
                        'source_type' => 'instagram',
                        'source_context' => [
                            'instagram_connection_id' => (int) $connection->id,
                            'instagram_sender_id' => $senderId,
                            'instagram_username' => data_get($connection->metadata, 'username'),
                        ],
                    ],
                );
                $this->conversationService->appendMessage($conversation, [
                    'channel' => 'instagram',
                    'direction' => 'inbound',
                    'provider' => 'instagram',
                    'provider_message_id' => $messageId,
                    'body' => $body,
                    'normalized_body' => $body,
                    'from_identity' => $senderId,
                    'to_identity' => $accountId,
                    'received_at' => $timestamp,
                    'delivery_status' => 'received',
                    'message_type' => 'normal',
                    'raw_payload' => $event,
                    'metadata' => ['instagram_connection_id' => (int) $connection->id],
                ]);
                $processed++;
            }
        }

        return $processed;
    }

    protected function connectionForAccount(string $accountId): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->forAllTenants()
            ->where('provider', 'instagram')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->where('external_account_id', hash_hmac('sha256', $accountId, (string) config('app.key')))
            ->first();
    }

    protected function timestamp(mixed $value): Carbon
    {
        $milliseconds = is_numeric($value) ? (int) $value : 0;

        return $milliseconds > 0
            ? Carbon::createFromTimestampMs($milliseconds)
            : now();
    }
}
