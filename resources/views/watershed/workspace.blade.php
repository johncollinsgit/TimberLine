<x-layouts::app.sidebar title="The Watershed"><flux:main><style>.ws{max-width:1160px;margin:auto;color:#173e3b;font-size:16px}.ws h1{font-size:34px;font-weight:650}.ws h2{font-size:23px;margin:20px 0}.ws h3{font-size:19px;font-weight:600}.ws section,.ws article{background:#fff;border:1px solid #d7e4df;border-radius:14px;padding:24px;margin:18px 0}.ws p{line-height:1.6}.ws button,.ws .button{display:inline-block;background:#173e3b;color:white;border-radius:8px;padding:10px 17px;margin:8px 8px 8px 0}.ws input,.ws select,.ws textarea{border:1px solid #94b2a6;border-radius:6px;padding:9px;max-width:100%}.ws .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.ws .quiet{color:#527268;font-size:14px}.ws summary{padding:12px;cursor:pointer}.ws label{display:block;margin:10px 0}.ws .notice{padding:15px;background:#edf4f2;border-radius:10px}</style><div class="ws">
@php($actionUrl=fn($a)=>route('watershed.action',['action'=>$a,'tenant'=>$tenant->slug]))
<h1>The Watershed</h1><p>{{ $term->name }} · School year {{ $term->school_year }}–{{ $term->school_year+1 }} · Placement cutoff September 1 · Through grade {{ $maximum }}</p>
<a href="{{ route('soccer.workspace',['tenant'=>$tenant->slug]) }}">Wren Soccer workspace</a> · <a href="https://watershedfellowship.com/register" target="_blank" rel="noopener">Parent registration</a>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>
@endif

@if($errors->any())<div role="alert">@foreach($errors->all() as $e)<p>{{ $e }}</p>
@endforeach
</div>
@endif

<section><h2>Registration</h2><p>{{ $term->registration_open?'Open for applications':'Closed for applications' }}. Leadership controls admissions; classes have no capacity limits.</p><form method="post" action="{{ $actionUrl('term') }}">@csrf<input type="hidden" name="term_id" value="{{ $term->id }}"><input type="hidden" name="open" value="{{ $term->registration_open?'0':'1' }}"><button>{{ $term->registration_open?'Close registration':'Open registration' }}</button></form><details><summary>Create the next semester</summary><form method="post" action="{{ $actionUrl('new-term') }}">@csrf<label>School year starting in <input type="number" name="year" min="2026" max="2040" value="{{ $term->school_year }}" required></label><label>Semester <select name="semester"><option value="fall">Fall</option><option value="spring">Spring of the following calendar year</option></select></label><button>Create closed semester</button></form></details></section>
<section><h2>Applications</h2><div class="grid">@foreach(['pending','needs_parent_review','accepted','registered','declined'] as $status)<p><b>{{ $applications->where('status',$status)->count() }}</b> {{ ucwords(str_replace('_',' ',$status)) }}</p>
@endforeach
</div>
@forelse($applications as $a)<article><h3>{{ $families[$a['family_id']]['name']??'Family' }}</h3><p>{{ $families[$a['family_id']]['email']??'' }} · {{ ucwords(str_replace('_',' ',$a['status'])) }} · ${{ number_format($a['amount_cents']/100,2) }}</p>
@foreach($a['data']['children'] as $child)<div class="notice" style="margin:12px 0"><b>{{ $child['name'] }}</b> · {{ $classes[$child['class']] }} · Grade {{ $child['grade']<0?'Preschool':($child['grade']===0?'K':$child['grade']) }}<p class="quiet">Placement: {{ $child['placement_status'] }} @if($child['exception'])· Suggested: {{ $classes[$child['suggested_class']] }}
@endif
</p>
@if($child['reason'])<p>Parent’s written reason: {{ $child['reason'] }}</p>
@endif

@if($a['status']==='pending'&&$child['placement_status']==='pending')<form method="post" action="{{ $actionUrl('approve-placement') }}">@csrf<input type="hidden" name="application_id" value="{{ $a['id'] }}"><input type="hidden" name="version" value="{{ $a['version'] }}"><input type="hidden" name="player_id" value="{{ $child['player_id'] }}"><label>Leadership note <textarea name="reason" maxlength="2000"></textarea></label><button>Approve requested placement</button><button formaction="{{ $actionUrl('decline-placement') }}">Return suggested placement to parent</button></form>
@endif

@foreach($a['releases']->where('player_id',$child['player_id']) as $release)<p><a href="{{ route('watershed.release',['id'=>$release->id,'tenant'=>$tenant->slug]) }}">Signed release · {{ $release->signed_at }}</a></p>
@endforeach
</div>
@endforeach

@if($a['status']==='pending')<form method="post" action="{{ $actionUrl('accept') }}">@csrf<input type="hidden" name="application_id" value="{{ $a['id'] }}"><input type="hidden" name="version" value="{{ $a['version'] }}"><label>Leadership note (visible to parent) <textarea name="reason" maxlength="2000"></textarea></label><button>Accept family</button><button formaction="{{ $actionUrl('decline') }}">Decline family</button></form>
@endif
</article>
@empty
<p>No applications yet.</p>
@endforelse
</section>
<section><h2>Class rosters</h2><div class="grid">@foreach($classes as $key=>$name)<div><h3>{{ $name }}</h3>@foreach($applications->whereIn('status',['accepted','registered']) as $a)@foreach($a['data']['children'] as $c)@if($c['class']===$key)<p>{{ $c['name'] }}<span class="quiet"> · {{ $a['status']==='registered'?'Complete':'Awaiting payment' }}</span></p>
@endif

@endforeach

@endforeach
</div>
@endforeach
</div></section>
<section><h2>Family preapproval</h2><p>Preapproval applies to future complete applications. Accepting one semester does not automatically preapprove future semesters.</p>@foreach($imports as $import)<div class="notice"><p>Roster match needed: {{ $import['name'] }}</p><form method="post" action="{{ $actionUrl('preapprove') }}">@csrf<input type="hidden" name="import_id" value="{{ $import['id'] }}"><input type="hidden" name="preapproved" value="1"><select name="family_id" required><option value="">Choose verified family</option>@foreach($families as $f)<option value="{{ $f['id'] }}">{{ $f['name'] }} · {{ $f['email'] }}</option>
@endforeach
</select><button>Confirm match and preapprove</button></form></div>
@endforeach

<details><summary>Manage family preapprovals</summary>@foreach($families as $f)<form method="post" action="{{ $actionUrl('preapprove') }}">@csrf<input type="hidden" name="family_id" value="{{ $f['id'] }}"><input type="hidden" name="preapproved" value="{{ $f['preapproved']?'0':'1' }}"><p>{{ $f['name'] }} · {{ $f['email'] }} <button>{{ $f['preapproved']?'Remove preapproval':'Preapprove family' }}</button></p></form>
@endforeach
</details></section>
<section><h2>Email delivery</h2>@foreach($deliveries as $state=>$count)<p>{{ ucfirst($state) }}: {{ $count }}</p>
@endforeach
<p class="quiet">Uncertain deliveries require provider review; they are never resent automatically.</p></section></div></flux:main></x-layouts::app.sidebar>
