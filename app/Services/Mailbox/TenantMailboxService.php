<?php

namespace App\Services\Mailbox;

use App\Models\Tenant;
use App\Models\TenantMailbox;
use App\Models\TenantMailDomain;
use App\Models\TenantMailMessage;
use App\Models\TenantMessagingSenderProfile;
use App\Models\User;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class TenantMailboxService
{
    public function __construct(private TenantMessagingGateway $gateway) {}

    public function createDomain(Tenant $tenant, string $domain, string $transport): TenantMailDomain
    {
        $domain = strtolower(trim($domain));
        if (! filter_var('postmaster@'.$domain, FILTER_VALIDATE_EMAIL) || ! in_array($transport, ['sendgrid', 'direct'], true)) {
            throw ValidationException::withMessages(['domain' => 'Enter a valid mail domain and transport.']);
        }
        if (TenantMailDomain::query()->forAllTenants()->where('domain', $domain)->where('tenant_id', '!=', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['domain' => 'This domain is already assigned to another Everbranch workspace.']);
        }
        $mx = $transport === 'direct' ? (string) config('mailbox.mail_host') : 'mx.sendgrid.net';
        $proof = 'everbranch-mail-verification='.Str::random(40);

        return TenantMailDomain::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'domain' => $domain],
            ['transport' => $transport, 'status' => 'pending_dns', 'dns_records' => [
                ['type' => 'TXT', 'host' => '_everbranch-mail.'.$domain, 'value' => $proof],
                ['type' => 'MX', 'host' => $domain, 'value' => $mx, 'priority' => 10],
            ]]
        );
    }

    public function domainOwnershipVerified(TenantMailDomain $domain): bool
    {
        $proof = collect($domain->dns_records)->firstWhere('type', 'TXT');
        if (! is_array($proof) || ! filled($proof['host'] ?? null) || ! filled($proof['value'] ?? null)) {
            return false;
        }
        $records = dns_get_record((string) $proof['host'], DNS_TXT) ?: [];

        return collect($records)->contains(fn (array $record): bool => ($record['txt'] ?? null) === $proof['value']);
    }

    public function verifyDomain(TenantMailDomain $domain): bool
    {
        $expectedMx = $domain->transport === 'direct' ? (string) config('mailbox.mail_host') : 'mx.sendgrid.net';
        $mx = dns_get_record($domain->domain, DNS_MX) ?: [];
        $mxMatches = collect($mx)->contains(fn (array $row): bool => strtolower(rtrim((string) ($row['target'] ?? ''), '.')) === strtolower(rtrim($expectedMx, '.')));
        $senderReady = $domain->transport === 'direct'
            ? ((bool) config('mailbox.direct_enabled') && filled($domain->provider_domain_id))
            : TenantMessagingSenderProfile::query()->forTenantId($domain->tenant_id)
                ->where('authenticated_domain', $domain->domain)->where('verification_status', 'verified')->exists();
        $ready = $this->domainOwnershipVerified($domain) && $mxMatches && $senderReady;
        $domain->update(['status' => $ready ? 'ready' : 'pending_dns', 'verified_at' => $ready ? now() : null]);
        if (! $ready) {
            $domain->mailboxes()->update(['status' => 'pending_domain']);
        } else {
            foreach ($domain->mailboxes as $mailbox) {
                $mailbox->update(['status' => $this->mailboxTransportReady($mailbox) ? 'ready' : 'pending_domain']);
            }
        }

        return $ready;
    }

    public function createMailbox(TenantMailDomain $domain, string $localPart, string $displayName, User $owner): TenantMailbox
    {
        $localPart = strtolower(trim($localPart));
        if (! preg_match('/^[a-z0-9][a-z0-9._+-]{0,62}$/', $localPart) || str_contains($localPart, '..')) {
            throw ValidationException::withMessages(['local_part' => 'Enter a valid mailbox name.']);
        }
        $address = $localPart.'@'.$domain->domain;

        return DB::transaction(function () use ($domain, $address, $displayName, $owner): TenantMailbox {
            $mailbox = TenantMailbox::query()->firstOrCreate(
                ['tenant_id' => $domain->tenant_id, 'address' => $address],
                ['tenant_mail_domain_id' => $domain->id, 'display_name' => trim($displayName) ?: $address, 'status' => 'pending_domain']
            );
            if ($domain->status === 'ready' && $this->mailboxTransportReady($mailbox)) {
                $mailbox->update(['status' => 'ready']);
            }
            $mailbox->users()->syncWithoutDetaching([$owner->id => ['tenant_id' => $domain->tenant_id, 'permission' => 'read_write']]);

            return $mailbox;
        });
    }

    public function grantAccess(TenantMailbox $mailbox, int $userId, string $permission): void
    {
        if (! in_array($permission, ['read_only', 'read_write'], true)) {
            throw ValidationException::withMessages(['permission' => 'Invalid mailbox permission.']);
        }
        $member = DB::table('tenant_user')->where('tenant_id', $mailbox->tenant_id)->where('user_id', $userId)->where('membership_active', true)->exists();
        if (! $member) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active member of this workspace.']);
        }
        $mailbox->users()->syncWithoutDetaching([$userId => ['tenant_id' => $mailbox->tenant_id, 'permission' => $permission]]);
    }

    public function send(TenantMailbox $mailbox, string $to, string $subject, string $body): TenantMailMessage
    {
        $to = strtolower(trim($to));
        $subject = trim($subject);
        $body = trim($body);
        if (! filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') {
            throw ValidationException::withMessages(['message' => 'To, subject, and message are required.']);
        }
        if ($mailbox->status !== 'ready' || $mailbox->domain->status !== 'ready') {
            throw ValidationException::withMessages(['mailbox' => 'Finish domain setup before sending from this address.']);
        }
        if ($mailbox->domain->transport === 'direct') {
            if (! config('mailbox.direct_enabled')) {
                throw ValidationException::withMessages(['mailbox' => 'Direct mail transport is not ready.']);
            }
            $password = (string) data_get($mailbox->provider_credentials, 'password');
            if ($password === '') {
                throw ValidationException::withMessages(['mailbox' => 'This mailbox has no direct submission credential.']);
            }
            $host = (string) config('mailbox.submission_host');
            $port = (int) config('mailbox.submission_port');
            $transport = new EsmtpTransport($host, $port, false);
            $transport->setRequireTls(true);
            $transport->setUsername($mailbox->address);
            $transport->setPassword($password);
            (new Mailer($transport))->send((new Email)->from(new \Symfony\Component\Mime\Address($mailbox->address, $mailbox->display_name))->to($to)->subject($subject)->text($body));
            $provider = 'direct';
        } else {
            $sender = TenantMessagingSenderProfile::query()->forTenantId($mailbox->tenant_id)
                ->where('from_email', $mailbox->address)->where('verification_status', 'verified')->first();
            if (! $sender) {
                throw ValidationException::withMessages(['mailbox' => 'A verified sender profile is required for this address.']);
            }
            $result = $this->gateway->sendEmail($mailbox->tenant_id, $to, $subject, $body, [
                'sender_profile_id' => $sender->id, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            ]);
            if (! ($result['success'] ?? false)) {
                throw ValidationException::withMessages(['message' => (string) ($result['error_message'] ?? 'Email delivery was not accepted.')]);
            }
            $provider = 'sendgrid';
        }

        return TenantMailMessage::query()->create([
            'tenant_id' => $mailbox->tenant_id, 'tenant_mailbox_id' => $mailbox->id, 'folder' => 'sent',
            'direction' => 'outbound', 'from_address' => $mailbox->address, 'to_address' => $to,
            'subject' => $subject, 'text_body' => $body, 'provider_message_id' => null,
            'thread_key' => self::threadKey($to, $subject), 'delivery_status' => 'accepted',
            'read_at' => now(), 'occurred_at' => now(),
        ]);
    }

    public function ingestSendGrid(array $payload): int
    {
        $from = $this->address((string) ($payload['from'] ?? ''));
        $to = $this->address((string) ($payload['to'] ?? ''));
        if (! $from || ! $to) {
            return 0;
        }
        $mailbox = TenantMailbox::query()->forAllTenants()->where('address', $to)->where('status', 'ready')->first();
        if (! $mailbox || $mailbox->domain->transport !== 'sendgrid' || $mailbox->domain->status !== 'ready') {
            return 0;
        }
        $headers = (string) ($payload['headers'] ?? '');
        preg_match('/^Message-ID:\s*(.+)$/im', $headers, $match);
        $messageId = trim($match[1] ?? '') ?: null;
        if ($messageId && TenantMailMessage::query()->forTenantId($mailbox->tenant_id)->where('tenant_mailbox_id', $mailbox->id)->where('direction', 'inbound')->where('provider_message_id', $messageId)->exists()) {
            return 1;
        }
        $subject = trim((string) ($payload['subject'] ?? ''));
        TenantMailMessage::query()->create([
            'tenant_id' => $mailbox->tenant_id, 'tenant_mailbox_id' => $mailbox->id, 'folder' => 'inbox',
            'direction' => 'inbound', 'from_address' => $from, 'to_address' => $to,
            'subject' => mb_substr($subject, 0, 255), 'text_body' => mb_substr((string) ($payload['text'] ?? ''), 0, 1_000_000),
            'provider_message_id' => $messageId, 'thread_key' => self::threadKey($from, $subject),
            'delivery_status' => 'received', 'occurred_at' => now(),
        ]);

        return 1;
    }

    public static function threadKey(string $correspondent, string $subject): string
    {
        $clean = preg_replace('/^(re|fwd?):\s*/i', '', trim($subject));

        return hash('sha256', strtolower(trim($correspondent)).'|'.strtolower(trim((string) $clean)));
    }

    private function address(string $value): ?string
    {
        preg_match('/<([^>]+)>/', $value, $match);
        $email = strtolower(trim($match[1] ?? $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function mailboxTransportReady(TenantMailbox $mailbox): bool
    {
        if ($mailbox->domain->transport === 'direct') {
            return filled($mailbox->provider_account_id);
        }

        return TenantMessagingSenderProfile::query()->forTenantId($mailbox->tenant_id)
            ->where('from_email', $mailbox->address)->where('verification_status', 'verified')->exists();
    }
}
