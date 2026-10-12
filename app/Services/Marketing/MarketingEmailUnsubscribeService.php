<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MessagingContactChannelState;
use App\Models\Tenant;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\Crypt;

class MarketingEmailUnsubscribeService
{
    public function __construct(protected MarketingIdentityNormalizer $normalizer) {}

    public function url(int $tenantId, string $email): string
    {
        $normalized = $this->normalizer->normalizeEmail($email);
        if ($tenantId < 1 || $normalized === null) {
            throw new \InvalidArgumentException('A tenant and valid email are required.');
        }
        $token = Crypt::encryptString(json_encode(['purpose' => 'marketing_unsubscribe', 'tenant_id' => $tenantId, 'email' => $normalized], JSON_THROW_ON_ERROR));

        return rtrim((string) config('app.url'), '/').'/email/unsubscribe?token='.rawurlencode($token);
    }

    /** @return array{tenant_id:int,email:string}|null */
    public function recipient(string $token): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
            $email = $this->normalizer->normalizeEmail($data['email'] ?? null);
            $tenantId = (int) ($data['tenant_id'] ?? 0);
            if (($data['purpose'] ?? '') !== 'marketing_unsubscribe' || $email === null || $tenantId < 1 || ! Tenant::query()->whereKey($tenantId)->exists()) {
                return null;
            }

            return ['tenant_id' => $tenantId, 'email' => $email];
        } catch (\Throwable) {
            return null;
        }
    }

    public function suppressed(int $tenantId, string $email): bool
    {
        $email = $this->normalizer->normalizeEmail($email);
        if ($email === null) {
            return true;
        }
        $states = MessagingContactChannelState::query()->forTenantId($tenantId)->where('email', $email);
        if ((clone $states)->whereIn('email_status', ['unsubscribed', 'suppressed', 'bounced'])->exists()) {
            return true;
        }

        return MarketingProfile::query()->forTenantId($tenantId)
            ->where(function ($query) use ($email): void {
                $query->where('normalized_email', $email)->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
            })->whereNotNull('email_opted_out_at')->where('accepts_email_marketing', false)->exists();
    }

    /** @param array<string,mixed> $options */
    public function isMarketing(array $options): bool
    {
        return ($options['is_marketing'] ?? false) === true || in_array((string) ($options['campaign_type'] ?? ''), [
            'birthday', 'birthday_email', 'campaign', 'group_direct_send', 'direct_message',
            'tenant_rewards_reminder', 'candle_cash_reminder',
        ], true);
    }

    /** @param array<string,mixed> $options
     * @return array{0:?string,1:array<string,mixed>}
     */
    public function prepare(int $tenantId, string $email, ?string $text, array $options): array
    {
        $url = $this->url($tenantId, $email);
        $options['headers'] = [...(array) ($options['headers'] ?? []),
            'List-Unsubscribe' => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
        $html = (string) ($options['html_body'] ?? '');
        // Replace already-sent-template mailto footers at the last provider boundary.
        $html = preg_replace('/mailto:info@theforestrystudio\.com\?subject=Unsubscribe|#unsubscribe-preview/i', e($url), $html) ?? $html;
        if ($html !== '' && ! str_contains($html, '/email/unsubscribe?')) {
            $footer = '<p style="font-size:12px;color:#566a64;">To stop marketing emails, <a clicktracking="off" href="'.e($url).'">unsubscribe</a>.</p>';
            $html = str_contains($html, '</body>') ? str_replace('</body>', $footer.'</body>', $html) : $html.$footer;
        }
        $options['html_body'] = $html !== '' ? $html : null;
        $text = (string) ($options['text_body'] ?? $text);
        // Gateway and legacy dispatch can both prepare the same send.
        $text = preg_replace('/\nUnsubscribe: https?:\/\/\S+\s*$/', '', $text) ?? $text;
        $text = rtrim($text)."\n\nUnsubscribe: ".$url;
        $options['text_body'] = $text;

        return [$text, $options];
    }

    /** @return array<string,mixed> */
    public function blockedResult(int $tenantId): array
    {
        return ['success' => false, 'provider' => null, 'message_id' => null, 'status' => 'suppressed',
            'error_code' => 'email_unsubscribed', 'error_message' => 'Marketing email is blocked by address-level suppression.',
            'retryable' => false, 'payload' => [], 'dry_run' => false, 'tenant_id' => $tenantId];
    }
}
