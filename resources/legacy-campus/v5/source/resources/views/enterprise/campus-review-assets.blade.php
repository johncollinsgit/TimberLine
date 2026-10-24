<style>{!! file_get_contents(resource_path('js/enterprise/campus-review.css')) !!}</style>
@include('service-proposals.campus-review')
<script>globalThis.EverbranchCampusReviewBoot = @json([...$campusReviewContext, 'layout' => $campusReviewLayout]);</script>
<script>{!! file_get_contents(resource_path('js/enterprise/campus-review.js')) !!}</script>
@include('service-proposals.activity-bridge')
