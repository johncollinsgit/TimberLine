<?php

namespace App\Enterprise;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Uses explicitly approved existing native mailbox grants; never provisions or borrows a sender. */
class ConnectedMailboxGateway extends MailboxGateway
{
    public function __construct(private WorkspaceAccess $access) {}

    public function status(): array
    {
        $schema = Schema::hasTable('tenant_mailboxes') && Schema::hasTable('tenant_mail_domains') && Schema::hasTable('tenant_mailbox_users');
        $configured = config('enterprise.mailbox.enabled') && config('mailbox.direct_enabled')
            && count((array) config('enterprise.mailbox.allowed_ids', [])) > 0
            && is_string(config('mailbox.submission_host')) && preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D', config('mailbox.submission_host'))
            && (int) config('mailbox.submission_port') > 0 && (int) config('mailbox.submission_port') <= 65535;

        return ['schema_ready' => $schema, 'configured' => (bool) ($schema && $configured), 'send_enabled' => $this->ready(),
            'status' => ! $schema ? 'Enterprise mailbox schema is not installed.'
                : (! $configured ? 'A separately approved existing mailbox and Enterprise SMTP submission configuration are required.'
                    : (! config('enterprise.mailbox.send_enabled') ? 'Configured; real dispatch awaits separate owner approval.' : 'Configured; current own-user grant, exact preview and confirmation are required.')),
            'live_delivery_verified' => false];
    }

    public function ready(): bool
    {
        return ! config('enterprise.conversation.enabled') && (bool) config('enterprise.mailbox.enabled') && (bool) config('enterprise.mailbox.send_enabled')
            && count((array) config('enterprise.mailbox.allowed_ids', [])) > 0
            && (bool) config('mailbox.direct_enabled') && is_string(config('mailbox.submission_host'))
            && preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D', config('mailbox.submission_host')) === 1
            && (int) config('mailbox.submission_port') > 0 && (int) config('mailbox.submission_port') <= 65535
            && Schema::hasTable('tenant_mailboxes') && Schema::hasTable('tenant_mail_domains') && Schema::hasTable('tenant_mailbox_users');
    }

    private function rows(Tenant $tenant, User $user, string $areaId)
    {
        $actor = $this->access->actor($tenant, $user, true);
        $this->access->area($tenant, $actor, $areaId);
        abort_unless(WorkspaceAccess::permits($actor, $areaId, true), 404);
        if (! config('enterprise.mailbox.enabled') || ! Schema::hasTable('tenant_mailboxes') || ! Schema::hasTable('tenant_mail_domains') || ! Schema::hasTable('tenant_mailbox_users')) {
            return collect();
        }

        return DB::table('tenant_mailboxes as mailbox')->join('tenant_mail_domains as domain', function ($join): void {
            $join->on('domain.id', '=', 'mailbox.tenant_mail_domain_id')->on('domain.tenant_id', '=', 'mailbox.tenant_id');
        })->join('tenant_mailbox_users as grant', function ($join): void {
            $join->on('grant.tenant_mailbox_id', '=', 'mailbox.id')->on('grant.tenant_id', '=', 'mailbox.tenant_id');
        })->where('mailbox.tenant_id', $tenant->id)->where('grant.user_id', $user->id)->where('grant.permission', 'read_write')
            ->whereIn('mailbox.id', array_map('strval', (array) config('enterprise.mailbox.allowed_ids', [])))
            ->where('mailbox.status', 'ready')->where('domain.status', 'ready')->whereNotNull('domain.verified_at')->where('domain.transport', 'direct')
            ->select(['mailbox.*', 'domain.updated_at as domain_updated_at', 'domain.verified_at as domain_verified_at',
                'grant.id as grant_id', 'grant.updated_at as grant_updated_at'])->get();
    }

    public function mailboxes(Tenant $tenant, User $user, string $areaId): array
    {
        return $this->rows($tenant, $user, $areaId)->filter(fn ($row) => filter_var($row->address, FILTER_VALIDATE_EMAIL) && filled($row->provider_credentials))
            ->map(fn ($row) => ['id' => (string) $row->id, 'tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id,
                'area_id' => $areaId, 'address' => $row->address, 'verified' => true, 'permission' => 'send',
                // A secret/transport/grant change invalidates every preview without disclosing its value.
                'binding_version' => hash('sha256', json_encode([$row->updated_at, $row->domain_updated_at, $row->domain_verified_at,
                    $row->grant_id, $row->grant_updated_at, hash('sha256', $row->provider_credentials), config('mailbox.submission_host'), config('mailbox.submission_port')], JSON_THROW_ON_ERROR))])
            ->values()->all();
    }

    public function dispatch(Tenant $tenant, User $user, array $mailbox, array $message, string $idempotencyKey): array
    {
        abort_unless($this->ready(), 503, 'Real mailbox dispatch has not been approved and configured.');
        $binding = $this->authorize($tenant, $user, $mailbox['area_id'], $mailbox['id']);
        abort_unless($binding === $mailbox, 409, 'The mailbox binding changed.');
        abort_unless(\Illuminate\Support\Str::isUuid($idempotencyKey) && filter_var($message['to'] ?? '', FILTER_VALIDATE_EMAIL)
            && is_string($message['subject'] ?? null) && trim($message['subject']) !== '' && ! preg_match('/[\r\n]/', $message['subject'])
            && mb_strlen($message['subject']) <= 200 && is_string($message['body'] ?? null) && trim($message['body']) !== '' && mb_strlen($message['body']) <= 10000, 422);
        $row = $this->rows($tenant, $user, $mailbox['area_id'])->first(fn ($row) => (string) $row->id === $mailbox['id']);
        abort_unless($row, 404);
        $credentials = json_decode(Crypt::decryptString($row->provider_credentials), true, 8, JSON_THROW_ON_ERROR);
        abort_unless(is_string($credentials['password'] ?? null) && $credentials['password'] !== '', 503, 'This authorized mailbox has no submission credential.');
        // The existing draft state is committed as dispatching before this call. No retry occurs here.
        $this->submit($mailbox['address'], $credentials['password'], $message, $idempotencyKey);

        return ['status' => 'accepted', 'id' => 'smtp:'.$idempotencyKey];
    }

    protected function submit(string $sender, string $password, array $message, string $key): void
    {
        $transport = new EsmtpTransport((string) config('mailbox.submission_host'), (int) config('mailbox.submission_port'), false);
        $transport->setRequireTls(true);
        $transport->setUsername($sender);
        $transport->setPassword($password);
        $transport->getStream()->setTimeout(15);
        $email = (new Email)->from(new Address($sender))->to($message['to'])->subject($message['subject'])->text($message['body']);
        $email->getHeaders()->addIdHeader('Message-ID', $key.'@'.explode('@', $sender, 2)[1]);
        $transport->send($email);
    }
}
