<x-layouts::app.sidebar title="Wren Soccer">
<flux:main>
<style>
.soccer{max-width:1250px;margin:auto;color:#162c29}.soccer h1{font-size:36px;font-weight:750;letter-spacing:-1.2px}.soccer h2{font-size:22px;font-weight:700}.soccer p{line-height:1.6}.soccer .muted{color:#60726e;font-size:14px}.soccer .eyebrow{font-size:11px;font-weight:750;letter-spacing:2px;text-transform:uppercase;color:#28744d}.soccer .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin:28px 0}.soccer .card{background:white;border:1px solid #dce5df;border-radius:18px;padding:24px;box-shadow:0 4px 20px #16332d05}.soccer .card .number{display:block;font-size:42px;letter-spacing:-2px;font-weight:700}.soccer .card .rail{height:5px;border-radius:4px;background:#edf1eb;margin:20px 0 10px;overflow:hidden}.soccer .card .rail i{display:block;height:100%;background:#28744d}.soccer .section{padding:24px;background:white;border:1px solid #dce5df;border-radius:18px;margin:22px 0}.soccer .row{display:flex;align-items:center;justify-content:space-between;gap:20px;border-top:1px solid #e7ede9;padding:18px 0}.soccer .row:first-of-type{border-top:0}.soccer .row strong{font-weight:650}.soccer .pill{display:inline-flex;padding:5px 11px;border-radius:20px;font-size:12px;font-weight:650;background:#eef4ec;color:#2d6545}.soccer .pill.waitlisted,.soccer .pill.expired{background:#fff2d9;color:#87612c}.soccer .pill.offered{background:#edf3ff;color:#416aa2}.soccer button,.soccer .btn{padding:10px 15px;background:#183d32;color:white;border-radius:9px;font-weight:600;font-size:13px;cursor:pointer;display:inline-block}.soccer .ghost{background:#f3f6f1;color:#183d32;border:1px solid #dce5df}.soccer input,.soccer select,.soccer textarea{border:1px solid #cfdbd3;padding:10px 12px;border-radius:8px;max-width:100%;background:#fff;color:#142b25}.soccer input[type=checkbox]{accent-color:#28744d}.soccer label{display:grid;gap:7px;font-size:13px}.soccer .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin:20px 0}.soccer .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.soccer .alert{padding:16px 20px;background:#fff6e6;color:#79531c;border-radius:12px;margin:20px 0}.soccer .error{background:#fff0ee;color:#9b3630}.soccer .success{background:#eff8ed;color:#285735}.soccer .table-scroll{overflow:auto}.soccer table{width:100%;border-collapse:collapse;font-size:13px}.soccer th{text-align:left;color:#60726e;font-weight:550;font-size:11px;text-transform:uppercase;letter-spacing:.7px;padding:14px 10px;border-bottom:1px solid #dae4dc}.soccer td{padding:16px 10px;border-bottom:1px solid #edf1ed;vertical-align:top}.soccer small{font-size:12px}.soccer .editor{display:grid;grid-template-columns:360px 1fr;gap:24px}.soccer .message{padding:16px;border-radius:12px;background:#f3f6f1;margin:12px 0}.soccer .message p{white-space:pre-wrap}.soccer .message small{display:block;color:#60726e}.soccer details{border-top:1px solid #e5ece6;padding:16px 0}.soccer summary{cursor:pointer;font-weight:650}.soccer .team-name{display:flex;gap:12px;align-items:center}.soccer .dot{width:11px;height:11px;border-radius:50%;display:inline-block}.soccer .header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:12px}.soccer .empty{padding:28px 0;color:#60726e}.soccer a:focus-visible,.soccer button:focus-visible{outline:3px solid #a6cf88;outline-offset:3px}@media(max-width:800px){.soccer .cards,.soccer .editor{grid-template-columns:1fr}.soccer .header,.soccer .row{align-items:flex-start;flex-wrap:wrap}.soccer .form-grid{grid-template-columns:1fr}.soccer h1{font-size:29px}}
</style>
<div class="soccer">

@php($actionUrl=fn($a)=>route('soccer.action',['action'=>$a,'tenant'=>$tenant->slug]))

@php($pageUrl=fn($s,$extra=[])=>route('soccer.workspace',['section'=>$s,'tenant'=>$tenant->slug,...$extra]))
<header class="header"><div><span class="eyebrow">Wren Family Soccer</span><h1>{{ ['home'=>'A new season starts here.','parents'=>'Parents & families','players'=>'Players','coaches'=>$admin?'Coaches':'Your coach jersey','teams'=>'Teams','messages'=>'Messages','website'=>'Website editor','settings'=>'Season & connections'][$section] }}</h1><p class="muted">{{ $season?->name ?? 'Spring 2027' }} · Community Bible Church · Easley, SC</p></div>
@if($admin)<a class="btn ghost" href="{{ $pageUrl('settings') }}">Season settings</a>
@endif</header>

@if(session('status'))<div role="status" class="alert success">{{ session('status') }}</div>
@endif

@if($errors->any())<div role="alert" class="alert error">
@foreach($errors->all() as $error)<p>{{ $error }}</p>
@endforeach</div>
@endif

@if($admin && $blockers && in_array($section,['home','settings']))<div class="alert"><strong>Registration is closed until setup is verified.</strong><p class="muted">{{ implode(' ',array_unique($blockers)) }}</p></div>
@endif

@if(in_array($section,['home','teams']))
<div class="cards">
@foreach($capacity as $key=>$team)
@if($admin || in_array($key,json_decode($coach->team_keys,true)))
<a href="{{ $pageUrl('teams',['team'=>$key]) }}" class="card"><div class="team-name"><span class="dot" style="background:{{ $team['color'] }}"></span><h2>{{ $team['name'] }}</h2></div><p class="muted">Ages {{ $team['min_age'] }}{{ $team['max_age']?'–'.$team['max_age']:'+' }}</p><span class="number">{{ $team['public_used']+$team['reserved_used'] }}<small class="muted" style="font-size:18px;letter-spacing:0"> / {{ $team['public']+$team['reserved'] }}</small></span><div class="rail"><i style="width:{{ min(100,100*($team['public_used']+$team['reserved_used'])/max(1,$team['public']+$team['reserved'])) }}%;background:{{ $team['color'] }}"></i></div><p class="muted">{{ $team['public_free'] }} public spots available 
@if($admin)· {{ $team['reserved_free'] }} held in reserve
@endif</p></a>

@endif
@endforeach</div>

@endif

@if($section==='home')
<div class="section"><h2>This season</h2><div class="cards"><div><span class="number">{{ $registrations->where('status','registered')->count() }}</span><p class="muted">Registered players</p></div><div><span class="number">{{ $registrations->where('status','offered')->count() }}</span><p class="muted">Reserved, awaiting payment</p></div><div><span class="number">{{ $registrations->where('status','waitlisted')->count() }}</span><p class="muted">On the waitlist</p></div></div><a class="btn" href="{{ $pageUrl('players') }}">Open players & waitlist</a></div>

@if($admin)<div class="section"><h2>Recent changes</h2>
@forelse($events as $e)<div class="row"><div><strong>{{ ucwords(str_replace('_',' ',$e['action'])) }}</strong><p class="muted">{{ $e['data']['summary']??'' }}</p></div><small class="muted">{{ $e['created_at'] }}</small></div>
@empty<p class="empty">Season activity will appear here.</p>
@endforelse</div>
@endif

@endif

@if($section==='parents')<section class="section"><h2>{{ $families->count() }} families</h2>
@forelse($families as $f)<details><summary>{{ $f['profile']['parentName']??$f['email'] }} <small class="muted">{{ $f['email'] }}</small></summary><div class="form-grid"><div><p>{{ $f['profile']['phone']??'Phone missing' }}</p><p class="muted">{{ $f['profile']['address']??'' }}</p></div><div>
@foreach($players->where('family_id',$f['id']) as $p)<p>{{ $p['name'] }} <small class="muted">{{ $p['birthday'] }}</small></p>
@endforeach</div></div><p class="muted">{{ $f['verified_at']?'Email verified':'Awaiting account claim' }} · {{ $f['sms_consent_at']?'Soccer SMS consent recorded':'SMS consent not recorded' }}</p><form method="post" action="{{ $actionUrl('guardian') }}" class="form-grid">
@csrf<input type="hidden" name="family_id" value="{{ $f['id'] }}"><label>Verified additional / changed guardian email<input required type="email" name="email"></label><label>Evidence for household access<input name="reason" required></label><button>Link guardian email</button></form></details>
@empty<p class="empty">Import historical family records or receive the first registration.</p>
@endforelse</section>
@endif

@if(in_array($section,['players','teams']))
<section class="section"><div class="header"><h2>{{ $section==='teams' && request('team')?config('soccer.teams.'.request('team').'.name'):'Season players' }}</h2><form method="get"><input type="hidden" name="tenant" value="{{ $tenant->slug }}"><label>Season<select name="season" onchange="this.form.submit()">
@foreach($seasons as $s)<option value="{{ $s->id }}" 
@selected($season?->id===$s->id)>{{ $s->name }}</option>
@endforeach</select></label></form></div>
<div class="table-scroll"><table><thead><tr><th>Player</th><th>Team</th><th>Status</th><th>Jersey</th>
@if($admin)<th>Fee</th><th>Actions</th>
@endif</tr></thead><tbody>
@foreach($registrations as $reg)
@continue(request('team') && request('team')!==$reg->team_key)<tr><td><strong>{{ $players[$reg->player_id]['name']??'Player' }}</strong><p class="muted">{{ $families[$reg->family_id]['profile']['parentName']??'' }}</p><small>{{ $families[$reg->family_id]['profile']['phone']??'' }} · {{ $families[$reg->family_id]['email']??'' }}</small>
<details><summary>Contacts & player care</summary><p>Emergency: {{ $families[$reg->family_id]['profile']['emergencyContact']??'' }} · {{ $families[$reg->family_id]['profile']['emergencyPhone']??'' }}</p>
@foreach(['allergies'=>'Allergies','allergenList'=>'Allergen details','epipen'=>'EpiPen','conditions'=>'Conditions','restrictions'=>'Restrictions','comments'=>'Notes','medical_note'=>'Historical notes'] as $field=>$label)
@if($players[$reg->player_id]['profile'][$field]??false)<p><strong>{{ $label }}:</strong> {{ $players[$reg->player_id]['profile'][$field] }}</p>
@endif
@endforeach</details></td><td>{{ config('soccer.teams.'.$reg->team_key.'.name') }}</td><td><span class="pill {{ $reg->status }}">{{ ucfirst($reg->status) }}</span>
@if($reg->offer_expires_at)<p class="muted">Pay by {{ \Carbon\Carbon::parse($reg->offer_expires_at)->timezone('America/New_York')->format('M j, g:i A') }}</p>
@endif</td><td>{{ $reg->needs_jersey?($reg->jersey_size??'Size needed'):'Reusing jersey' }}</td>
@if($admin)<td>
@if($reg->release_id)<a class="btn ghost" href="{{ route('soccer.release-export',['id'=>$reg->release_id,'tenant'=>$tenant->slug]) }}">Release PDF</a>
@endif{{ $reg->waived?'Waived':'$'.number_format($reg->amount_cents/100,2) }}<p class="muted">{{ $reg->payment_status }}</p></td><td>
@if($reg->status==='waitlisted')<form method="post" action="{{ $actionUrl('offer') }}">
@csrf<input type="hidden" name="season_id" value="{{ $reg->season_id }}"><input type="hidden" name="family_id" value="{{ $reg->family_id }}"><input type="hidden" name="assignments[0][id]" value="{{ $reg->id }}"><select name="assignments[0][seat_kind]" aria-label="Seat pool"><option value="public">Public seat</option><option value="reserved">Reserved seat</option></select><button>Offer & invoice</button></form>
@if(!$reg->auto_eligible)<form method="post" action="{{ $actionUrl('reactivate') }}">
@csrf<input type="hidden" name="id" value="{{ $reg->id }}"><button class="ghost">Reactivate</button></form>
@endif
@endif 
@if(in_array($reg->status,['registered','waitlisted']))<details><summary>Override team</summary><form method="post" action="{{ $actionUrl('override-team') }}">
@csrf<input type="hidden" name="id" value="{{ $reg->id }}"><select name="team_key" aria-label="Destination team">
@foreach(config('soccer.teams') as $key=>$team)<option value="{{ $key }}">{{ $team['name'] }}</option>
@endforeach</select><label>Reason<input name="reason" required></label><button>Move player</button></form></details><form method="post" action="{{ $actionUrl('cancel') }}">
@csrf<input type="hidden" name="id" value="{{ $reg->id }}"><button class="ghost">Withdraw</button></form>
@endif</td>
@endif</tr>
@endforeach</tbody></table></div>
@if(!$registrations->count())<p class="empty">No registrations for this season yet.</p>
@endif</section>

@if($admin && $section==='players')<section class="section"><h2>Returning player directory</h2><p class="muted">Historical profiles stay available without enrolling anyone in Spring 2027.</p>
@foreach($players as $p)<details><summary>{{ $p['name'] }} · {{ $p['birthday']??'Birthday needs review' }}</summary><p>{{ $families[$p['family_id']]['profile']['parentName']??'' }} · {{ $families[$p['family_id']]['email']??'' }}</p>
@foreach($releases->where('player_id',$p['id']) as $release)<p><a href="{{ route('soccer.release-export',['id'=>$release->id,'tenant'=>$tenant->slug]) }}">{{ $release->season_key }} · signed {{ $release->signed_at }} · release PDF</a></p>
@endforeach</details>
@endforeach</section>
@endif
@if($admin)<section class="section"><h2>Family invoices</h2>
@forelse($invoices->where('season_id',$season?->id) as $i)<details><summary>{{ $families[$i->family_id]['profile']['parentName']??$families[$i->family_id]['email']??'Family' }} · ${{ number_format($i->amount_cents/100,2) }} · {{ ucfirst($i->status) }}</summary><p class="muted">Invoice {{ $i->id }} · Refunded ${{ number_format($i->refunded_cents/100,2) }}</p>
@if($i->status==='unpaid')<form method="post" action="{{ $actionUrl('withdraw-offer') }}" class="form-grid">
@csrf<input type="hidden" name="invoice_id" value="{{ $i->id }}"><label>Reason for withdrawing offer<input required name="reason"></label><button class="ghost">Withdraw unpaid family offer</button></form><form method="post" action="{{ $actionUrl('venmo') }}" class="form-grid">
@csrf<input type="hidden" name="invoice_id" value="{{ $i->id }}"><label>Verified Venmo transfer reference<input required name="reference"></label><label>Received amount in cents<input required type="number" name="amount_cents" value="{{ $i->amount_cents }}"></label><button>Confirm Venmo received</button></form>
@endif 
@foreach($payments->where('invoice_id',$i->id) as $payment)
@php($refundable = $payment->amount_cents - $refunds->where('invoice_id',$i->id)->filter(fn($refund)=>($refund['evidence']['payment_id']??null)===$payment->id && in_array($refund['status'],['pending','uncertain','succeeded']))->sum('amount_cents'))
<p><strong>{{ ucfirst($payment->provider) }} payment: ${{ number_format($payment->amount_cents/100,2) }}</strong> · refundable ${{ number_format($refundable/100,2) }}</p>
@if($refundable>0)<form method="post" action="{{ $actionUrl('refund') }}" class="form-grid">
@csrf<input type="hidden" name="invoice_id" value="{{ $i->id }}"><input type="hidden" name="payment_id" value="{{ $payment->id }}"><label>Refund amount in cents<input required type="number" min="1" max="{{ $refundable }}" name="amount_cents"></label><label>Reason<input required name="reason"></label>
@if($payment->provider==='venmo')<label><span><input type="checkbox" required name="venmo_completed" value="1"> I have completed this Venmo refund</span></label>
@endif<button>Refund / record completed refund</button><p class="muted">Refunding does not remove the player. Withdraw separately to release their seat.</p></form>
@endif
@endforeach
@foreach($refunds->where('invoice_id',$i->id) as $refund)<p class="muted">Refund ${{ number_format($refund['amount_cents']/100,2) }} · {{ $refund['status'] }} · {{ $refund['evidence']['reason']??'' }} · {{ $refund['created_at'] }}</p>
@endforeach</details>
@empty<p class="empty">Invoices are created only for offered seats.</p>
@endforelse</section>
@endif

@endif

@if($section==='coaches')<section class="section"><h2>{{ $admin?'Coaching team':'Your jersey size' }}</h2>
@foreach($coaches as $c)<div class="row"><div><strong>{{ $c->name }}</strong><p class="muted">{{ ucfirst($c->role) }} coach · {{ implode(', ',array_map(fn($k)=>config('soccer.teams.'.$k.'.name'),json_decode($c->team_keys,true))) }}</p><p>Jersey: {{ $c->jersey_size??'Size needed' }}</p></div>
@if($c->user_id===auth()->id())<form action="{{ $actionUrl('coach-size') }}" method="post" class="actions">
@csrf<label>Adult jersey size<select required name="jersey_size">
@foreach(['XS','S','M','L','XL','2XL','3XL'] as $size)<option 
@selected($c->jersey_size===$size)>{{ $size }}</option>
@endforeach</select></label><button>Save size</button></form>
@endif</div>
@endforeach</section>
@if($admin)<section class="section"><h2>Parent coaching volunteers</h2>
@foreach($families as $family)
@if(data_get($family, 'profile.coaching_by_season.'.$season?->season_key.'.volunteer'))<div class="row"><strong>{{ $family['profile']['parentName']??$family['email'] }}</strong><span>{{ $family['email'] }} · {{ $family['profile']['phone']??'' }} · Jersey {{ data_get($family, 'profile.coaching_by_season.'.$season?->season_key.'.jersey_size') }}</span></div>
@endif
@endforeach</section><section class="section"><h2>Assign a coach</h2><p class="muted"><a href="{{ route('admin.users',['tenant'=>$tenant->slug]) }}">Open Team Access</a> to invite their account to Wren first. Volunteering waives fees but does not grant backend access.</p><form method="post" action="{{ $actionUrl('coach') }}">
@csrf<div class="form-grid"><label>Name<input required name="name"></label><label>Workspace account email<input required type="email" name="email"></label><label>Coach role<select name="role"><option value="assistant">Assistant coach</option><option value="head">Head coach</option></select></label><fieldset><legend>Assigned teams</legend>
@foreach(config('soccer.teams') as $k=>$tm)<label><span><input type="checkbox" name="team_keys[]" value="{{ $k }}"> {{ $tm['name'] }}</span></label>
@endforeach</fieldset></div><button>Save coach assignment</button></form></section>
@endif
@endif

@if($section==='messages')<section class="section"><h2>GroupMe conversations</h2><p class="muted">Use your own GroupMe account to read and send messages. Group membership and your Wren role both control access.</p><details><summary>Connect your GroupMe account</summary><form method="post" action="{{ $actionUrl('groupme-connect') }}" class="form-grid">
@csrf<label>Personal GroupMe API token<input required type="password" name="token" autocomplete="off"></label><div><a href="https://dev.groupme.com/" target="_blank" rel="noopener noreferrer" class="muted">Get your token from GroupMe Developers</a><p class="muted">Stored encrypted; never shared with other coaches.</p></div><button>Connect account</button></form></details><div class="actions">
@forelse($groups as $key=>$gid)<button class="ghost" data-group="{{ $gid }}">{{ ucwords(str_replace('_',' ',$key)) }}</button>
@empty<p class="empty">Admin must map the existing GroupMe groups in Settings.</p>
@endforelse</div><div id="groupme-messages" aria-live="polite"></div><form method="post" action="{{ $actionUrl('groupme-send') }}" id="groupme-compose" hidden>
@csrf<input type="hidden" name="group_id" id="selected-group"><input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}"><label>Message<textarea required name="body" maxlength="1000" rows="3"></textarea></label><button style="margin-top:12px">Send to GroupMe</button></form></section>
@if($admin)<section class="section"><h2>Waitlist texts & notifications</h2>
@foreach($smsThreads as $thread)
<details><summary>{{ $thread->phone }} · {{ $thread->unread_count }} unread</summary>
@foreach($thread->messages as $message)
<div class="message"><strong>{{ $message->direction==='inbound'?'Reply':'Wren Soccer' }}</strong><p>{{ $message->body }}</p><small>{{ $message->delivery_status }} · {{ $message->received_at??$message->sent_at }}</small></div>
@endforeach
</details>
@endforeach
<p class="muted">Every waitlist change alerts Sarah at 678-644-1829. Family offers and reminders use SMS.</p>
@foreach($events as $e)<div class="message"><strong>{{ ucwords(str_replace('_',' ',$e['action'])) }}</strong><p>{{ $e['data']['summary']??'' }}</p><small>{{ $e['created_at'] }}</small></div>
@endforeach</section>
@endif
<script>document.querySelectorAll('[data-group]').forEach(button=>button.addEventListener('click',async()=>{const target=document.getElementById('groupme-messages');target.textContent='Loading…';try{const response=await fetch({!! json_encode(route('soccer.group-messages',['tenant'=>$tenant->slug])) !!}+'&group_id='+encodeURIComponent(button.dataset.group),{headers:{Accept:'application/json'}});const rows=await response.json();if(!response.ok)throw Error(rows.message||'GroupMe unavailable.');target.replaceChildren();rows.reverse().forEach(row=>{const card=document.createElement('article');card.className='message';const name=document.createElement('strong');name.textContent=row.name;const body=document.createElement('p');body.textContent=row.text||'Attachment';card.append(name,body);target.append(card)});document.getElementById('selected-group').value=button.dataset.group;document.getElementById('groupme-compose').hidden=false;}catch(error){target.textContent=error.message;}}));</script>

