<style>{!! $legacyAssets->bytes('resources/js/enterprise/campus-review.css') !!}</style>
@include('campus-v5::service-proposals.campus-review')
<script>globalThis.EverbranchCampusReviewBoot = @json([...$campusReviewContext, 'layout' => $campusReviewLayout]);</script>
<script>{!! $legacyAssets->bytes('resources/js/enterprise/campus-review.js') !!}</script>
@include('campus-v5::service-proposals.activity-bridge')
