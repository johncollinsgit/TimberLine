<?php

namespace App\Services\Marketing\Desk;

use App\Models\MessagingConversation;
use App\Models\User;
use App\Services\Marketing\MessagingConversationService;

class FacebookMessagingService
{
    public function sendReply(MessagingConversation $conversation, string $body, ?User $actor): void
    {
        abort_unless($conversation->channel === 'facebook' && $conversation->last_inbound_at?->gt(now()->subHours(24)), 422, 'Messenger replies require a customer message within 24 hours.');
        $client = app(MetaMarketingClient::class);
        $connection = $client->connection($conversation->tenant_id, (int) data_get($conversation->source_context, 'facebook_connection_id'), 'facebook', 'reply');
        $sender = (string) data_get($conversation->source_context, 'facebook_sender_id');
        abort_if($sender === '', 422);
        $result = $client->request($connection, 'POST', $client->account($connection).'/messages', ['recipient' => ['id' => $sender], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $body]]);
        abort_if(empty($result['message_id']), 502);
        app(MessagingConversationService::class)->appendMessage($conversation, ['channel' => 'facebook', 'direction' => 'outbound', 'provider' => 'facebook', 'provider_message_id' => $result['message_id'], 'body' => $body, 'sent_at' => now(), 'delivery_status' => 'accepted', 'created_by' => $actor?->id, 'metadata' => ['facebook_connection_id' => $connection->id]]);
    }
}
