<x-app-layout>
<style>
  .mail-shell{max-width:1480px;margin:25px auto;padding:0 24px;color:#17232d}
  .mail-head{display:flex;justify-content:space-between;gap:20px;align-items:end;margin-bottom:20px}
  .mail-head h1{font-size:30px;font-weight:750;margin:0}.mail-head p{margin:5px 0;color:#5b6875}
  .mail-grid{display:grid;grid-template-columns:235px minmax(270px,430px) minmax(340px,1fr);min-height:620px;border:1px solid #dbe2e8;border-radius:18px;overflow:hidden;background:white;box-shadow:0 10px 35px #182c3a10}
  .mail-side{padding:20px;background:#f4f7f9;border-right:1px solid #e0e7ec}
  .mail-side h2,.mail-list h2,.mail-reader h2{font-size:16px;margin:0 0 15px}
  .mail-side a{display:block;padding:10px 12px;border-radius:10px;color:#334757;text-decoration:none}
  .mail-side a.active{background:#dbe9f7;color:#174879;font-weight:700}.mail-side small{display:block;margin:17px 10px 5px;color:#657583;text-transform:uppercase;font-weight:700;font-size:11px}
  .mail-compose-trigger,.mail-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 16px;border:0;border-radius:20px;background:#0e6199;color:white;font-weight:700;cursor:pointer;text-decoration:none}
  .mail-compose-trigger{margin-bottom:20px;width:100%}.mail-list{border-right:1px solid #e0e7ec}
  .mail-list-head{padding:18px;border-bottom:1px solid #e0e7ec}.mail-list-head form{display:flex;gap:8px}
  .mail-list input,.mail-compose input,.mail-compose textarea,.mail-admin input,.mail-admin select{width:100%;padding:10px 12px;border:1px solid #bfccd6;border-radius:9px;background:white;color:#17232d}
  .mail-list-item{display:block;padding:15px 18px;border-bottom:1px solid #edf1f4;text-decoration:none;color:inherit}.mail-list-item:hover,.mail-list-item.selected{background:#eef5fb}.mail-list-item.unread{font-weight:700}
  .mail-list-item strong,.mail-list-item span,.mail-list-item small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.mail-list-item span{margin:5px 0}.mail-list-item small{font-weight:400;color:#6c7a86}
  .mail-reader{padding:25px;min-width:0}.mail-reader .meta{color:#667681;font-size:13px;line-height:1.7}.mail-reader .body{margin-top:25px;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.6}
  .mail-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:25px}.mail-actions form{display:inline}.mail-actions button{border:1px solid #c4d0d8;background:white;border-radius:16px;padding:8px 12px;cursor:pointer}
  .mail-compose{margin-top:22px;padding:24px;border:1px solid #dbe2e8;border-radius:16px;background:white}.mail-compose h2{font-size:19px}.mail-compose form{display:grid;gap:13px}.mail-compose label,.mail-admin label{display:grid;gap:5px;font-weight:650}.mail-compose textarea{min-height:180px}
  .mail-admin{margin-top:26px;padding:22px;background:white;border:1px solid #dbe2e8;border-radius:16px}.mail-admin h2{font-size:20px}.mail-admin h3{font-size:16px;margin:24px 0 10px}.mail-admin form{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}.mail-admin input,.mail-admin select{max-width:280px}.mail-admin article{padding:12px 0;border-top:1px solid #e0e7ec}.mail-admin p,.mail-admin small{color:#586a77}
  .mail-alert{padding:12px 16px;border-radius:8px;margin:12px 0;background:#e5f4e9;color:#14552d}.mail-alert.error{background:#fce8e7;color:#9a2b21}
  @media(max-width:1040px){.mail-grid{grid-template-columns:180px 1fr}.mail-reader{grid-column:1/-1;border-top:1px solid #e0e7ec}.mail-side{padding:12px}}
  @media(max-width:620px){.mail-shell{padding:0 12px}.mail-grid{display:block}.mail-side{border-right:0;border-bottom:1px solid #e0e7ec}.mail-list{border-right:0}.mail-side a{display:inline-block}.mail-reader{min-height:250px}}
</style>
<main class="mail-shell">
  <div class="mail-head"><div><h1>Mail</h1><p>Send and receive mail for {{ $tenant->name }}</p></div></div>
  @if(session('success'))<div class="mail-alert">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="mail-alert error">{{ session('error') }}</div>@endif
  @if($errors->any())<div class="mail-alert error">{{ $errors->first() }}</div>@endif
  <div class="mail-grid">
    <aside class="mail-side">
      @if($mailbox)<a class="mail-compose-trigger" href="#compose">Compose</a>@endif
      <h2>Mailboxes</h2>
      @forelse($mailboxes as $box)
        <a class="{{ $mailbox?->id === $box->id ? 'active' : '' }}" href="{{ route('mail.index', ['mailbox'=>$box->id]) }}">{{ $box->display_name }}<small>{{ $box->address }}</small></a>
      @empty
        <p>No mailbox access yet. Ask a workspace admin to add you.</p>
      @endforelse
      @if($mailbox)
        <small>Folders</small>
        @foreach(['inbox'=>'Inbox','sent'=>'Sent','draft'=>'Drafts','trash'=>'Trash'] as $key=>$label)
          <a class="{{ $folder === $key ? 'active' : '' }}" href="{{ route('mail.index', ['mailbox'=>$mailbox->id,'folder'=>$key]) }}">{{ $label }}</a>
        @endforeach
      @endif
    </aside>
    <section class="mail-list">
      <div class="mail-list-head"><h2>{{ ucfirst($folder) }}</h2>
        @if($mailbox)<form method="get" action="{{ route('mail.index') }}"><input type="hidden" name="mailbox" value="{{ $mailbox->id }}"><input type="hidden" name="folder" value="{{ $folder }}"><input type="search" name="q" value="{{ $search }}" placeholder="Search this folder" aria-label="Search this folder"><button class="mail-btn">Search</button></form>@endif
      </div>
      @if($messages)
        @forelse($messages as $item)
          <a class="mail-list-item {{ !$item->read_at ? 'unread' : '' }} {{ $selected?->id === $item->id ? 'selected' : '' }}" href="{{ route('mail.index',['mailbox'=>$mailbox->id,'folder'=>$folder,'message'=>$item->id,'q'=>$search]) }}">
            <strong>{{ $item->direction === 'inbound' ? $item->from_address : $item->to_address }} @if($item->starred_at)★@endif</strong>
            <span>{{ $item->subject ?: '(No subject)' }}</span><small>{{ Str::limit($item->text_body, 85) }} · {{ $item->occurred_at?->format('M j, g:i A') }}</small>
          </a>
        @empty
          <p style="padding:20px;color:#657583">No messages here yet.</p>
        @endforelse
        <div style="padding:12px">{{ $messages->links() }}</div>
      @endif
    </section>
    <section class="mail-reader">
      @if($selected)
        <h2>{{ $selected->subject ?: '(No subject)' }}</h2>
        <div class="meta">From: {{ $selected->from_address }}<br>To: {{ $selected->to_address }}<br>{{ $selected->occurred_at?->format('F j, Y g:i A') }} · {{ $selected->delivery_status }}</div>
        <div class="body">{{ $selected->text_body }}</div>
        <div class="mail-actions">
          @foreach([$selected->read_at?'unread':'read',$selected->starred_at?'unstar':'star',$selected->folder==='trash'?'restore':'trash'] as $action)
            <form method="post" action="{{ route('mail.messages.action',['mailbox'=>$mailbox->id,'message'=>$selected->id]) }}">@csrf<input type="hidden" name="action" value="{{ $action }}"><button>{{ ucfirst($action) }}</button></form>
          @endforeach
          @if($selected->direction === 'inbound')<a href="#compose" class="mail-btn">Reply</a>@endif
        </div>
      @else
        <h2>Choose a message</h2><p>Mail for your selected address appears here.</p>
      @endif
    </section>
  </div>
  @if($mailbox)
  <section class="mail-compose" id="compose"><h2>New message from {{ $mailbox->address }}</h2>
    @if($mailbox->status !== 'ready')<p>This address is waiting for domain setup. Sending is unavailable.</p>@endif
    <form method="post" action="{{ route('mail.send',['mailbox'=>$mailbox->id]) }}">@csrf
      <label>To<input type="email" name="to" required value="{{ old('to', $selected?->direction === 'inbound' ? $selected->from_address : '') }}"></label>
      <label>Subject<input name="subject" required maxlength="255" value="{{ old('subject', $selected?->direction === 'inbound' ? 'Re: '.$selected->subject : '') }}"></label>
      <label>Message<textarea name="body" required maxlength="100000">{{ old('body') }}</textarea></label>
      <div><button class="mail-btn" @disabled($mailbox->status !== 'ready')>Send</button></div>
    </form>
  </section>
  @endif
  @if($isAdmin)
  <section class="mail-admin"><h2>Mail administration</h2><p>Create domains and addresses, then give workspace members access. A mailbox cannot send or receive until its domain and transport are ready.</p>
    <h3>Add a domain</h3><form method="post" action="{{ route('mail.domains.store') }}">@csrf<input name="domain" required placeholder="example.com"><select name="transport"><option value="sendgrid">SendGrid</option><option value="direct">Everbranch mail server</option></select><button class="mail-btn">Add domain</button></form>
    @foreach($domains as $domain)
      <article><strong>{{ $domain->domain }}</strong> · {{ $domain->transport }} · {{ str_replace('_',' ',$domain->status) }}
        <p>Required MX: {{ $domain->dns_records[0]['value'] ?? 'Pending' }}</p>
        <form method="post" action="{{ route('mail.domains.verify',['domain'=>$domain->id]) }}">@csrf<button class="mail-btn">Check DNS & sender</button></form>
        @if($domain->transport === 'direct' && !$domain->provider_domain_id)<form method="post" action="{{ route('mail.domains.provision',['domain'=>$domain->id]) }}">@csrf<button class="mail-btn">Provision on mail server</button></form>@endif
      </article>
    @endforeach
    @if($domains->isNotEmpty())
      <h3>Create an address</h3><form method="post" action="{{ route('mail.mailboxes.store') }}">@csrf
        <input name="local_part" required placeholder="info"><select name="domain_id">@foreach($domains as $domain)<option value="{{ $domain->id }}">{{ $domain->domain }}</option>@endforeach</select><input name="display_name" required placeholder="Display name"><button class="mail-btn">Create address</button>
      </form>
    @endif
    <h3>Mailbox access</h3>
    @foreach($domains->flatMap->mailboxes as $box)
      <article><strong>{{ $box->address }}</strong> · {{ str_replace('_',' ',$box->status) }}
        @if($box->domain->transport === 'direct' && !$box->provider_account_id)<form method="post" action="{{ route('mail.mailboxes.provision',['mailbox'=>$box->id]) }}">@csrf<button class="mail-btn">Provision address</button></form>@endif
        <form method="post" action="{{ route('mail.mailboxes.members',['mailbox'=>$box->id]) }}">@csrf
          <select name="user_id">@foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }} ({{ $member->email }})</option>@endforeach</select>
          <select name="permission"><option value="read_write">Read & send</option><option value="read_only">Read only</option></select><button class="mail-btn">Grant access</button>
        </form>
      </article>
    @endforeach
  </section>
  @endif
</main>
</x-app-layout>
