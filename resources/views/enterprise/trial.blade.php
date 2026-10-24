<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Controlled reviewer trial · Everbranch Enterprise</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f5ef;color:#243e32;font:15px/1.6 system-ui,sans-serif}header{background:#203f32;color:#fff;padding:25px max(20px,calc((100vw - 1120px)/2))}header strong{font:27px Georgia,serif}header small{display:block;color:#d1dcbf;letter-spacing:.1em;text-transform:uppercase;font-size:10px}main{max-width:1160px;padding:30px 20px;margin:auto}h1,h2{font-family:Georgia,serif;font-weight:400;line-height:1.2}h1{font-size:36px}h2{font-size:25px}.panel{padding:24px;background:#fff;border:1px solid #d9e1d0;border-radius:8px;margin-bottom:20px}.grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px}.muted{color:#65765e;font-size:13px}.label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#61764c}label{display:block;margin:12px 0;font-size:13px}input,select,textarea,button{font:inherit}input:not([type=checkbox]),select{display:block;width:100%;padding:12px;border:1px solid #bac9af;border-radius:5px;margin-top:5px}button,.button{min-height:44px;padding:11px 16px;border:1px solid #b9c8ad;border-radius:5px;background:#fff;color:#254b38;cursor:pointer}button.primary{background:#2e583f;color:#fff;border-color:#2e583f}button:disabled{opacity:.55;cursor:not-allowed}.actions{display:flex;gap:10px;flex-wrap:wrap}.fields{display:grid;grid-template-columns:1fr 1fr;gap:4px 12px}.fields label{margin:3px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:13px/1.7 ui-monospace,monospace;background:#f6f8f2;padding:15px;border-radius:5px}iframe{width:100%;height:500px;border:1px solid #d9e1d0}#status{min-height:24px;overflow-wrap:anywhere}#status.error{color:#963d30}.record{padding:13px 0;border-bottom:1px solid #e1e7d9;overflow-wrap:anywhere}.record strong{display:block}.budget{font-size:22px}.budget small{font:12px system-ui;display:block}.badge{display:inline-block;padding:4px 8px;border:1px solid #bacaab;border-radius:4px;font-size:11px}button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #b18b2c;outline-offset:3px}[hidden]{display:none!important}@media(max-width:760px){.grid{grid-template-columns:1fr}main{padding:20px 14px}.panel{padding:19px}h1{font-size:30px}.fields{grid-template-columns:1fr}}
</style></head><body><header><strong>Everbranch</strong><small>Enterprise · Controlled reviewer trial</small></header><main>
<p class="label">Approved sessions · Fictional work records</p><h1>Try a connected campus handoff.</h1><p>Preview an approved test message or a read-only AI assessment before confirming it. No mailbox connection or employee account is requested.</p>
<p id="status" role="status" aria-live="polite"></p>
<section class="panel" id="invitation"><h2>Open your reviewer session</h2><p class="muted">Use the invitation code approved for you. Receiving email addresses and SMS numbers are separately approved. Never put your code or contact details in the address bar.</p><form id="start-form"><label>Reviewer invitation code<input id="reviewer-code" type="password" autocomplete="off" minlength="16" maxlength="200" required></label><div class="actions"><button class="primary" id="start-button">Open approved trial</button><button type="button" id="check-session">Check current session</button></div></form></section>
<div id="session" hidden><section class="panel"><div class="label">One shared lifetime allowance</div><p class="budget" id="budget"></p><p class="muted" id="session-expiry"></p><button type="button" id="refresh">Refresh status</button></section>
<div class="grid"><section class="panel"><h2>Choose the exact test</h2><p class="muted">New previews do not call providers. Confirmation can incur provider cost within the single shared $5 lifetime cap, including acceptance checks.</p><form id="preview-form"><label>Channel<select id="channel"><option value="sms">SMS to an approved test number</option><option value="email">Email from the Everbranch service sender</option><option value="ai">Ask Everbranch · fictional fields only</option></select></label><label id="destination-label">Receiving destination<input id="destination" type="text" maxlength="254" list="destinations" autocomplete="off" required><datalist id="destinations"></datalist></label><p class="muted" id="destination-help"></p><fieldset><legend>Fictional fields included in the preview</legend><div class="fields" id="fields"></div></fieldset><button class="primary" id="preview-button">Prepare exact preview</button><button type="button" id="retry-preview" hidden>Retry the same preview</button></form><h2>Shared sample work</h2><div id="tasks"></div></section>
<section class="panel" id="preview-panel"><h2>Review before confirming</h2><p class="muted">The exact destination, content, selected fields, maximum reservation and expiry appear here.</p><div id="preview-details"></div><iframe id="email-html" title="Exact sample email preview" sandbox="" referrerpolicy="no-referrer" hidden></iframe><div class="actions"><button class="primary" id="confirm" type="button" hidden>Confirm this exact test</button><button id="retry-confirm" type="button" hidden>Check the same interrupted confirmation</button><button id="revoke" type="button" hidden>Revoke this sample task link</button></div><p class="muted">Provider acceptance, delivery, link opening and acknowledgment are separate events. An uncertain outcome is held for reconciliation and must not be replaced by another send.</p></section></div>
<section class="panel"><h2>This session’s test history</h2><p class="muted">Refresh to check existing attempts. Refreshing does not send messages or call AI.</p><div id="history"></div></section></div>
</main><script>
(() => {
    'use strict';
    const boot = @json($boot), $ = id => document.getElementById(id);
    let state = null, preview = null, pendingPreview = null, pendingConfirm = null, busy = false;
    const labels = {title:'Title',status:'Status',owner:'Accountable owner',next_action:'Next action',due_on:'Due date',blocker:'Blocker'};
    const money = value => '$' + (Number(value || 0) / 1000000).toFixed(4);
    function message(text, error = false) {$('status').textContent = text; $('status').classList.toggle('error', error);}
    function key() {return 'everbranch-controlled-trial:' + state.session_id;}
    function save() {if(state)try{sessionStorage.setItem(key(),JSON.stringify({pendingPreview,pendingConfirm}));}catch{}}
    function restore() {try{const saved=JSON.parse(sessionStorage.getItem(key())||'{}');pendingPreview=saved.pendingPreview||null;pendingConfirm=saved.pendingConfirm||null;}catch{pendingPreview=null;pendingConfirm=null;}}
    async function api(path, body) {
        const response = await fetch(boot.base + path, {credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body===undefined?{}:{method:'POST',body:JSON.stringify(body)})});
        const out = await response.json().catch(()=>({message:'The server did not confirm this request. Refresh this page if the browser session expired.'}));
        if(!response.ok){const error=new Error(out.errors?Object.values(out.errors).flat().join(' '):out.message||'This trial action is unavailable.');error.status=response.status;throw error;}
        return out;
    }
    function lock() {
        $('start-button').disabled=busy;$('check-session').disabled=busy;$('refresh').disabled=busy;
        for(const node of $('preview-form').elements)node.disabled=busy||Boolean(pendingConfirm)||(Boolean(pendingPreview)&&node.id!=='retry-preview');
        $('retry-preview').hidden=!pendingPreview;$('retry-preview').disabled=busy||Boolean(pendingConfirm);
        $('confirm').disabled=busy;$('retry-confirm').disabled=busy;$('revoke').disabled=busy;
    }
    function channel() {
        const chosen=$('channel').value, destinations=state?.destinations[chosen]||[];
        $('destination-label').hidden=chosen==='ai';$('destination').required=chosen!=='ai';$('destination').type=chosen==='email'?'email':'text';
        $('destinations').replaceChildren(...destinations.map(value=>{const node=document.createElement('option');node.value=value;return node;}));
        $('destination').value=chosen==='sms'?(destinations[0]||''):'';
        $('destination-help').textContent=chosen==='ai'?'Only the checked, explicitly fictional fields are submitted. The assessment cannot act, send or change work.':chosen==='email'?'Enter your separately approved receiving email. The sender is Everbranch; no access to your mailbox is needed.':'Only the test numbers approved for this reviewer can receive SMS. Final encoding and segment count are checked on the server.';
    }
    function text(parent, tag, value, className) {const node=document.createElement(tag);node.textContent=value;if(className)node.className=className;parent.append(node);return node;}
    function events(row) {const e=row.events||{};return `Provider acceptance: ${e.acceptance||row.status}. Delivery: ${e.delivery||'unverified'}. Link opened: ${e.link_opened?'yes':'not recorded'}. Acknowledged: ${e.acknowledged?'yes':'not recorded'}.`;}
    function renderPreview(row) {
        preview=row;$('preview-details').replaceChildren();$('email-html').hidden=true;$('email-html').srcdoc='';
        $('confirm').hidden=true;$('retry-confirm').hidden=!pendingConfirm;$('revoke').hidden=!row?.link_id;
        if(!row)return;
        text($('preview-details'),'p',`${row.channel.toUpperCase()} · ${row.status}`,'badge');
        text($('preview-details'),'p',row.channel==='ai'?'Recipient: approved AI model; selected fictional records only.':`Receiving destination: ${row.content.destination}`);
        if(row.quote.details?.from)text($('preview-details'),'p',`Service sender: ${row.quote.details.from}`);
        text($('preview-details'),'p',`Maximum cost reservation: ${money(row.quote.maximum_microusd)}. Preview expires ${row.expires_at}.`,'muted');
        if(row.channel==='sms')text($('preview-details'),'p',`${row.quote.details.encoding} · ${row.quote.details.segments} SMS segment(s) · ordinary SMS, no MMS or URL shortening.`,'muted');
        if(row.content.subject)text($('preview-details'),'h3',row.content.subject);
        if(row.content.body)text($('preview-details'),'pre',row.content.body);
        text($('preview-details'),'h3','Selected fictional source fields');
        text($('preview-details'),'pre',JSON.stringify(row.content.sources,null,2));
        if(row.content.html){$('email-html').srcdoc=row.content.html;$('email-html').hidden=false;}
        text($('preview-details'),'p',events(row),'muted');
        if(row.outcome?.assessment)text($('preview-details'),'pre',JSON.stringify(row.outcome.assessment,null,2));
        if(!row.quote.ready)text($('preview-details'),'p','This channel is not ready for live acceptance. No confirmation is available.','muted');
        $('confirm').hidden=row.status!=='preview'||!row.quote.ready||Boolean(pendingConfirm)||Boolean(state?.budget.paused)||!state?.budget.ready;
        $('retry-confirm').hidden=!pendingConfirm;
    }
    function renderState() {
        $('invitation').hidden=true;$('session').hidden=false;
        const b=state.budget;
        $('budget').textContent=b.ready?`${money(b.remaining_microusd)} remaining of $5.0000 shared lifetime cap`:'Paid tests unavailable until prior spend and storage policy are verified.';
        if(b.paused)$('budget').textContent+=' · PAUSED pending reconciliation';
        $('session-expiry').textContent=`Reviewer session expires ${state.expires_at}. ${b.scope||''}`;
        $('tasks').replaceChildren();for(const task of state.tasks){const article=document.createElement('article');article.className='record';text(article,'strong',task.title);text(article,'p',`${task.status} · ${task.owner}`,'muted');text(article,'p',`Next: ${task.next_action}`);$('tasks').append(article);}
        $('fields').replaceChildren();for(const field of state.fields){const label=document.createElement('label'),input=document.createElement('input');input.type='checkbox';input.name='field';input.value=field;input.checked=true;label.append(input,document.createTextNode(' '+(labels[field]||field)));$('fields').append(label);}
        $('history').replaceChildren();for(const row of state.actions){const article=document.createElement('article');article.className='record';const button=text(article,'button',`${row.channel.toUpperCase()} · ${row.status}`);button.type='button';button.onclick=()=>renderPreview(row);text(article,'p',events(row),'muted');text(article,'p',`Attempt ${row.id}`,'muted');$('history').append(article);}
        channel();
        if(pendingPreview){$('channel').value=pendingPreview.channel;channel();$('destination').value=pendingPreview.destination||'';for(const input of $('fields').querySelectorAll('input'))input.checked=pendingPreview.fields.includes(input.value);}
        const selected=state.actions.find(row=>row.id===(pendingConfirm?.id||preview?.id));renderPreview(selected||preview);
        if(pendingConfirm&&!selected)message('An interrupted confirmation is retained. Check its exact existing attempt; do not create a replacement.',true);
        lock();
    }
    async function load() {
        try{state=await api('/session');restore();renderState();return true;}
        catch(error){if([403,404].includes(error.status)){$('invitation').hidden=false;$('session').hidden=true;state=null;preview=null;pendingPreview=null;pendingConfirm=null;}message(error.message,true);return false;}
    }
    $('start-form').onsubmit=async event=>{event.preventDefault();if(busy)return;busy=true;lock();const code=$('reviewer-code').value;$('reviewer-code').value='';try{state=await api('/start',{reviewer_code:code});restore();renderState();message('Approved fictional trial session opened. Nothing has been sent.');}catch(error){message(error.message+' Check the current session before entering the invitation again.',true);}finally{busy=false;lock();}};
    async function prepare(){if(busy||pendingConfirm)return;pendingPreview ||= {request_id:crypto.randomUUID(),channel:$('channel').value,destination:$('channel').value==='ai'?null:$('destination').value.trim(),fields:[...$('fields').querySelectorAll('input:checked')].map(node=>node.value)};save();busy=true;lock();try{const row=await api('/previews',pendingPreview);pendingPreview=null;save();renderPreview(row);message('Exact preview prepared. No provider has been called.');}catch(error){if([403,422].includes(error.status)){pendingPreview=null;save();}message(error.message+' Retry keeps the same preview key and fields.',true);}finally{busy=false;lock();}}
    $('preview-form').onsubmit=event=>{event.preventDefault();prepare();};$('retry-preview').onclick=prepare;
    async function confirm(){if(busy||(!preview&&!pendingConfirm))return;pendingConfirm ||= {id:preview.id,digest:preview.digest};save();busy=true;lock();try{const row=await api('/previews/'+encodeURIComponent(pendingConfirm.id)+'/confirm',{confirmed:true,digest:pendingConfirm.digest});if(['accepted','rejected','uncertain'].includes(row.status)){pendingConfirm=null;save();}renderPreview(row);message(row.status==='accepted'?'Provider accepted this test. Delivery remains unverified.':row.status==='uncertain'?'Outcome uncertain. Its reservation is held; reconcile this attempt without a replacement.':'This existing attempt has been checked.');await load();}catch(error){message(error.message+' The same confirmation identity is retained. Refresh status or check this exact interrupted confirmation.',true);}finally{busy=false;lock();}}
    $('confirm').onclick=confirm;$('retry-confirm').onclick=confirm;
    $('revoke').onclick=async()=>{if(busy||!preview?.link_id)return;busy=true;lock();try{await api('/links/'+encodeURIComponent(preview.link_id)+'/revoke',{});message('This sample task link is revoked. Past provider acceptance and delivery events are unchanged.');await load();}catch(error){message(error.message,true);}finally{busy=false;lock();}};
    $('channel').onchange=channel;$('refresh').onclick=load;$('check-session').onclick=load;load();
})();
</script></body></html>
