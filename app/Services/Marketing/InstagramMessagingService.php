<?php

namespace App\Services\Marketing;

use App\Models\IntegrationConnection;
use App\Models\MessagingConversation;
use App\Models\User;
use App\Services\Integrations\Instagram\InstagramConnector;
use Illuminate\Validation\ValidationException;

class InstagramMessagingService
{
    public function __construct(
        protected InstagramConnector $connector,
        protected MessagingConversationService $conversationService,
    ) {
    }

    public function sendReply(MessagingConversation $conversation, string $body, ?User $actor = null): void
    {
        abort_unless($conversation->channel === 'instagram', 422, 'This is not an Instagram conversation.');

        $connectionId = (int) data_get($conversation->source_context, 'instagram_connection_id');
        $recipientId = trim((string) data_get($conversation->source_context, 'instagram_sender_id'));
        $lastInboundAt = $conversation->last_inbound_at;
        $replyWindowHours = (int) config('services.instagram.reply_window_hours', 24);

        if ($connectionId <= 0 || $recipientId === '') {
            throw ValidationException::withMessages([
                'body' => ['Instagram reply is unavailable because the original account or sender cannot be identified.'],
            ]);
        }

        if ($lastInboundAt === null || $lastInboundAt->isBefore(now()->subHours($replyWindowHours))) {
            throw ValidationException::withMessages([
                'body' => ['Instagram replies are limited to '.$replyWindowHours.' hours after the customer’s most recent inbound message.'],
            ]);
        }

        $connection = IntegrationConnection::query()
            ->forTenantId((int) $conversation->tenant_id)
            ->where('provider', 'instagram')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->find($connectionId);
        if (! $connection instanceof IntegrationConnection) {
            throw ValidationException::withMessages([
                'body' => ['The Instagram account is disconnected. Reconnect it before replying.'],
            ]);
        }

        $result = $this->connector->client($connection)->sendTextMessage($recipientId, $body);
        $providerMessageId = trim((string) ($result['message_id'] ?? $result['id'] ?? data_get($result, 'data.0.id') ?? ''));

        $conversation->forceFill(['status' => 'open'])->save();
        $this->conversationService->appendMessage($conversation, [
            'channel' => 'instagram',
            'direction' => 'outbound',
            'provider' => 'instagram',
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'body' => $body,
            'normalized_body' => $body,
            'from_identity' => data_get($connection->metadata, 'username') ?: 'Instagram',
            'to_identity' => $recipientId,
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'message_type' => 'normal',
            'created_by' => $actor?->id,
            'raw_payload' => $result,
            'metadata' => [
                'instagram_connection_id' => (int) $connection->id,
                'source_label' => 'responses_inbox_reply',
            ],
        ]);
    }
}
