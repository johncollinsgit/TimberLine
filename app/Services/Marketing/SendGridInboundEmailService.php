<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendGridInboundEmailService
{
    public function __construct(
        protected MessagingConversationService $conversationService,
        protected MessagingContactChannelStateService $channelStateService,
        protected MessagingEmailReplyAddressService $replyAddressService,
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,matched:bool,status:string,tenant_id:?int,conversation_id:?int,message_type:?string}
     */
    public function handleInbound(array $payload): array
    {
        $fromEmail = $this->extractEmailAddress($payload['from'] ?? null);
        $recipientEmails = $this->extractRecipientEmails($payload);
        $subject = $this->nullableString($payload['subject'] ?? null);
        $textBody = $this->safeTextBody($payload);
        $headers = $this->parseHeaderString($payload['headers'] ?? null);
        $providerMessageId = $this->headerValue($headers, 'Message-ID')
            ?? $this->headerValue($headers, 'Message-Id')
            ?? $this->nullableString($payload['Message-Id'] ?? null);
        $occurredAt = now()->toImmutable();

        $replyAddressMatch = collect($recipientEmails)
            ->map(fn (string $email): ?array => $this->replyAddressService->parseReplyAddress($email))
            ->filter()
            ->first();

        $matchedDelivery = null;
        $tenantId = null;
        $storeKey = null;
        $profile = null;
        $context = [];

        if (is_array($replyAddressMatch)) {
            $matchedDelivery = MarketingEmailDelivery::query()
                ->forTenantId((int) $replyAddressMatch['tenant_id'])
                ->find((int) $replyAddressMatch['delivery_id']);
        }

        if (! $matchedDelivery instanceof MarketingEmailDelivery) {
            $matchedDelivery = $this->resolveDeliveryFromHeaders($headers, $fromEmail);
        }

        if ($matchedDelivery instanceof MarketingEmailDelivery) {
            $tenantId = (int) $matchedDelivery->tenant_id;
            $storeKey = $this->nullableString($matchedDelivery->store_key);
            $profile = $matchedDelivery->profile;
            $context = [
                'source_type' => 'marketing_email_delivery',
                'source_id' => (int) $matchedDelivery->id,
                'source_context' => [
                    'delivery_id' => (int) $matchedDelivery->id,
                    'campaign_recipient_id' => $matchedDelivery->marketing_campaign_recipient_id,
                    'source_label' => $matchedDelivery->source_label,
                ],
            ];
        } else {
            $profile = $this->resolveUniqueProfile($fromEmail);
            if ($profile instanceof MarketingProfile) {
                $tenantId = (int) $profile->tenant_id;
            }
        }

        if ($tenantId === null || $fromEmail === null) {
            Log::warning('messaging inbound email unmatched', [
                'from' => $fromEmail,
                'to' => $recipientEmails,
                'subject' => $subject,
                'payload_keys' => array_keys($payload),
            ]);

            return [
                'ok' => true,
                'matched' => false,
                'status' => 'unmatched_tenant',
                'tenant_id' => null,
                'conversation_id' => null,
                'message_type' => null,
            ];
        }

        $conversation = $this->conversationService->findOrCreateEmailConversation(
            tenantId: $tenantId,
            storeKey: $storeKey,
            profile: $profile,
            email: $fromEmail,
            subject: $subject,
            context: $context
        );

        if ($matchedDelivery instanceof MarketingEmailDelivery) {
            $this->conversationService->ensureEmailDeliverySeed($conversation, $matchedDelivery);
        }

        $classification = $this->classify($subject, $textBody, $headers);
        $message = $this->conversationService->appendMessage($conversation, [
            'marketing_profile_id' => $profile?->id,
            'channel' => 'email',
            'direction' => 'inbound',
            'provider' => 'sendgrid',
            'provider_message_id' => $providerMessageId,
            'body' => $textBody,
            'normalized_body' => $textBody !== '' ? $textBody : null,
            'subject' => $subject,
            'from_identity' => $fromEmail,
            'to_identity' => $recipientEmails[0] ?? null,
            'received_at' => $occurredAt,
            'message_type' => $classification['message_type'],
            'raw_payload' => $payload,
            'metadata' => [
                'headers' => $headers,
                'reply_references' => $classification['references'],
            ],
        ]);

        $status = 'received';
        if ($message->wasRecentlyCreated) {
            if ($classification['message_type'] === 'unsubscribe') {
                $this->channelStateService->markEmailStatus(
                    tenantId: $tenantId,
                    profile: $profile,
                    email: $fromEmail,
                    status: 'unsubscribed',
                    reason: 'inbound_unsubscribe',
                    providerSource: 'sendgrid_inbound',
                    metadata: [
                        'provider_message_id' => $providerMessageId,
                    ],
                    occurredAt: $occurredAt
                );
                $conversation->forceFill([
                    'status' => 'opted_out',
                ])->save();
                $this->conversationService->appendSystemNote(
                    $conversation,
                    'Email unsubscribe recorded from inbound reply.',
                    [
                        'event' => 'email_unsubscribe',
                        'provider_message_id' => $providerMessageId,
                    ],
                    'email-unsubscribe-' . ($providerMessageId ?? sha1($fromEmail . ($subject ?? '')))
                );
                $status = 'opted_out';
            } elseif (! in_array((string) $conversation->status, ['opted_out', 'archived'], true)) {
                $conversation->forceFill([
                    'status' => 'open',
                ])->save();
            }
        } else {
            $status = 'duplicate';
        }

        return [
            'ok' => true,
            'matched' => true,
            'status' => $status,
            'tenant_id' => $tenantId,
            'conversation_id' => (int) $conversation->id,
            'message_type' => $classification['message_type'],
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{message_type:string,references:array<int,string>}
     */
    protected function classify(?string $subject, string $body, array $headers): array
    {
        $normalizedSubject = strtolower(trim((string) $subject));
        $normalizedBody = strtolower(trim($body));
        $autoSubmitted = strtolower(trim((string) ($headers['Auto-Submitted'] ?? '')));
        $precedence = strtolower(trim((string) ($headers['Precedence'] ?? '')));
        $references = array_values(array_filter(array_unique(array_merge(
            $this->splitMessageIds($headers['In-Reply-To'] ?? null),
            $this->splitMessageIds($headers['References'] ?? null),
        ))));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return ['message_type' => 'auto_reply', 'references' => $references];
        }
        if (in_array($precedence, ['bulk', 'auto_reply', 'auto-reply'], true)) {
            return ['message_type' => 'auto_reply', 'references' => $references];
        }
        if (
            str_contains($normalizedSubject, 'out of office')
            || str_contains($normalizedSubject, 'automatic reply')
            || str_contains($normalizedSubject, 'auto reply')
        ) {
            return ['message_type' => 'auto_reply', 'references' => $references];
        }
        if (
            preg_match('/\bunsubscribe\b|\bremove me\b|\bopt me out\b/', $normalizedSubject)
            || preg_match('/\bunsubscribe\b|\bremove me\b|\bopt me out\b/', $normalizedBody)
        ) {
            return ['message_type' => 'unsubscribe', 'references' => $references];
        }

        return ['message_type' => 'normal', 'references' => $references];
    }

    /**
     * @param array<string,string> $headers
     */
    protected function resolveDeliveryFromHeaders(array $headers, ?string $fromEmail): ?MarketingEmailDelivery
    {
        $messageIds = array_values(array_filter(array_unique(array_merge(
            $this->splitMessageIds($headers['In-Reply-To'] ?? null),
            $this->splitMessageIds($headers['References'] ?? null),
        ))));

        foreach ($messageIds as $messageId) {
            $delivery = MarketingEmailDelivery::query()
                ->where('provider_message_id', $messageId)
                ->orWhere('sendgrid_message_id', $messageId)
                ->latest('id')
                ->first();
            if ($delivery instanceof MarketingEmailDelivery) {
                return $delivery;
            }
        }

        if ($fromEmail !== null) {
            $query = MarketingEmailDelivery::query()
                ->where('email', $fromEmail)
                ->orderByDesc('sent_at')
                ->orderByDesc('id');

            return $query->first();
        }

        return null;
    }

    protected function resolveUniqueProfile(?string $email): ?MarketingProfile
    {
        $normalizedEmail = $this->identityNormalizer->normalizeEmail($email);
        if ($normalizedEmail === null) {
            return null;
        }

        $profiles = MarketingProfile::query()
            ->where('normalized_email', $normalizedEmail)
            ->limit(2)
            ->get();

        return $profiles->count() === 1 ? $profiles->first() : null;
    }

    /**
     * @return array<int,string>
     */
    protected function extractRecipientEmails(array $payload): array
    {
        $candidates = [];
        foreach (['to', 'recipient', 'envelope'] as $key) {
            $value = $payload[$key] ?? null;
            if ($key === 'envelope' && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? ($decoded['to'] ?? null) : null;
            }

            if (is_array($value)) {
                $candidates = array_merge($candidates, array_map('strval', $value));
                continue;
            }

            if (is_string($value) && $value !== '') {
                $candidates = array_merge($candidates, preg_split('/[,;]/', $value) ?: []);
            }
        }

        return array_values(array_filter(array_map(function (string $candidate): ?string {
            return $this->extractEmailAddress($candidate);
        }, $candidates)));
    }

    protected function extractEmailAddress(mixed $value): ?string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $string, $matches)) {
            $string = trim((string) ($matches[1] ?? $string));
        }

        return $this->identityNormalizer->normalizeEmail($string);
    }

    protected function safeTextBody(array $payload): string
    {
        $text = $this->nullableString($payload['text'] ?? null);
        if ($text !== null) {
            return $text;
        }

        $html = $this->nullableString($payload['html'] ?? null);
        if ($html === null) {
            return '';
        }

        $text = str_ireplace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string,string>
     */
    protected function parseHeaderString(mixed $value): array
    {
        $headers = [];
        $raw = trim((string) $value);
        if ($raw === '') {
            return $headers;
        }

        foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $headerValue] = explode(':', $line, 2);
            $headers[trim($name)] = trim($headerValue);
        }

        return $headers;
    }

    protected function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $headerValue) {
            if (strcasecmp($headerName, $name) === 0) {
                return $this->nullableString($headerValue);
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    protected function splitMessageIds(mixed $value): array
    {
        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        preg_match_all('/<[^>]+>/', $string, $matches);
        $ids = $matches[0] ?? [];
        if ($ids !== []) {
            return array_values(array_filter(array_map(fn (string $id): ?string => $this->nullableString($id), $ids)));
        }

        return array_values(array_filter(array_map(
            fn (string $entry): ?string => $this->nullableString($entry),
            preg_split('/\s+/', $string) ?: []
        )));
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
