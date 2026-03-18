<?php

namespace App\Services\Marketing;

use App\Models\MarketingSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TwilioSenderConfigService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $senders = $this->normalizedSenders();
        $defaultKey = $this->resolveDefaultSenderKey($senders);

        return array_values(array_map(
            fn (array $sender): array => [
                ...$sender,
                'is_default' => $sender['key'] === $defaultKey,
            ],
            $senders
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function sendable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $sender): bool => (bool) ($sender['sendable'] ?? false)
        ));
    }

    public function defaultSender(): ?array
    {
        $senders = $this->sendable();
        foreach ($senders as $sender) {
            if ((bool) ($sender['is_default'] ?? false)) {
                return $sender;
            }
        }

        return $senders[0] ?? null;
    }

    /**
     * @return array{sender:?array,error:?array{code:string,message:string}}
     */
    public function resolveForSend(?string $requestedKey = null): array
    {
        $requestedKey = $this->nullableString($requestedKey);

        if ($requestedKey !== null) {
            $sender = collect($this->all())
                ->first(fn (array $row): bool => $row['key'] === $requestedKey);

            if (! $sender) {
                return [
                    'sender' => null,
                    'error' => [
                        'code' => 'unknown_sender',
                        'message' => 'The selected SMS sender is not configured.',
                    ],
                ];
            }

            if (! (bool) ($sender['enabled'] ?? false)) {
                return [
                    'sender' => $sender,
                    'error' => [
                        'code' => 'sender_disabled',
                        'message' => 'The selected SMS sender is not enabled yet.',
                    ],
                ];
            }

            if (! (bool) ($sender['sendable'] ?? false)) {
                return [
                    'sender' => $sender,
                    'error' => [
                        'code' => 'sender_not_ready',
                        'message' => 'The selected SMS sender does not have a live Twilio send identity yet.',
                    ],
                ];
            }

            return [
                'sender' => $sender,
                'error' => null,
            ];
        }

        $default = $this->defaultSender();
        if ($default) {
            return [
                'sender' => $default,
                'error' => null,
            ];
        }

        return [
            'sender' => null,
            'error' => [
                'code' => 'missing_sender_identity',
                'message' => 'Configure at least one enabled Twilio sender before sending SMS.',
            ],
        ];
    }

    public function smsSupported(): bool
    {
        return (bool) config('marketing.sms.enabled')
            && (bool) config('marketing.twilio.enabled')
            && $this->defaultSender() !== null;
    }

    public function updateDefaultSender(string $key): void
    {
        $key = trim($key);
        if ($key === '' || ! Schema::hasTable('marketing_settings')) {
            return;
        }

        MarketingSetting::query()->updateOrCreate(
            ['key' => 'sms_default_sender'],
            ['value' => ['key' => $key]]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function normalizedSenders(): array
    {
        $configured = config('marketing.twilio.senders', []);
        $senders = [];

        if (is_array($configured)) {
            foreach (array_values($configured) as $index => $sender) {
                if (! is_array($sender)) {
                    continue;
                }

                $normalized = $this->normalizeSender($sender, $index);
                if ($normalized !== null) {
                    $senders[$normalized['key']] = $normalized;
                }
            }
        }

        $legacy = $this->legacySender();
        if ($legacy !== null) {
            $legacyIdentity = $legacy['from_identifier'] ?? null;
            $hasLegacyIdentity = collect($senders)->contains(
                static fn (array $sender): bool => ($sender['from_identifier'] ?? null) === $legacyIdentity
            );

            if (! $hasLegacyIdentity) {
                $senders[$legacy['key']] = $legacy;
            }
        }

        return array_values($senders);
    }

    /**
     * @param array<string,mixed> $sender
     * @return array<string,mixed>|null
     */
    protected function normalizeSender(array $sender, int $index): ?array
    {
        $key = $this->senderKey($sender, $index);
        if ($key === null) {
            return null;
        }

        $label = $this->nullableString($sender['label'] ?? null)
            ?? Str::headline(str_replace('_', ' ', $key));

        $type = $this->nullableString($sender['type'] ?? null) ?? 'custom';
        $status = strtolower($this->nullableString($sender['status'] ?? null) ?? 'active');
        $messagingServiceSid = $this->nullableString($sender['messaging_service_sid'] ?? null);
        $fromNumber = $this->nullableString($sender['from_number'] ?? null);
        $phoneNumberSid = $this->nullableString($sender['phone_number_sid'] ?? null);

        $enabled = array_key_exists('enabled', $sender)
            ? (bool) $sender['enabled']
            : ! in_array($status, ['pending', 'disabled', 'inactive'], true);

        $sendable = $enabled && ($messagingServiceSid !== null || $fromNumber !== null);

        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'status' => $status,
            'enabled' => $enabled,
            'sendable' => $sendable,
            'messaging_service_sid' => $messagingServiceSid,
            'from_number' => $fromNumber,
            'phone_number_sid' => $phoneNumberSid,
            'default' => (bool) ($sender['default'] ?? false),
            'from_identifier' => $messagingServiceSid ?: $fromNumber,
            'identity_label' => $messagingServiceSid
                ? 'Messaging Service ' . $messagingServiceSid
                : ($fromNumber ?? 'Not configured'),
        ];
    }

    protected function legacySender(): ?array
    {
        $messagingServiceSid = $this->nullableString(config('marketing.twilio.messaging_service_sid'));
        $fromNumber = $this->nullableString(config('marketing.twilio.from_number'));

        if ($messagingServiceSid === null && $fromNumber === null) {
            return null;
        }

        return [
            'key' => 'legacy_default',
            'label' => 'Primary SMS Number',
            'type' => 'legacy',
            'status' => 'active',
            'enabled' => true,
            'sendable' => true,
            'messaging_service_sid' => $messagingServiceSid,
            'from_number' => $fromNumber,
            'phone_number_sid' => null,
            'default' => true,
            'from_identifier' => $messagingServiceSid ?: $fromNumber,
            'identity_label' => $messagingServiceSid
                ? 'Messaging Service ' . $messagingServiceSid
                : ($fromNumber ?? 'Not configured'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $senders
     */
    protected function resolveDefaultSenderKey(array $senders): ?string
    {
        $override = $this->defaultSenderOverride();
        if ($override !== null) {
            foreach ($senders as $sender) {
                if ($sender['key'] === $override && (bool) ($sender['sendable'] ?? false)) {
                    return $sender['key'];
                }
            }
        }

        foreach ($senders as $sender) {
            if ((bool) ($sender['default'] ?? false) && (bool) ($sender['sendable'] ?? false)) {
                return $sender['key'];
            }
        }

        foreach ($senders as $sender) {
            if ((bool) ($sender['sendable'] ?? false)) {
                return $sender['key'];
            }
        }

        return $senders[0]['key'] ?? null;
    }

    protected function defaultSenderOverride(): ?string
    {
        if (Schema::hasTable('marketing_settings')) {
            $setting = MarketingSetting::query()
                ->where('key', 'sms_default_sender')
                ->first();

            $saved = $this->nullableString(data_get($setting?->value, 'key'));
            if ($saved !== null) {
                return $saved;
            }
        }

        return $this->nullableString(config('marketing.twilio.default_sender_key'));
    }

    /**
     * @param array<string,mixed> $sender
     */
    protected function senderKey(array $sender, int $index): ?string
    {
        $candidate = $this->nullableString($sender['key'] ?? null)
            ?? $this->nullableString($sender['id'] ?? null)
            ?? $this->nullableString($sender['type'] ?? null)
            ?? $this->nullableString($sender['label'] ?? null);

        if ($candidate === null) {
            $candidate = 'sender_' . ($index + 1);
        }

        $slug = Str::of($candidate)->lower()->snake()->value();

        return $slug !== '' ? $slug : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
