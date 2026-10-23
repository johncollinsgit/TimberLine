<button class="button" type="button" id="review-open" data-campus-review-open>Review this page</button>
<dialog id="review-dialog" class="panel" aria-labelledby="campus-review-title" data-campus-review>
    <button class="button" type="button" id="review-close" data-campus-review-close>Close review</button>
    <h2 id="campus-review-title">Review this campus page</h2>
    <p>Fictional demonstration. Shared feedback is saved to this exact proposal version and campus build. Display names are self-selected, not verified identities, accounts, signatures or campus permissions.</p>
    <p><a class="button" id="review-proposal" href="{{ $campusReviewContext['urls']['proposal'] }}" data-campus-proposal>Open proposal</a></p>
    <label for="campus-review-page">Page being reviewed</label>
    <select id="campus-review-page" data-campus-review-page>
        @foreach($campusReviewContext['pages'] as $page)
            <option value="{{ $page['id'] }}" @selected($page['id'] === $campusReviewContext['page'])>{{ $page['title'] }}</option>
        @endforeach
    </select>
    <nav aria-label="Reviewable campus pages" data-campus-review-page-links>
        @foreach($campusReviewContext['pages'] as $page)
            <a href="{{ $campusReviewContext['urls']['pages'][$page['id']] }}" @if($page['id'] === $campusReviewContext['page']) aria-current="page" @endif>{{ $page['title'] }}</a>
        @endforeach
    </nav>
    <form id="review-name-form" method="post" action="{{ $campusReviewContext['urls']['name'] }}" data-campus-review-name>
        @csrf
        <input type="hidden" name="version_hash" value="{{ $campusReviewContext['version_hash'] }}">
        <input type="hidden" name="build_id" value="{{ $campusReviewContext['build_id'] }}">
        <input type="hidden" name="source_hash" value="{{ $campusReviewContext['source_hash'] }}">
        <label for="campus-review-name">Your review display name</label>
        <select id="campus-review-name" name="reviewer_name" required>
            <option value="">Choose your name</option>
            @foreach($campusReviewContext['reviewer_names'] as $name)
                <option @selected($name === $campusReviewContext['reviewer_name'])>{{ $name }}</option>
            @endforeach
        </select>
        <button class="button" type="submit">Remember my display name</button>
    </form>
    <p id="review-status" data-campus-review-status role="status" aria-live="polite"></p>
    <section id="review-comments" data-campus-review-comments aria-label="Shared comments on this page">
        @forelse($campusReviewContext['comments'] as $comment)
            <article class="panel"><strong>{{ $comment['name'] }}</strong><p style="white-space:pre-wrap">{{ $comment['body'] }}</p><small>{{ $comment['created_at'] }}</small></article>
        @empty
            <p>No shared comments on this page and build yet.</p>
        @endforelse
    </section>
    <p data-campus-review-coverage>Checked at most 2,000 comments for this version; showing up to 200 matching this page and build. @if($campusReviewContext['coverage']['partial'])Earlier matching feedback may be outside this bounded view.@endif</p>
    <button class="button" id="review-refresh" type="button" data-campus-review-refresh>Refresh shared comments</button>
    <form id="review-comment-form" method="post" action="{{ $campusReviewContext['urls']['comment'] }}" data-campus-review-comment>
        @csrf
        <input type="hidden" name="version_hash" value="{{ $campusReviewContext['version_hash'] }}">
        <input type="hidden" name="build_id" value="{{ $campusReviewContext['build_id'] }}">
        <input type="hidden" name="source_hash" value="{{ $campusReviewContext['source_hash'] }}">
        <input type="hidden" name="comment_key" value="{{ $campusReviewContext['comment_key'] }}">
        <input type="hidden" name="expected_reviewer_name" value="{{ $campusReviewContext['reviewer_name'] }}">
        <p>Commenting as <strong data-campus-review-display-name>{{ $campusReviewContext['reviewer_name'] ?? 'Choose a display name first' }}</strong></p>
        <label for="review-body">Shared feedback for this page</label>
        <textarea id="review-body" name="body" maxlength="4000" rows="4" required @disabled(!$campusReviewContext['writes_enabled'] || !$campusReviewContext['reviewer_name'])></textarea>
        <button class="button primary" type="submit" @disabled(!$campusReviewContext['writes_enabled'] || !$campusReviewContext['reviewer_name'])>Save shared comment</button>
        @unless($campusReviewContext['writes_enabled'])<p>Review comments are paused. Existing feedback remains readable.</p>@endunless
    </form>
    <p class="caption">Build {{ $campusReviewContext['build_id'] }} · Proposal {{ substr($campusReviewContext['version_hash'], 0, 12) }} · Source {{ substr($campusReviewContext['source_hash'], 0, 12) }}. Review does not approve an agreement, payment, campus access or production change.</p>
</dialog>
