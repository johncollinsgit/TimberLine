<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMailbox;
use App\Models\TenantMailDomain;
use App\Models\TenantMailMessage;
use App\Services\Mailbox\CloudflareDnsSetupService;
use App\Services\Mailbox\StalwartProvisioningService;
use App\Services\Mailbox\TenantMailboxService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantMailboxController extends Controller
{
    public function __construct(private TenantMailboxService $mail) {}

    public function setup(Request $request): View
    {
        $tenant = $this->adminTenant($request);

        return view('mail.setup', [
            'tenant' => $tenant,
            'domains' => TenantMailDomain::query()->forTenantId($tenant->id)->with('mailboxes')->orderBy('domain')->get(),
        ]);
    }

    public function signup(Request $request, TenantModuleCatalogService $catalog): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $data = $request->validate([
            'address' => ['required', 'email', 'max:320'],
            'display_name' => ['required', 'string', 'max:120'],
            'transport' => ['required', 'in:sendgrid,direct'],
        ]);
        [$localPart, $domainName] = explode('@', strtolower(trim($data['address'])), 2);
        $domain = $this->mail->createDomain($tenant, $domainName, $data['transport']);
        if ($domain->transport !== $data['transport']) {
            return back()->withErrors(['transport' => 'This domain is already set up with a different mail transport.']);
        }
        $box = $this->mail->createMailbox($domain, $localPart, $data['display_name'], $request->user());
        $access = $catalog->requestModuleAccessForTenant($tenant->id, 'email', $request->user()->id, 'mail_setup');
        $accessMessage = ($access['ok'] ?? false) ? ' Everbranch access request recorded.' : '';

        return redirect()->route('mail.setup', ['tenant' => $tenant->slug, 'domain' => $domain->id])
            ->with('success', $box->address.' was recorded. Complete domain verification and delivery setup before using it.'.$accessMessage);
    }

    public function setupCloudflare(Request $request, int $domain, CloudflareDnsSetupService $cloudflare): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $record = TenantMailDomain::query()->forTenantId($tenant->id)->findOrFail($domain);
        $data = $request->validate([
            'zone_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/i'],
            'api_token' => ['required', 'string', 'max:500'],
        ]);
        try {
            $message = $cloudflare->apply($record, $data['zone_id'], $data['api_token']);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return back()->withErrors(['cloudflare' => 'Cloudflare could not be reached. Try again shortly.']);
        }

        return back()->with('success', $message);
    }

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $accessible = TenantMailbox::query()->forTenantId($tenant->id)
            ->whereHas('users', fn ($query) => $query->where('users.id', $request->user()->id))
            ->orderBy('address')->get();
        $mailbox = $accessible->firstWhere('id', (int) $request->query('mailbox')) ?? $accessible->first();
        $folder = in_array($request->query('folder'), ['inbox', 'sent', 'draft', 'trash'], true) ? $request->query('folder') : 'inbox';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $messages = $mailbox ? TenantMailMessage::query()->forTenantId($tenant->id)->where('tenant_mailbox_id', $mailbox->id)
            ->where('folder', $folder)
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('subject', 'like', '%'.$search.'%')->orWhere('from_address', 'like', '%'.$search.'%')->orWhere('to_address', 'like', '%'.$search.'%')))
            ->orderByDesc('occurred_at')->paginate(30)->withQueryString() : null;
        $selected = $mailbox && $request->query('message') ? TenantMailMessage::query()->forTenantId($tenant->id)
            ->where('tenant_mailbox_id', $mailbox->id)->find((int) $request->query('message')) : null;
        $isAdmin = $this->isAdmin($request, $tenant);

        return view('mail.index', [
            'tenant' => $tenant, 'mailboxes' => $accessible, 'mailbox' => $mailbox, 'folder' => $folder,
            'messages' => $messages, 'selected' => $selected, 'search' => $search, 'isAdmin' => $isAdmin,
            'domains' => $isAdmin ? TenantMailDomain::query()->forTenantId($tenant->id)->with('mailboxes')->orderBy('domain')->get() : collect(),
            'members' => $isAdmin ? $tenant->users()->wherePivot('membership_active', true)->orderBy('name')->get() : collect(),
        ]);
    }

    public function createDomain(Request $request): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'transport' => ['required', 'in:sendgrid,direct']]);
        $this->mail->createDomain($tenant, $data['domain'], $data['transport']);

        return back()->with('success', 'Domain added. Complete and verify DNS before using it.');
    }

    public function verifyDomain(Request $request, int $domain): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $record = TenantMailDomain::query()->forTenantId($tenant->id)->findOrFail($domain);
        $ready = $this->mail->verifyDomain($record);

        return back()->with($ready ? 'success' : 'error', $ready ? 'Domain is ready for mail.' : 'DNS or sending provider is not ready yet.');
    }

    public function createMailbox(Request $request): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $data = $request->validate(['domain_id' => ['required', 'integer'], 'local_part' => ['required', 'string', 'max:63'], 'display_name' => ['required', 'string', 'max:120']]);
        $domain = TenantMailDomain::query()->forTenantId($tenant->id)->findOrFail($data['domain_id']);
        $this->mail->createMailbox($domain, $data['local_part'], $data['display_name'], $request->user());

        return back()->with('success', 'Mailbox created. Its address becomes active when the domain is ready.');
    }

    public function provisionDomain(Request $request, int $domain, StalwartProvisioningService $server): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $record = TenantMailDomain::query()->forTenantId($tenant->id)->findOrFail($domain);
        abort_unless($this->mail->domainOwnershipVerified($record), 422, 'Add the domain verification TXT record before provisioning.');
        $server->provisionDomain($record);

        return back()->with('success', 'Domain created on the mail server. Add its DNS records before verification.');
    }

    public function provisionMailbox(Request $request, int $mailbox, StalwartProvisioningService $server): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $record = TenantMailbox::query()->forTenantId($tenant->id)->findOrFail($mailbox);
        $server->provisionMailbox($record);

        return back()->with('success', 'Address created on the mail server. Delivery starts after domain verification.');
    }

    public function grant(Request $request, int $mailbox): RedirectResponse
    {
        $tenant = $this->adminTenant($request);
        $box = TenantMailbox::query()->forTenantId($tenant->id)->findOrFail($mailbox);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'permission' => ['required', 'in:read_only,read_write']]);
        $this->mail->grantAccess($box, (int) $data['user_id'], $data['permission']);

        return back()->with('success', 'Mailbox access saved.');
    }

    public function send(Request $request, int $mailbox): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $box = $this->boxForUser($tenant, $request, $mailbox, true);
        $data = $request->validate(['to' => ['required', 'email', 'max:320'], 'subject' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:100000']]);
        $this->mail->send($box, $data['to'], $data['subject'], $data['body']);

        return redirect()->route('mail.index', ['mailbox' => $box->id, 'folder' => 'sent'])->with('success', 'Message accepted for delivery.');
    }

    public function action(Request $request, int $mailbox, int $message): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $box = $this->boxForUser($tenant, $request, $mailbox, true);
        $record = TenantMailMessage::query()->forTenantId($tenant->id)->where('tenant_mailbox_id', $box->id)->findOrFail($message);
        $data = $request->validate(['action' => ['required', 'in:read,unread,star,unstar,trash,restore']]);
        match ($data['action']) {
            'read' => $record->update(['read_at' => now()]),
            'unread' => $record->update(['read_at' => null]),
            'star' => $record->update(['starred_at' => now()]),
            'unstar' => $record->update(['starred_at' => null]),
            'trash' => $record->update(['folder' => 'trash']),
            'restore' => $record->update(['folder' => $record->direction === 'outbound' ? 'sent' : 'inbox']),
        };

        return back();
    }

    private function boxForUser(Tenant $tenant, Request $request, int $id, bool $write): TenantMailbox
    {
        $box = TenantMailbox::query()->forTenantId($tenant->id)->findOrFail($id);
        $grant = DB::table('tenant_mailbox_users')->where('tenant_id', $tenant->id)->where('tenant_mailbox_id', $box->id)->where('user_id', $request->user()->id)->first();
        abort_unless($grant && (! $write || $grant->permission === 'read_write'), 403);

        return $box;
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function adminTenant(Request $request): Tenant
    {
        $tenant = $this->tenant($request);
        abort_unless($this->isAdmin($request, $tenant), 403);

        return $tenant;
    }

    private function isAdmin(Request $request, Tenant $tenant): bool
    {
        $membership = $tenant->users()->whereKey($request->user()->id)->first();

        return $membership && $membership->pivot->membership_active && in_array($membership->pivot->role, ['admin', 'owner', 'tenant_owner'], true);
    }
}
