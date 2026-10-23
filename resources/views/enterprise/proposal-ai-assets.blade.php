@if($enterpriseSample && ($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true)
@include('enterprise.proposal-ai-styles')
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/live-ai.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/live-ai-client.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/live-ai-controls.js')) !!}</script>
@endif
