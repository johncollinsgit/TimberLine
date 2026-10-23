@if(($enterprisePresentation['runtime'] ?? 'private') === 'sample' && ($enterprisePresentation['locations']['catalog'] ?? '') === 'lees-mcrae-official-map-v1')
<style>
  :root{--green:#005c42;--gold:#cf9b2c}
  .sidebar{background:#005c42}
  .brand-mark{color:#cf9b2c;border-color:#cf9b2c}
  .nav-item.active{box-shadow:inset 3px 0 #cf9b2c}
  .button.gold{background:#cf9b2c;border-color:#cf9b2c;color:var(--ink)}
  .campus-job-marker>span{background:#005c42}
  .campus-job-marker.selected-job>span{background:#cf9b2c;color:var(--ink)}
</style>
@endif