@endif

@if($section==='website')<div class="actions"><a class="btn ghost" href="https://easleyfamilysoccer.com" target="_blank" rel="noopener noreferrer">View live website</a>
@if($site)<a class="btn ghost" href="{{ $preview }}" target="_blank" rel="noopener noreferrer">Preview draft</a><form method="post" action="{{ $actionUrl('website-publish') }}">
@csrf<input type="hidden" name="version" value="{{ $site->draft_site_version_id }}"><button>Publish saved draft</button></form>
@else<form method="post" action="{{ $actionUrl('website-initialize') }}">
@csrf<button>Connect existing Wren theme</button></form>
@endif</div>
@if($site)<div class="editor"><form method="post" action="{{ $actionUrl('website-save') }}" class="section">
@csrf<input type="hidden" name="version" value="{{ $site->draft_site_version_id }}"><h2>Theme & content</h2><p class="muted">Save first. Your draft stays private until published.</p>
@foreach($fields as $key=>$label)<label style="margin-top:16px">{{ $label }}
@if(str_ends_with($key,'_color'))<input type="color" name="content[{{ $key }}]" value="{{ $content[$key] }}">
@else<textarea name="content[{{ $key }}]" rows="{{ strlen($content[$key])>100?4:2 }}" maxlength="8000">{{ $content[$key] }}</textarea>
@endif</label>
@endforeach<button style="margin-top:20px">Save draft</button></form><section class="section"><div class="actions"><button class="ghost" type="button" onclick="document.getElementById('site-preview').style.width='100%'">Desktop</button><button class="ghost" type="button" onclick="document.getElementById('site-preview').style.width='390px'">Phone</button></div><iframe id="site-preview" title="Wren website draft preview" src="{{ $preview }}" style="height:780px;width:100%;max-width:100%;border:0;margin-top:20px" referrerpolicy="no-referrer"></iframe><h2 style="margin-top:24px">Published history</h2>
@foreach($versions as $v)<form method="post" action="{{ $actionUrl('website-restore') }}" class="row">
@csrf<input type="hidden" name="version" value="{{ $site->draft_site_version_id }}"><input type="hidden" name="restore_version" value="{{ $v->id }}"><p>Version {{ $v->version_number }} · {{ $v->published_at?->format('M j') }}</p><button class="ghost">Restore as draft</button></form>
@endforeach</section></div>
@endif
@endif

