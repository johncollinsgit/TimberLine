@if(!empty($enterpriseInstall))
<style>
.eai-trigger{min-height:44px;border:1px solid #cbd6bf;border-radius:6px;background:#fff;color:#244d3d;padding:9px 13px;font:12px system-ui,sans-serif;white-space:nowrap}.eai-dialog{width:min(580px,calc(100vw - 24px));max-height:calc(100dvh - 24px);overflow:auto;border:1px solid #cad5bf;border-radius:12px;padding:26px;color:#244d3d;background:#f9fbf5;font:14px/1.7 system-ui,sans-serif}.eai-dialog::backdrop{background:#122a24a8}.eai-dialog h2{font:30px/1.2 Georgia,serif;margin:0 0 16px}.eai-dialog p{color:#52664b}.eai-dialog button{min-height:44px;padding:10px 14px;border:1px solid #becdb0;border-radius:5px;background:white;color:#244d3d;font:13px system-ui,sans-serif;cursor:pointer}.eai-dialog .eai-actions{display:flex;gap:8px;flex-wrap:wrap}.eai-dialog label{display:flex;gap:10px;align-items:flex-start;margin:14px 0}.eai-dialog input[type=checkbox]{width:22px;min-height:22px;flex:0 0 22px;margin-top:4px}.eai-dialog :focus-visible,.eai-trigger:focus-visible{outline:3px solid #a17d2c;outline-offset:3px}.eai-dialog button:disabled{opacity:.55;cursor:not-allowed}.eai-workflow-strip{padding:8px 20px;display:flex;justify-content:flex-end;background:#f8faf4;border-bottom:1px solid #dce4d4}@media(max-width:650px){.eai-dialog{padding:21px}.eai-dialog h2{font-size:26px}.topbar{flex-wrap:wrap}}
</style>
<button type="button" class="eai-trigger" id="enterprise-install-open">App &amp; updates</button>
<dialog class="eai-dialog" id="enterprise-install-dialog" aria-labelledby="enterprise-install-title">
 <h2 id="enterprise-install-title" tabindex="-1">Your work, on your device.</h2>
 <p>Open this Enterprise workspace from your home screen. Sign-in and department permissions still apply to every task, note and private photo.</p>
 <p id="enterprise-install-mode"></p>
 <p><strong>iPhone or iPad:</strong> open the approved workspace in Safari, tap Share, then Add to Home Screen and choose Open as Web App when offered. On Android or desktop, use the browser's Install app option when available.</p>
 <p>Installation is optional. This version requires a connection; it does not provide offline sync, background GPS or push notifications. Photo uploads use the existing private file picker and scanning rules.</p>
 <div class="eai-actions"><button type="button" id="enterprise-install-device" hidden>Install on this device</button><button type="button" id="enterprise-update-check">Check for update</button></div>
 <p role="status" aria-live="polite" id="enterprise-update-status">Check the current server when ready. The app never reloads your work automatically.</p>
 <section id="enterprise-update-preview" hidden>
  <p><strong>Before refreshing:</strong> finish saves/uploads and resolve retained workspace or planning actions using their exact retry. Refresh is blocked while those actions remain in this page. Other stored drafts and receipts are retained where browser storage is available; not every unsaved field is stored. Unsent file selections cannot be restored automatically: keep the original photo/file to select again. Existing unsaved-work warnings still apply.</p>
  <label><input id="enterprise-update-confirm" type="checkbox">I reviewed my work and am ready to refresh this page.</label>
  <button type="button" id="enterprise-update-reload" disabled>Refresh to current version</button>
 </section>
 <div class="eai-actions"><button type="button" id="enterprise-install-close">Return to work</button></div>
</dialog>
<script>
(() => {
    const boot = @json($enterpriseInstall), get = id => document.getElementById('enterprise-' + id), dialog = get('install-dialog'), open = get('install-open');
    let installation = null, checking = false;
    const writes = () => globalThis.EverbranchTransport?.pendingWrites || 0;
    const blocked = () => globalThis.EverbranchTransport?.refreshBlockers || 0;
    const lock = () => { get('update-reload').disabled = !get('update-confirm').checked || writes() > 0 || blocked() > 0 || checking; };
    const destination = value => { const url = new URL(value, location.href); if (url.origin !== location.origin || url.username || url.password) throw new Error('The current application update route is unavailable.'); return url; };
    function close() { if(dialog.open)dialog.close();open.focus(); }
    open.onclick = () => { dialog.showModal();get('install-title').focus();lock(); };
    get('install-close').onclick = close;
    dialog.addEventListener('cancel', event => {event.preventDefault();close();});
    dialog.addEventListener('keydown', event => {if(event.key !== 'Tab')return;const items=[...dialog.querySelectorAll('button:not(:disabled),input:not(:disabled)')].filter(e=>e.getClientRects().length),first=items[0],last=items.at(-1);if(event.shiftKey&&(document.activeElement===first||document.activeElement===get('install-title'))){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}});
    get('install-mode').textContent=matchMedia('(display-mode: standalone)').matches||navigator.standalone===true?'Standalone window detected. Hosted sign-in, uploads and updates still need acceptance on your actual device.':'Browser view. No device installation has been performed by this setup.';
    addEventListener('beforeinstallprompt', event => {event.preventDefault();installation=event;get('install-device').hidden=false;});
    get('install-device').onclick=async()=>{if(!installation)return;const prompt=installation;installation=null;get('install-device').hidden=true;await prompt.prompt();const choice=await prompt.userChoice;get('update-status').textContent=choice.outcome==='accepted'?'The browser accepted your installation choice. Open the home-screen app and verify sign-in on this device.':'Installation was dismissed. Continue using the browser.';};
    addEventListener('appinstalled',()=>{get('install-device').hidden=true;installation=null;get('update-status').textContent='The browser reported installation. Verify the home-screen launch and permissions on this device.';});
    get('update-check').onclick=async()=>{
        if(checking)return;checking=true;get('update-check').disabled=true;lock();get('update-status').textContent='Checking the server version. Your work remains open.';
        try{const response=await fetch(destination(boot.version_url),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok)throw new Error('The server could not verify the current app version.');const current=await response.json();if(typeof current.version!=='string'||!current.version)throw new Error('The app version is unavailable.');get('update-status').textContent=current.version===boot.version?'This page matches the reported version. You may still refresh after reviewing your work.':'An updated server version is available. Review the refresh details before continuing.';get('update-preview').hidden=false;get('update-confirm').checked=false;}
        catch(error){get('update-preview').hidden=true;get('update-status').textContent=error.message+' No refresh was performed.';}
        finally{checking=false;get('update-check').disabled=false;lock();}
    };
    get('update-confirm').onchange=lock;
    document.addEventListener('enterprise:network-activity',()=>{if(writes()>0||blocked()>0)get('update-confirm').checked=false;lock();if(dialog.open)get('update-status').textContent=writes()>0?'A save or upload is in progress. Refresh is paused until it finishes; review any uncertain outcome before continuing.':blocked()>0?'Refresh is paused. Finish the upload or resolve the retained workspace or planning action using its exact retry before continuing.':'The request has settled. Check its recorded result or exact retry, then confirm again when ready to refresh.';});
    addEventListener('beforeunload',event=>{if(writes()||blocked()){event.preventDefault();event.returnValue='';}});
    get('update-reload').onclick=()=>{if(writes()||blocked()||checking||!get('update-confirm').checked)return;location.reload();};
    const header=document.querySelector('.topbar');if(header)header.append(open);else{const strip=document.createElement('div');strip.className='eai-workflow-strip';strip.append(open);document.getElementById('enterprise-workflow')?.before(strip);}
})();
</script>
@endif
