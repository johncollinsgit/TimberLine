<x-app-layout>
<main style="max-width:900px;margin:32px auto;padding:0 20px;color:#17232d">
  <p><a href="{{ route('dashboard') }}">← Workspace</a></p>
  <h1 style="font-size:30px;font-weight:750;margin:12px 0">Set up Everbranch Mail</h1>
  <p style="color:#526273;max-width:680px">Choose an address for {{ $tenant->name }}. We'll save the request now and walk you through proving domain ownership, DNS, and delivery. The address stays inactive until those steps are complete.</p>
  @if(session('success'))<p role="status" style="padding:14px;background:#e4f4e9;border-radius:10px">{{ session('success') }}</p>@endif
  @if(session('error'))<p role="alert" style="padding:14px;background:#fee9e8;border-radius:10px">{{ session('error') }}</p>@endif
  @if($errors->any())<p role="alert" style="padding:14px;background:#fee9e8;border-radius:10px">{{ $errors->first() }}</p>@endif
  <section style="background:white;border:1px solid #d9e1e8;border-radius:16px;padding:24px;margin:24px 0">
    <h2 style="font-size:21px;font-weight:700">1. Add your address</h2>
    <form method="post" action="{{ route('mail.setup.store') }}" style="display:grid;gap:15px;max-width:550px">
      @csrf
      <label style="display:grid;gap:5px">Email address<input type="email" name="address" value="{{ old('address') }}" placeholder="info@yourdomain.com" required maxlength="320" style="padding:11px;border:1px solid #b8c8d4;border-radius:8px"></label>
      <label style="display:grid;gap:5px">Display name<input name="display_name" value="{{ old('display_name', $tenant->name) }}" required maxlength="120" style="padding:11px;border:1px solid #b8c8d4;border-radius:8px"></label>
      <label style="display:grid;gap:5px">Delivery method
        <select name="transport" style="padding:11px;border:1px solid #b8c8d4;border-radius:8px">
          <option value="direct" @selected(old('transport','direct') === 'direct')>Everbranch mail server (setup pending)</option>
          <option value="sendgrid" @selected(old('transport') === 'sendgrid')>SendGrid (existing provider)</option>
        </select>
      </label>
      <p style="font-size:14px;color:#526273">Our team must finish server and sender checks before direct mail can receive or send. Your choice is saved for this domain.</p>
      <button style="background:#0e6199;color:white;border:0;border-radius:22px;padding:11px 20px;justify-self:start;font-weight:700">Save address</button>
    </form>
  </section>
  @foreach($domains as $domain)
  <section id="domain-{{ $domain->id }}" style="background:white;border:1px solid #d9e1e8;border-radius:16px;padding:24px;margin:24px 0">
    <h2 style="font-size:21px;font-weight:700">{{ $domain->domain }}</h2>
    <p><strong>Status:</strong> {{ $domain->status === 'ready' ? 'Ready' : 'Setup pending' }} · {{ $domain->transport === 'direct' ? 'Everbranch mail server' : 'SendGrid' }}</p>
    <p><strong>Addresses:</strong> {{ $domain->mailboxes->pluck('address')->join(', ') ?: 'None yet' }}</p>
    <div style="background:#eaf3fb;border-radius:12px;padding:18px;margin:20px 0">
      <h3 style="font-size:17px;font-weight:700;margin:0 0 7px">Quick setup with Cloudflare</h3>
      <p style="margin:5px 0 12px">Create a token limited to this zone with Zone Read and DNS Write, then enter it with the zone ID. Everbranch uses it for this request and does not save it. We add the ownership record now; we add MX only after the mail transport is ready and only when no other MX is in place.</p>
      <form method="post" action="{{ route('mail.setup.cloudflare', ['domain'=>$domain->id]) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
        @csrf
        <label style="display:grid;gap:4px">Cloudflare zone ID<input name="zone_id" required pattern="[a-fA-F0-9]{32}" maxlength="32" autocomplete="off" style="padding:9px;border:1px solid #b8c8d4;border-radius:8px"></label>
        <label style="display:grid;gap:4px">Scoped API token<input type="password" name="api_token" required autocomplete="off" style="padding:9px;border:1px solid #b8c8d4;border-radius:8px"></label>
        <button style="background:#0e6199;color:white;border:0;border-radius:22px;padding:11px 17px;font-weight:700">Set up Cloudflare records</button>
      </form>
      <p style="font-size:13px;margin:10px 0 0"><a href="https://developers.cloudflare.com/fundamentals/api/get-started/create-token/" target="_blank" rel="noopener noreferrer">How to create a zone-scoped token ↗</a></p>
    </div>
    <details><summary style="cursor:pointer;font-weight:700">Set up DNS manually with another provider</summary>
    <h3 style="font-size:17px;font-weight:700;margin-top:20px">2. Verify ownership</h3>
    <p>At your DNS provider, add this TXT record. It proves that this Everbranch workspace controls the domain.</p>
    @php($proof = collect($domain->dns_records)->firstWhere('type', 'TXT'))
    @if($proof)
      <div style="background:#f4f7f9;padding:14px;border-radius:10px;overflow-wrap:anywhere;font-family:monospace">TXT · {{ $proof['host'] }} · {{ $proof['value'] }}</div>
    @else
      <p>Contact Everbranch support to regenerate the ownership record for this domain.</p>
    @endif
    <h3 style="font-size:17px;font-weight:700;margin-top:20px">3. Connect delivery</h3>
    <p>When Everbranch confirms that your selected transport is provisioned, set the MX record below. Changing MX routes incoming mail for the entire domain. If you already use another mailbox provider, arrange a migration first.</p>
    @php($mx = collect($domain->dns_records)->firstWhere('type', 'MX'))
    @if($mx)
      <div style="background:#f4f7f9;padding:14px;border-radius:10px;overflow-wrap:anywhere;font-family:monospace">MX · {{ $mx['host'] }} · priority {{ $mx['priority'] ?? 10 }} · {{ $mx['value'] }}</div>
    @endif
    <p style="font-size:14px;color:#526273">Sending also requires SPF, DKIM, and DMARC records. Everbranch will supply the exact values when the sender is provisioned.</p>
    </details>
    <form method="post" action="{{ route('mail.setup.verify', ['domain'=>$domain->id]) }}">@csrf<button style="border:1px solid #0e6199;color:#0e6199;background:white;border-radius:22px;padding:9px 16px;font-weight:700">Check setup</button></form>
    @if($domain->status === 'ready')<p><a href="{{ route('mail.index') }}">Open Mail →</a></p>@endif
  </section>
  @endforeach
</main>
</x-app-layout>
