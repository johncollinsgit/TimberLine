/* The mentor review channel has no operational Enterprise authority. */
(() => {
  'use strict';
  const boot = globalThis.EverbranchCampusReviewBoot;
  if (!boot || !document.querySelector('[data-campus-review]')) return;
  const panel = document.querySelector('[data-campus-review]'), dialog = document.getElementById('review-dialog');
  const $ = selector => panel.querySelector(selector);
  const nameForm = $('[data-campus-review-name]'), commentForm = $('[data-campus-review-comment]');
  const body = commentForm?.querySelector('[name="body"]'), names = nameForm?.querySelector('[name="reviewer_name"]');
  const pageSelect = $('[data-campus-review-page]'), status = $('[data-campus-review-status]') || document.getElementById('review-status');
  const comments = $('[data-campus-review-comments]') || document.getElementById('review-comments');
  if (!dialog || !nameForm || !commentForm || !body || !names || !status || !comments) return;
  const allowed = new Set(boot.pages.map(item => item.id));
  const original = {version_id:boot.version_id,version_hash:boot.version_hash,build_id:boot.build_id,source_hash:boot.source_hash};
  let context = boot, page = boot.page, generation = 0, loading = null, posting = false, priorFocus = null, locked = false;
  const drafts = new Map();
  const urls = boot.urls;
  function safeUrl(value) {
    const target = new URL(value, location.href);
    if (target.origin !== location.origin || target.username || target.password || target.hash || target.search) throw Error('Invalid review destination.');
    return target.href;
  }
  try { for (const group of ['pages','data_pages','name_pages','comment_pages']) for (const value of Object.values(urls[group])) safeUrl(value); safeUrl(urls.proposal); }
  catch { status.textContent = 'Review destinations are unavailable.'; return; }
  const key = () => ['everbranch-campus-feedback',boot.version_id,boot.version_hash,boot.build_id,boot.source_hash,page,context.reviewer_name || 'unnamed'].join(':');
  const read = k => {if(drafts.has(k))return drafts.get(k);let value={};try{value=JSON.parse(sessionStorage.getItem(k)||'{}');}catch{}drafts.set(k,value);return value;};
  const write = (k, value) => {drafts.set(k,value);try {sessionStorage.setItem(k, JSON.stringify(value));} catch {let note=panel.querySelector('[data-review-storage-warning]');if(!note){note=document.createElement('p');note.dataset.reviewStorageWarning='true';note.setAttribute('role','status');body.after(note);}note.textContent='Browser draft storage is unavailable. Exact retry remains available in this tab; keep it open until the save is confirmed. Reload may lose this unsent draft.';} };
  function saveDraft() {const entry=read(key());write(key(),{...entry,body:body.value.slice(0,4000)});}
  function restoreDraft() {
    const draft=read(key()); body.value=typeof draft.body==='string'?draft.body:''; body.readOnly=!!draft.pending;
    commentForm.querySelector('button[type="submit"]')?.setAttribute('aria-label',draft.pending?'Retry the exact unconfirmed comment':'Save page comment');
    if(draft.pending&&!locked) status.textContent='The previous save is unconfirmed. Retry the exact comment; it will not create a duplicate.';
  }
  function checked(next, expectedPage) {
    if(!next || !Object.entries(original).every(([field,value])=>next[field]===value) || next.page!==expectedPage
      || !allowed.has(next.page) || !Array.isArray(next.comments) || next.comments.length>200
      || (next.reviewer_name!==null && !boot.reviewer_names.includes(next.reviewer_name))) {const error=Error('This review context changed. Reopen the published review link.');error.contextChanged=true;throw error;}
    return next;
  }
  function render() {
    if(pageSelect) pageSelect.value=page;
    names.value=locked?'':context.reviewer_name || '';names.disabled=locked;
    nameForm.querySelector('button[type="submit"]')?.toggleAttribute('disabled',locked||posting);
    body.disabled=locked || !context.writes_enabled || !context.reviewer_name;
    const display=panel.querySelector('[data-campus-review-display-name]');if(display)display.textContent=locked?'Review access unavailable':context.reviewer_name || 'Choose a display name first';
    const expected=commentForm.querySelector('[name="expected_reviewer_name"]');if(expected)expected.value=context.reviewer_name || '';
    nameForm.action=urls.name_pages[page]; commentForm.action=urls.comment_pages[page];
    for(const form of [nameForm,commentForm]) for(const [field,value] of Object.entries({version_hash:boot.version_hash,build_id:boot.build_id,source_hash:boot.source_hash})) {
      const input=form.querySelector(`[name="${field}"]`); if(input)input.value=value;
    }
    comments.replaceChildren();
    if(!context.comments.length){const empty=document.createElement('p');empty.textContent='No shared comments on this page yet.';comments.append(empty);}
    for(const item of context.comments){const card=document.createElement('article'),heading=document.createElement('strong'),text=document.createElement('p'),time=document.createElement('small');heading.textContent=String(item.name);text.textContent=String(item.body);time.textContent=String(item.created_at);card.append(heading,text,time);comments.append(card);}
    const submit=commentForm.querySelector('button[type="submit"]');if(submit)submit.disabled=locked || !context.writes_enabled || !context.reviewer_name || posting;
    const label=panel.querySelector('[data-campus-review-current]');if(label)label.textContent=boot.pages.find(item=>item.id===page)?.title || page;
    for(const link of panel.querySelectorAll('[data-campus-review-page-links] a')) {if(link.href===safeUrl(urls.pages[page]))link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');}
    restoreDraft();
  }
  function definitive(error){return error.contextChanged || [401,403,404,409,410,419].includes(error.status);}
  function lockContext(reason){saveDraft();generation++;loading?.abort();loading=null;locked=true;context={...context,comments:[],writes_enabled:false};render();if(reason)status.textContent=reason;}
  async function request(url, payload, signal) {
    const response=await fetch(safeUrl(url),{method:payload?'POST':'GET',credentials:'same-origin',redirect:'error',signal,
      headers:{Accept:'application/json',...(payload?{'Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf_token}: {})},...(payload?{body:JSON.stringify(payload)}:{})});
    if(!response.ok){const error=Error([401,403,410,419].includes(response.status)?'Review access expired or changed. Reopen the published link.':response.status===409?'This review version or display name changed. Refresh before continuing.':'The review request was not confirmed. Keep your draft and try again.');error.status=response.status;throw error;}
    return response.json();
  }
  async function refresh() {
    saveDraft(); loading?.abort();const controller=new AbortController();loading=controller;const mine=++generation,current=page;
    status.textContent='Loading shared page comments…';const timer=setTimeout(()=>controller.abort(),15000);
    try{const result=await request(urls.data_pages[current],null,controller.signal);if(mine!==generation||page!==current)return;context=checked(result.review,current);locked=false;render();status.textContent=context.coverage?.partial?'Showing a bounded set of shared comments. Older comments remain retained.':'Shared comments refreshed from the server.';}
    catch(error){if(mine===generation){if(definitive(error))lockContext(error.message);status.textContent=error.name==='AbortError'?'Refresh timed out. Displayed comments may be stale; your draft remains in this tab.':error.message;}}
    finally{clearTimeout(timer);if(loading===controller)loading=null;}
  }
  function changePage(next) {
    if(!allowed.has(next)||next===page)return;
    saveDraft();generation++;loading?.abort();page=next;context={...context,page,comments:[]};render();
    if(dialog.open)void refresh();
  }
  document.addEventListener('enterprise:section-rendered',event=>changePage(event.detail?.section));
  function navigate(next){if(!allowed.has(next))return;saveDraft();if(boot.layout==='canvas'||next==='workflows'||page==='workflows'||!document.getElementById('enterprise-main'))location.assign(safeUrl(urls.pages[next]));else document.dispatchEvent(new CustomEvent('enterprise:navigate',{detail:{section:next}}));}
  pageSelect?.addEventListener('change',()=>navigate(pageSelect.value));
  for(const link of panel.querySelectorAll('[data-campus-review-page-links] a'))link.addEventListener('click',event=>{if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;const next=Object.keys(urls.pages).find(id=>safeUrl(urls.pages[id])===link.href);if(next){event.preventDefault();navigate(next);}});
  body.addEventListener('input',saveDraft);
  commentForm.addEventListener('submit',async event=>{
    event.preventDefault();if(locked||posting||!context.reviewer_name||!context.writes_enabled)return;saveDraft();
    const savedKey=key(),entry=read(savedKey),current=page,actor=context.reviewer_name,wasPending=!!entry.pending;
    const pending=entry.pending || {version_hash:boot.version_hash,build_id:boot.build_id,source_hash:boot.source_hash,
      expected_reviewer_name:actor,body:body.value,comment_key:crypto.randomUUID()};
    if(!pending.body.trim())return;
    write(savedKey,{...entry,body:pending.body,pending});posting=true;body.readOnly=true;render();status.textContent='Saving this exact page comment…';
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000),mine=generation;let failureNotice='';
    try{const result=await request(urls.comment_pages[current],pending,controller.signal);checked(result.review,current);if(result.saved!==true)throw Error('The save was not confirmed.');
      const stored=read(savedKey);if(stored.pending?.comment_key===pending.comment_key)write(savedKey,{body:''});
      if(mine===generation&&page===current&&context.reviewer_name===actor){generation++;loading?.abort();loading=null;context=result.review;render();status.textContent='Comment saved and shared on this page.';}}
    catch(error){if(mine===generation&&page===current){if(error.status===422&&!wasPending){write(savedKey,{body:pending.body});body.readOnly=false;}if(definitive(error))lockContext(error.message);failureNotice=error.name==='AbortError'?'Save outcome is unconfirmed. Retry this exact comment safely.':error.message;}}
    finally{clearTimeout(timer);posting=false;render();if(failureNotice&&mine===generation&&page===current)status.textContent=failureNotice;}
  });
  nameForm.addEventListener('submit',async event=>{
    event.preventDefault();if(locked||posting)return;saveDraft();const next=names.value,current=page,mine=generation,controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);posting=true;status.textContent='Saving review display name…';
    try{const result=await request(urls.name_pages[current],{version_hash:boot.version_hash,build_id:boot.build_id,source_hash:boot.source_hash,reviewer_name:next},controller.signal);if(mine!==generation||page!==current)return;const accepted=checked(result.review,current);generation++;loading?.abort();loading=null;context=accepted;render();status.textContent='Review display name saved. This grants no campus access.';}
    catch(error){if(mine===generation){if(definitive(error))lockContext(error.message);status.textContent=error.name==='AbortError'?'Display name save is unconfirmed. Refresh to check its current value.':error.message;}}
    finally{clearTimeout(timer);posting=false;render();}
  });
  const open=document.getElementById('review-open'),close=document.getElementById('review-close');
  open?.addEventListener('click',event=>{priorFocus=event.currentTarget;if(!dialog.open)dialog.showModal();void refresh();});
  close?.addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>{saveDraft();priorFocus?.focus();});
  document.getElementById('review-refresh')?.addEventListener('click',()=>void refresh());
  for(const holder of document.querySelectorAll('.erp-proposal')){const link=document.createElement('a');link.href=safeUrl(urls.proposal);link.className='ert-button primary';link.textContent='Open proposal';holder.replaceChildren(link);}
  const toolbar=document.createElement('div');toolbar.className='campus-review-tools';const proposal=document.createElement('a');proposal.className='button primary';proposal.href=safeUrl(urls.proposal);proposal.textContent='Open proposal';toolbar.append(proposal);if(open)toolbar.append(open);const header=boot.layout==='canvas'?null:document.querySelector('.topbar');if(header)header.append(toolbar);else{toolbar.classList.add('campus-review-standalone');document.body.prepend(toolbar);}
  document.body.classList.add('has-campus-review');
  const ribbon=document.querySelector('.reviewer-boundary');if(ribbon)ribbon.textContent=boot.reviewer_ai?.enabled===true?'Sample work stays in this tab. Confirmed AI shares selected fields; review comments are saved.':'Fictional campus work stays in this tab. Review names and page comments are saved on the server; no messages or AI charges.';
  globalThis.addEventListener('pagehide',()=>{saveDraft();generation++;loading?.abort();});
  render();
})();
