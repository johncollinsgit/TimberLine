(()=>{
'use strict';
const configNode=document.getElementById('mf-label-config');if(!configNode)return;
const config=JSON.parse(configNode.textContent),copy=config.copy,base='/apps/forestry/wholesale/labels';
const setup=String(config.setupVariant),reuse=String(config.reuseVariant),serviceIds=new Set([setup,reuse]);
const fields=['Custom label','_MF label request','_MF label revision','_MF label mode'];
const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const t=key=>escape(copy[key]??key);
const uuid=()=>crypto.randomUUID();
const root=window.Shopify?.routes?.root||'/';
let labels=[],cart=null,busy=false,checkoutPending=false,opener=null,mode='reuse',revision=null,selected=new Set(),requestKey=uuid(),generation=0,instructions='',uploaded=null;
const dialog=document.createElement('dialog');dialog.className='mf-label-dialog';dialog.setAttribute('aria-labelledby','mf-label-title');document.body.append(dialog);
async function request(url,options={}){const response=await fetch(url,{credentials:'same-origin',...options,headers:{Accept:'application/json',...(options.headers||{})}});const body=await response.json();if(!response.ok)throw new Error(body.message||copy.unavailable);return body;}
const json=(url,data)=>request(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
async function getCart(){cart=await request(root+'cart.js');return cart;}
const isService=item=>serviceIds.has(String(item.variant_id));
const eligible=item=>!isService(item)&&!/wax\s?melt|room\s?spray|flight|sample|barcode/i.test(`${item.product_title} ${item.variant_title}`)&&/candle|cotton wick|cedar wick|wood wick/i.test(`${item.product_title} ${item.variant_title}`);
const eligibleLines=()=>cart.items.filter(eligible);
function receipt(){return cart?.items.find(isService)?.properties?.['_MF label request'];}
function title(){return cart?.items.find(isService)?.properties?.['Custom label'];}
function body(html){dialog.innerHTML=`<div class="mf-label-body"><div class="mf-label-top"><div><p class="mf-label-kicker">${t('eyebrow')}</p><h2 id="mf-label-title">${t('title')}</h2></div><button class="mf-label-close" type="button" data-close aria-label="${t('close')}">×</button></div><div id="mf-label-content">${html}</div><p class="mf-label-error" role="alert"></p></div>`;dialog.querySelector('[data-close]').onclick=()=>dialog.close();}
function fail(error){const box=dialog.querySelector('[role=alert]');if(box)box.textContent=error.message||copy.unavailable;}
function setBusy(value){busy=value;dialog.querySelectorAll('button,input,select,textarea').forEach(el=>el.disabled=value);}
function show(){if(!dialog.open)dialog.showModal();dialog.querySelector('button,input,select')?.focus();}
async function open(requested='reuse',checkout=false){
 opener=document.activeElement;checkoutPending=checkout;generation++;const run=generation;body(`<p>${t('loading')}</p>`);show();
 try{
  const [library]=await Promise.all([request(base),getCart()]);if(run!==generation)return;labels=library.labels||[];mode=requested==='new'?'new':labels.length?'reuse':'legacy';revision=mode==='reuse'?labels[0]:null;
  const existing=cart.items.find(isService)?.properties;const current=labels.find(label=>label.id===existing?.['_MF label revision']);if(requested!=='new'&&current)revision=current;if(requested!=='new'&&receipt()){const saved=await request(base+'/requests/'+encodeURIComponent(receipt()));if(!saved.bound){mode=saved.mode;revision=saved.label||null;uploaded=mode==='new'?revision:null;}}selected=new Set(eligibleLines().filter(item=>!receipt()||item.properties?.['_MF label request']===receipt()).map(item=>item.key));requestKey=uuid();instructions='';if(requested==='new')uploaded=null;
  if(checkout&&!receipt()&&cart.attributes?.['Custom labels']!=='No')question();else selection();
 }catch(error){fail(error);}
}
function question(){body(`<p>${t('checkout_question')}</p><p class="mf-label-note">${t('single_label')}</p><div class="mf-label-actions"><button type="button" data-yes>${t('yes')}</button><button type="button" class="mf-label-secondary" data-no>${t('no')}</button></div>`);dialog.querySelector('[data-yes]').onclick=selection;dialog.querySelector('[data-no]').onclick=async()=>{setBusy(true);try{await remove(true);dialog.close();goCheckout();}catch(e){fail(e);}finally{setBusy(false);}};}
function selection(){
 const picker=mode==='new'?`<label>${t('label_name')}<input name="label_name" maxlength="120" value="${escape(uploaded?.name||copy.custom_label)}"></label><label>${t('upload')}<input name="logo" type="file" accept=".png,.jpg,.jpeg,.webp,.svg,.pdf"></label><p class="mf-label-note">${t('formats')}</p><div data-upload-preview>${uploaded?.preview?`<img class="mf-label-preview" src="${escape(uploaded.preview)}" alt="${t('preview')}">`:''}</div>`:mode==='reuse'?`<label>${t('saved_label')}<select name="saved">${labels.map(label=>`<option value="${escape(label.id)}" ${label.id===revision?.id?'selected':''}>${escape(label.name)}</option>`).join('')}</select></label><img class="mf-label-preview" src="${escape(revision?.preview||config.placeholder)}" alt="${t('preview')}">`:`<p>${t('legacy')}</p>`;
 body(`${picker}<a href="#" data-switch>${mode==='new'?t('use_previous'):t('new_logo')}</a><div class="mf-label-actions"><button type="button" data-continue>${t('continue')}</button></div><p class="mf-label-note"><a href="mailto:info@theforestrystudio.com?subject=Multiple%20custom%20labels">${t('multiple_link')}</a></p>`);
 dialog.querySelector('[data-switch]').onclick=e=>{e.preventDefault();mode=mode==='new'?(labels.length?'reuse':'legacy'):'new';revision=mode==='reuse'?labels[0]:null;selection();};
 dialog.querySelector('[name=saved]')?.addEventListener('change',e=>{revision=labels.find(label=>label.id===e.target.value);dialog.querySelector('.mf-label-preview').src=revision.preview;});
 dialog.querySelector('[name=logo]')?.addEventListener('change',e=>{const file=e.target.files[0];if(file&&file.type.startsWith('image/')&&!file.type.includes('svg')){const url=URL.createObjectURL(file),box=dialog.querySelector('[data-upload-preview]');box.innerHTML='';const image=document.createElement('img');image.className='mf-label-preview';image.alt=copy.preview;image.src=url;image.onload=()=>URL.revokeObjectURL(url);box.append(image);}});
 dialog.querySelector('[data-continue]').onclick=async()=>{
  if(mode!=='new'){review();return;}
  const file=dialog.querySelector('[name=logo]').files[0],name=dialog.querySelector('[name=label_name]').value.trim();
  if(!file&&uploaded){setBusy(true);try{revision=await readyPreview(uploaded);review();}catch(error){fail(error);}finally{setBusy(false);}return;}if(!file||!name){fail(new Error(copy.file_required));return;}if(file.size>20*1024*1024){fail(new Error(copy.file_size));return;}
  setBusy(true);try{const data=new FormData();data.append('file',file);data.append('name',name);const result=await request(base+'/uploads',{method:'POST',body:data});revision=result.label;uploaded=revision;revision=await readyPreview(revision);uploaded=revision;review();}catch(e){fail(e);}finally{setBusy(false);}
 };
}
async function readyPreview(label){
 for(let attempt=0;attempt<20;attempt++){
  if(label.status==='failed')throw new Error(copy.preview_failed||copy.unavailable);
  if(label.preview)return label;
  await new Promise(resolve=>setTimeout(resolve,1000));
  const result=await request(base+'/revisions/'+encodeURIComponent(label.id));label=result.label;
 }
 throw new Error(copy.preview_pending||copy.unavailable);
}
function review(){
 const lines=eligibleLines();const fee=mode==='new'?20:5;
 body(`<p>${mode==='legacy'?t('legacy'):escape(revision?.name)}</p>${revision?.preview?`<img class="mf-label-preview" src="${escape(revision.preview)}" alt="${t('preview')}">`:''}<p class="mf-label-note">${t('single_label')}</p><details ${lines.length===0?'open':''}><summary>${t('groups')} (${lines.length})</summary>${lines.length?lines.map((line,index)=>`<label class="mf-label-group"><input type="checkbox" data-group="${index}" ${selected.has(line.key)?'checked':''}><span>${escape(line.product_title.replace(/^Wholesale\s+/i,''))}<br>${escape(line.variant_title)} · ${line.quantity}</span></label>`).join(''):`<p>${t('add_candles')}</p>`}</details><details><summary>${t('notes')}</summary><label>${t('notes')}<textarea name="instructions" rows="3" maxlength="2000">${escape(instructions)}</textarea></label></details><div class="mf-label-fee"><span>${mode==='new'?t('setup_fee'):t('reuse_fee')}</span><span>$${fee.toFixed(2)}</span></div><p class="mf-label-note">${t('staff_review')}</p><div class="mf-label-actions"><button type="button" data-confirm ${lines.length?'':'disabled'}>${checkoutPending?t('save_checkout'):t('save')}</button><button class="mf-label-secondary" type="button" data-back>${t('back')}</button></div>`);
 dialog.querySelectorAll('[data-group]').forEach(input=>input.onchange=()=>{const key=lines[Number(input.dataset.group)].key;if(input.checked)selected.add(key);else selected.delete(key);requestKey=uuid();});
 dialog.querySelector('[data-back]').onclick=()=>{instructions=dialog.querySelector('[name=instructions]').value;selection();};dialog.querySelector('[name=instructions]').oninput=e=>{instructions=e.target.value;requestKey=uuid();};
 dialog.querySelector('[data-confirm]').onclick=async()=>{const groups=lines.filter(line=>selected.has(line.key));if(!groups.length){fail(new Error(copy.choose_groups));return;}
  setBusy(true);try{const result=await json(base+'/requests',{mode,revision_id:revision?.id||null,groups:groups.map(item=>({variant_id:String(item.variant_id),line_key:item.key,quantity:item.quantity})),instructions:dialog.querySelector('[name=instructions]')?.value||'',idempotency_key:requestKey});await apply(result,groups);dialog.close();if(checkoutPending)goCheckout();}catch(e){fail(e);await getCart().catch(()=>{});}finally{setBusy(false);}
 };
}
async function change(line,properties,quantity=line.quantity){return json(root+'cart/change.js',{id:line.key,quantity,properties:{...properties}});}
const identity=line=>JSON.stringify([String(line.variant_id),Object.entries(line.properties||{}).filter(([key,value])=>!fields.includes(key)&&value!=='').sort(([a],[b])=>a.localeCompare(b))]);
function resolveLine(target){const exact=cart.items.find(line=>line.key===target.key);if(exact)return exact;const matches=cart.items.filter(line=>identity(line)===identity(target));if(matches.length!==1)throw new Error(copy.cart_changed);return matches[0];}
async function remove(markNo=false){await getCart();for(const line of cart.items.filter(item=>!isService(item)&&item.properties?.['_MF label request'])){const properties={...line.properties};fields.forEach(field=>properties[field]='');await change(resolveLine(line),properties);await getCart();}for(const line of cart.items.filter(isService)){await json(root+'cart/change.js',{id:line.key,quantity:0});await getCart();}await json(root+'cart/update.js',{attributes:{'Custom labels':markNo?'No':''}});await refresh();}
async function apply(result,groups){
 await getCart();const targets=groups.map(group=>{const item=resolveLine(group);return {variant_id:item.variant_id,properties:{...item.properties},quantity:item.quantity,key:item.key};});
 for(const line of cart.items.filter(isService)){await json(root+'cart/change.js',{id:line.key,quantity:0});await getCart();}
 for(const line of cart.items.filter(item=>!isService(item)&&item.properties?.['_MF label request'])){
  const target=targets.find(item=>item.key===line.key);if(target)continue;const properties={...line.properties};fields.forEach(field=>properties[field]='');await change(resolveLine(line),properties);await getCart();
 }
 const label=mode==='legacy'?copy.previous_label:revision.name;
 for(const target of targets){const live=resolveLine(target);await change(live,{...target.properties,'Custom label':label,'_MF label request':result.receipt,'_MF label revision':result.revision_id||'pending','_MF label mode':mode});await getCart();}
 await json(root+'cart/add.js',{items:[{id:Number(result.variant_id),quantity:1,properties:{'Custom label':label,'_MF label request':result.receipt,'_MF label revision':result.revision_id||'pending','_MF label mode':mode}}]});
 await json(root+'cart/update.js',{attributes:{'Custom labels':'Yes'}});await refresh();
}
async function refresh(){await getCart();document.dispatchEvent(new CustomEvent('mf:cart-changed',{detail:cart}));updatePresentation();if(typeof publish==='function'&&typeof PUB_SUB_EVENTS!=='undefined')publish(PUB_SUB_EVENTS.cartUpdate,{source:'custom-labels',cartData:cart});}
function goCheckout(){location.assign(root+'checkout');}
function updatePresentation(){
 const label=labels.find(item=>item.id===cart?.items.find(isService)?.properties?.['_MF label revision'])||labels[0];
 document.querySelectorAll('[data-mf-label-product]').forEach(box=>{const handle=box.dataset.mfLabelProduct,src=handle==='custom-label-set-up'?config.setupPhoto:label?.preview||config.placeholder;const area=box.querySelector('.card__media .media')||box.querySelector('.mf-product-heading');if(area){let image=area.querySelector('.mf-label-art');if(!image){image=document.createElement('img');image.className=box.tagName==='TBODY'?'mf-label-art mf-product-image':'mf-label-art';image.width=400;image.height=400;image.alt=handle==='custom-label-set-up'?copy.setup_photo:label?.name||copy.choose_label;if(box.tagName==='TBODY'){area.querySelector('.mf-product-image')?.remove();area.prepend(image);}else area.append(image);}if(image.getAttribute('src')!==src)image.src=src;}if(box.matches('.product')&&handle==='use-my-custom-label'){box.querySelectorAll('.product__media-wrapper img').forEach(image=>{image.removeAttribute('srcset');if(image.getAttribute('src')!==src)image.src=src;image.alt=label?.name||copy.setup_photo;});}});
 const signature=JSON.stringify([cart?.items.map(i=>[i.key,i.quantity]),labels.map(i=>i.id)]);
 const checkout=document.querySelector('#checkout')||document.querySelector('#CartDrawer-Checkout');if(checkout&&cart){let box=document.querySelector('[data-mf-label-checkout-summary]');if(box?.dataset.signature===signature)return;if(!box){box=document.createElement('div');}box.replaceChildren();box.dataset.signature=signature;box.dataset.mfLabelCheckoutSummary='';box.className='mf-label-checkout-summary';box.textContent=receipt()?`${copy.custom_label}: ${title()}`:copy.checkout_question;const edit=document.createElement('button');edit.type='button';edit.textContent=receipt()?copy.edit:copy.choose_label;edit.onclick=()=>open('reuse');box.append(edit);if(receipt()){const removeButton=document.createElement('button');removeButton.type='button';removeButton.textContent=copy.remove;removeButton.onclick=async()=>{removeButton.disabled=true;try{await remove(true);}catch(e){box.textContent=e.message;}};box.append(removeButton);}if(!box.isConnected)checkout.parentElement.insertBefore(box,checkout);}
}
async function beforeCheckout(event){
 const control=event.target.closest?.('[name="checkout"],a[href*="/checkout"],[data-mf-label-checkout]');if(!control||control.closest('.mf-label-dialog'))return;
 event.preventDefault();event.stopImmediatePropagation();try{await getCart();if(receipt()){const saved=await request(base+'/requests/'+encodeURIComponent(receipt()));if(saved.bound){await open('reuse',true);return;}goCheckout();}else if(cart.attributes?.['Custom labels']==='No')goCheckout();else await open('reuse',true);}catch(error){body('');fail(error);show();}
}
document.addEventListener('click',event=>{const removeButton=event.target.closest('[data-mf-label-remove]');if(removeButton){event.preventDefault();event.stopImmediatePropagation();removeButton.disabled=true;remove(true).catch(error=>{body('');fail(error);show();}).finally(()=>removeButton.disabled=false);return;}const button=event.target.closest('[data-mf-label-open]');if(button){event.preventDefault();event.stopImmediatePropagation();open(button.dataset.mfLabelOpen);return;}beforeCheckout(event);},true);
document.addEventListener('submit',event=>{if(event.submitter?.name==='checkout')beforeCheckout({target:event.submitter,preventDefault:()=>event.preventDefault(),stopImmediatePropagation:()=>event.stopImmediatePropagation()});},true);
dialog.addEventListener('close',()=>{generation++;if(opener?.isConnected)opener.focus();else document.querySelector('#checkout,#CartDrawer-Checkout')?.focus();});
dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
let timer;new MutationObserver(records=>{if(records.every(record=>dialog.contains(record.target)||record.target.closest?.('[data-mf-label-checkout-summary]')))return;clearTimeout(timer);timer=setTimeout(()=>{document.querySelectorAll('[data-mf-label-open]').forEach(button=>{const product=button.closest('.product');if(product)product.dataset.mfLabelProduct=button.dataset.mfLabelOpen==='new'?'custom-label-set-up':'use-my-custom-label';});updatePresentation();},100);}).observe(document.body,{childList:true,subtree:true});
window.MFCustomLabels={open,remove,refresh,setupPhoto:config.setupPhoto,placeholder:config.placeholder};
Promise.all([request(base),getCart()]).then(([library])=>{labels=library.labels||[];updatePresentation();}).catch(()=>updatePresentation());
})();
