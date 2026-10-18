@if($posts->where('status','draft')->count()>1)
<details class="md-post"><summary><strong>Review and approve several posts</strong><small>Choose a time and accounts for each draft</small></summary>
<form class="md-form" method="POST" action="{{ route('marketing.desk.posts.batch-approve') }}">@csrf
@foreach($posts->where('status','draft')->take(20)->values() as $index=>$draft)
<div class="md-post md-form md-inline"><input type="hidden" name="posts[{{ $index }}][id]" value="{{ $draft->id }}"><input type="hidden" name="posts[{{ $index }}][revision]" value="{{ $draft->revision }}">
<label class="md-check"><input type="checkbox" name="posts[{{ $index }}][selected]" value="1"><span>{{ $draft->content['caption'] }}<small> · draft version {{ $draft->revision }}</small></span></label>
<label>Publish at ({{ config('marketing_desk.timezone') }})<input type="datetime-local" name="posts[{{ $index }}][scheduled_at]"></label>
@foreach(['facebook'=>'Facebook','instagram'=>'Instagram'] as $platform=>$label)<label>{{ $label }}<select name="posts[{{ $index }}][connections][{{ $platform }}]"><option value="">Do not publish here</option>@foreach($connections->where('provider',$platform)->where('status','connected') as $connection)<option value="{{ $connection->id }}">{{ $connection->external_account_label }}</option>@endforeach</select></label>@endforeach
</div>
@endforeach
<label class="md-check"><input type="checkbox" name="confirmed" value="1" required>I reviewed the selected drafts, media previews, accounts, and publication times.</label><button class="eb-primary-button">Approve selected posts</button>
</form></details>
@endif
