<section class="md-welcome">
    <div class="md-welcome-copy"><span class="md-eyebrow">A little focus. A lot more possibility.</span><h2>Make something worth<br class="md-desktop-break"> sharing.</h2><p>Plan a post with Bud, choose a photo, and approve it when it feels right. Come back to see what people loved and reply to the conversations that matter.</p><div class="md-actions"><a class="eb-primary-button" href="{{ route('marketing.desk.index',['view'=>'calendar']) }}">Create a post <span aria-hidden="true">＋</span></a><a class="md-text-link" href="{{ route('marketing.desk.index',['view'=>'insights']) }}">See what’s working →</a></div><div class="md-purpose"><span>01 <strong>Plan & create</strong></span><span>02 <strong>Review & approve</strong></span><span>03 <strong>Learn & reply</strong></span></div></div>
    <div class="md-photo-wall" aria-label="Your content photos">
        @forelse($photoPosts as $photoPost)
        <a class="md-photo-tile" href="{{ route('marketing.desk.index',['view'=>($photoPost->content['source'] ?? '')==='instagram_import' ? 'insights' : 'calendar']) }}"><img src="{{ $photoPost->content['thumbnail_url'] ?? $photoPost->content['media_urls'][0] }}" alt="{{ Str::limit($photoPost->content['caption'],100) }}" referrerpolicy="no-referrer"><span>{{ $photoPost->status==='draft' ? 'Your next post · Draft' : ($photoPost->status==='published' ? 'From your published posts' : 'Coming up') }}</span></a>
        @empty
        <a class="md-photo-prompt" href="{{ route('marketing.desk.index',['view'=>'calendar']) }}"><span aria-hidden="true">＋</span><strong>Put your photos to work</strong><p>Add a product photo, a moment behind the scenes, or the people who make your business special.</p><small>Choose photos in a new post →</small></a>
        @endforelse
    </div>
</section>
