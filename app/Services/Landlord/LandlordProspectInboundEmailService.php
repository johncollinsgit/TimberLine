<?php

namespace App\Services\Landlord;

use App\Models\LandlordProspect;
use App\Services\Operations\OperatorAlertService;
use Illuminate\Support\Str;

class LandlordProspectInboundEmailService
{
    public function __construct(private OperatorAlertService $operatorAlerts) {}

    /**
     * Store a reply from a known prospect when it arrives through the SendGrid inbound webhook.
     *
     * @param  array<string,mixed>  $payload
     * @return array{handled:bool,status:string,prospect_id:?int}
     */
    public function capture(array $payload): array
    {
        $fromAddress = $this->emailAddress($payload['from'] ?? null);
        if ($fromAddress === null) {
            return ['handled' => false, 'status' => 'unmatched', 'prospect_id' => null];
        }

        $prospect = LandlordProspect::query()
            ->whereRaw('lower(email) = ?', [$fromAddress])
            ->first();
        if (! $prospect instanceof LandlordProspect) {
            return ['handled' => false, 'status' => 'unmatched', 'prospect_id' => null];
        }

        $headers = $this->headers($payload['headers'] ?? null);
        $externalMessageId = $this->nullableString($headers['message-id'] ?? $payload['Message-Id'] ?? $payload['message_id'] ?? null);
        if ($externalMessageId !== null && $prospect->communications()->where('external_message_id', $externalMessageId)->exists()) {
            return ['handled' => true, 'status' => 'duplicate', 'prospect_id' => (int) $prospect->id];
        }

        $communication = $prospect->communications()->create([
            'direction' => 'inbound',
            'channel' => 'email',
            'status' => 'received',
            'subject' => $this->nullableString($payload['subject'] ?? null),
            'body' => $this->body($payload),
            'from_address' => $fromAddress,
            'to_address' => $this->emailAddress($payload['to'] ?? null),
            'external_message_id' => $externalMessageId,
            'occurred_at' => now(),
        ]);

        if ($prospect->status !== 'converted') {
            $prospect->forceFill([
                'status' => 'replied',
                'responded_at' => $communication->occurred_at,
            ])->save();
        }

        $this->operatorAlerts->notify(
            'landlord_prospect.reply_received',
            "Everbranch: {$prospect->business_name} replied. Open the prospect workspace to respond.",
            [
                'target_type' => 'landlord_prospect_communication',
                'target_id' => (int) $communication->id,
                'prospect_id' => (int) $prospect->id,
                'prospect_email' => (string) $prospect->email,
                'dedupe_key' => 'landlord-prospect-reply:'.$communication->id,
            ]
        );

        return ['handled' => true, 'status' => 'received', 'prospect_id' => (int) $prospect->id];
    }

    private function emailAddress(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            $value = trim((string) $matches[1]);
        }

        $value = Str::lower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /** @return array<string,string> */
    private function headers(mixed $value): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', (string) $value) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $headerValue] = explode(':', $line, 2);
            $headers[Str::lower(trim($name))] = trim($headerValue);
        }

        return $headers;
    }

    /** @param array<string,mixed> $payload */
    private function body(array $payload): string
    {
        $text = trim((string) ($payload['text'] ?? $payload['body-plain'] ?? ''));
        if ($text !== '') {
            return Str::limit($text, 20000, '');
        }

        return Str::limit(trim(strip_tags((string) ($payload['html'] ?? ''))), 20000, '');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 255, '') : null;
    }
}
