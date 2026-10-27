@if(isset($version, $token) && $version && app(\App\Proposals\Activity::class)->enabled($version))
<p class="muted">Review activity, including pages opened and tour progress, is shared with Evergrove Software.</p>
<div hidden data-review-activity data-endpoint="{{ route('service-proposals.recipient.activity', $token) }}" data-version-hash="{{ $version->content_hash }}" data-csrf="{{ csrf_token() }}" data-mode="proposal" data-page="overview"></div>
<script data-review-activity-script>{!! file_get_contents(resource_path('js/service-proposals/activity.js')) !!}</script>
@elseif(isset($campusReviewContext))
<div hidden data-review-activity data-endpoint="{{ $campusReviewContext['activity_url'] }}" data-version-hash="{{ $campusReviewContext['version_hash'] }}" data-csrf="{{ $campusReviewContext['csrf_token'] }}" data-mode="campus" data-page="{{ $campusReviewContext['page'] }}"></div>
<script data-review-activity-script>{!! file_get_contents(resource_path('js/service-proposals/activity.js')) !!}</script>
@endif