@if($section==='settings' && $admin)<section class="section"><h2>Card account</h2><p class="muted">Wren owns its Stripe account. Complete identity and payout details in Stripe.</p><form method="post" action="{{ $actionUrl('card-connect') }}" class="form-grid">
@csrf<label>Account owner email<input type="email" name="email" required value="{{ auth()->user()->email }}"></label><button>Continue Stripe onboarding</button></form>
@if($cardAccount)<p>Status: {{ $cardAccount->status }}</p><form method="post" action="{{ $actionUrl('card-refresh') }}">
@csrf<button class="ghost">Check Stripe verification</button></form>
@endif</section><section class="section"><h2>Spring 2027 season</h2>
@if($season)<form method="post" action="{{ $actionUrl('season') }}">
@csrf<input type="hidden" name="id" value="{{ $season->id }}"><div class="form-grid"><label>First practice date<input type="date" name="starts_on" value="{{ $season->starts_on }}"></label><label>Age cutoff date<input type="date" name="age_as_of" value="{{ $season->age_as_of??$season->starts_on }}"></label><label>Scheduled opening · Eastern time<input type="datetime-local" name="registration_opens_at" value="{{ $season->registration_opens_at?\Carbon\CarbonImmutable::parse($season->registration_opens_at)->timezone('America/New_York')->format('Y-m-d\\TH:i'):'' }}"></label><label>Offer deadline (hours)<input type="number" name="offer_hours" min="1" max="336" value="{{ $season->offer_hours }}"></label></div>
@foreach($capacity as $k=>$tm)<div class="row"><strong>{{ $tm['name'] }}</strong><div class="actions"><label>Public spots<input type="number" name="teams[{{ $k }}][public]" value="{{ $tm['public'] }}" min="{{ $tm['public_used'] }}"></label><label>Admin reserve<input type="number" name="teams[{{ $k }}][reserved]" value="{{ $tm['reserved'] }}" min="{{ $tm['reserved_used'] }}"></label></div></div>
@endforeach<label style="margin:20px 0"><span><input type="checkbox" name="registration_open" value="1" 
@checked($season->registration_open)> Open registration after setup verification</span></label><button>Save season</button></form>
@endif</section><section class="section"><h2>GroupMe connections</h2><form method="post" action="{{ $actionUrl('settings') }}">
@csrf<div class="form-grid">
@foreach(['soccer'=>'Soccer','head_coaches'=>'Head Coaches','all_coaches'=>'All Coaches'] as $key=>$label)<label>{{ $label }} group ID<input name="{{ $key }}_group" value="{{ $settings['groupme_groups'][$key]??'' }}"></label>
@endforeach<label>Soccer group join link<input type="url" name="soccer_group_url" value="{{ $settings['soccer_group_url']??'' }}"></label></div><label><span><input type="checkbox" name="launch_verified" value="1" 
@checked($settings['launch_verified']??false)> I verified sign-in email, card payment/refund, Sarah/family SMS delivery, and the three GroupMe groups</span></label><button style="margin-top:20px">Save verified connections</button></form></section><section class="section"><h2>Import review · {{ $imports->count() }} unresolved rows</h2><p class="muted">Missing or conflicting identity data requires review before linking to a family.</p>
@foreach($imports as $i)<details><summary>{{ $i['source'] }} · row {{ $i['row_number'] }} · {{ $i['season_key'] }}</summary><dl>
@foreach($i['evidence']['row']??[] as $label=>$value)
@if($value)<div class="row"><dt class="muted">{{ $label }}</dt><dd>{{ $value }}</dd></div>
@endif
@endforeach</dl><form method="post" action="{{ $actionUrl('import-resolve') }}" class="form-grid">
@csrf<input type="hidden" name="id" value="{{ $i['id'] }}"><label>Confirmed family<select name="family_id">
@foreach($families as $f)<option value="{{ $f['id'] }}" @selected(($i['family_id']??null)===$f['id'])>{{ $f['profile']['parentName']??$f['email'] }} · {{ $f['email'] }}</option>
@endforeach</select></label><label>Confirmed player<select name="player_id">
@foreach($players as $p)<option value="{{ $p['id'] }}" @selected(($i['player_id']??null)===$p['id'])>{{ $p['name'] }} · {{ $p['birthday'] }}</option>
@endforeach</select></label><label>Evidence for this match<input required name="reason"></label><button>Link reviewed record</button></form><details><summary>Create a confirmed historical profile</summary><p class="muted">Use only after reviewing the original contacts and birthday. This creates no Spring 2027 enrollment or payment.</p><form method="post" action="{{ $actionUrl('import-create') }}" class="form-grid">
@csrf<input type="hidden" name="id" value="{{ $i['id'] }}"><label>Confirmed guardian email<input required type="email" name="email"></label><label>Guardian name<input required name="parentName"></label><label>Confirmed phone<input required name="phone"></label><label>Child’s full name<input required name="name"></label><label>Confirmed birthday<input required type="date" name="birthday"></label><label>Evidence for this identity<input required name="reason"></label><button>Create / link confirmed profile</button></form></details></details>
@endforeach</section><section class="section"><h2>Unlinked signed releases</h2>
@foreach($releases->whereNull('player_id') as $release)<details><summary>{{ $release->season_key }} · {{ $release->source_key }}</summary><p><a href="{{ route('soccer.release-export',['id'=>$release->id,'tenant'=>$tenant->slug]) }}">Review the original signed PDF</a></p><form method="post" action="{{ $actionUrl('release-link') }}" class="form-grid">
@csrf<input type="hidden" name="id" value="{{ $release->id }}"><label>Confirmed child<select name="player_id">
@foreach($players as $player)<option value="{{ $player['id'] }}">{{ $player['name'] }} · {{ $player['birthday'] }} · {{ $families[$player['family_id']]['email']??'' }}</option>
@endforeach</select></label><label>Evidence for this match<input required name="reason"></label><button>Link original signed release</button></form></details>
@endforeach</section>
@endif
</div></flux:main></x-layouts::app.sidebar>
