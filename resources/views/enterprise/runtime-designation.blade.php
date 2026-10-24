@if(config('enterprise.runtime') === true && config('enterprise.sample_data') === true && !($enterpriseSample ?? false))
<div class="enterprise-runtime-designation" role="note" aria-label="Persistent demonstration"><strong>Persistent demonstration workspace</strong> · Fictional organization records. External integrations are not connected.</div>
<style>.enterprise-runtime-designation{box-sizing:border-box;width:100%;padding:12px 20px;background:#f1ead0;color:#403b21;border-bottom:1px solid #d6c995;font:13px/1.6 system-ui,sans-serif;overflow-wrap:anywhere}.enterprise-runtime-designation strong{font-weight:700}</style>
@endif
