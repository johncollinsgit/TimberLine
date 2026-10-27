  {!! $legacyAssets->bytes('resources/js/enterprise/detail-placement.js') !!}
  let workDetailStorage=null;
  try {workDetailStorage=localStorage;} catch {}
  const workDetailPreference=EverbranchDetailPlacement.create(workDetailStorage,EverbranchDetailPlacement.preferenceKey(transport.mode,workspace,viewer,EverbranchDetailPlacement.runtimeBoundary(location.pathname)));
  function setWorkDetailPlacement(value) {
    if(!EverbranchDetailPlacement.valid(value))return;
    // Change only layout attributes. The native dialog, draft text, selected files,
    // permission generation and any in-flight upload keep their original identity.
    $('work-drawer').dataset.placement=value;
    $('work-drawer').querySelectorAll('[data-detail-placement]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.detailPlacement===value)));
  }
  setWorkDetailPlacement(workDetailPreference.read());
  $('work-drawer').querySelectorAll('[data-detail-placement]').forEach(button=>button.onclick=()=>{
    const value=button.dataset.detailPlacement;
    if(workDetailPreference.save(value))setWorkDetailPlacement(value);
  });
