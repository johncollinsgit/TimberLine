<article class="md-result-card">
    <div class="md-result-photo">@if($card['photo'])<img src="{{ $card['photo'] }}" alt="{{ Str::limit($card['caption'],100) ?: 'Instagram post photo' }}" loading="lazy" referrerpolicy="no-referrer">@else<span>{{ ucfirst($card['format']) }} post</span>@endif<span class="md-photo-label">{{ ucfirst($card['publication']->platform) }} · {{ $card['format']==='video' ? 'Video / Reel' : ucfirst($card['format']) }}</span></div>
    <div class="md-result-body"><div class="md-result-score"><span>{{ \App\Services\Marketing\Desk\SocialResultsService::METRICS[$card['metric']] }}</span><strong>{{ $card['score'] !== null ? number_format($card['score']) : '—' }}</strong><small>{{ $card['score'] !== null ? 'Lifetime total' : 'Not available yet' }}</small></div><h3>{{ Str::limit($card['caption'], ($compact ?? false) ? 100 : 180) ?: 'Post without a caption' }}</h3>
    @if($card['published_at'])<p class="md-muted">Published {{ \Carbon\CarbonImmutable::parse($card['published_at'])->timezone(config('marketing_desk.timezone'))->format('M j, Y') }}</p>@endif
    @unless($compact ?? false)
    <dl class="md-mini-metrics">@foreach(($card['publication']->platform==='instagram' ? ['reach'=>'Reach','likes'=>'Likes','comments'=>'Comments','saved'=>'Saves','shares'=>'Shares'] : ['post_impressions_unique'=>'Reach','post_clicks'=>'Clicks']) as $metric=>$label)<div><dt>{{ $label }}</dt><dd>{{ isset($card['metrics'][$metric]) && is_numeric($card['metrics'][$metric]) ? number_format($card['metrics'][$metric]) : '—' }}</dd></div>@endforeach</dl>
    <p class="md-freshness">@if($card['synced_at'])Post synced {{ \Carbon\CarbonImmutable::parse($card['synced_at'])->diffForHumans() }}.<br>@endif{{ $card['publication']->observed_at ? 'Detailed metrics checked '.$card['publication']->observed_at->diffForHumans().'.' : 'Detailed metrics have not been fetched.' }}</p>
    @endunless
    <div class="md-actions">@if($card['permalink'])<a href="{{ $card['permalink'] }}" target="_blank" rel="noopener noreferrer">View original ↗</a>@endif
    @unless($compact ?? false)<form method="POST" action="{{ route('marketing.desk.insights',$card['publication']->id) }}">@csrf<button class="md-text-button">Refresh metrics</button></form>@endunless</div>
    @unless($compact ?? false)<form method="POST" action="{{ route('marketing.desk.posts.copy-caption',$card['post']->id) }}">@csrf<button class="eb-secondary-button">Use caption in a new draft</button><small class="md-caption-note">Add your photo and review before scheduling.</small></form>@endunless
    </div>
</article>
