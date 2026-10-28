import {importLabelFiles} from '../../scripts/labels/batch-import.js';
const root=document.querySelector('#wl-admin');
if(root)root.addEventListener('submit',async event=>{
 const form=event.target;if(!form.matches('[data-label-import],[data-label-action]'))return;
 event.preventDefault();const message=form.querySelector('[role="status"]'),button=form.querySelector('button');button.disabled=true;message.textContent='Saving…';
 const send=async file=>{
  const data=new FormData(form);
  if(file){data.delete('files[]');data.append('files[]',file);}
  data.set('context_token',root.dataset.context);
  const helper=window.ForestryEmbeddedApp?.getShopifySessionToken;if(helper){const token=await helper();if(token)data.set('shopify_session_token',token);}
  const token=data.get('shopify_session_token');
  const response=await fetch(form.getAttribute('action'),{method:'POST',body:data,headers:{Accept:'application/json',...(token?{Authorization:`Bearer ${token}`}:{})}});
  const json=await response.json();if(!response.ok)throw new Error(json.message||'Unable to save. Your selections are retained.');return json;
 };
 try{
  if(form.matches('[data-label-import]')){
   form.savedFiles??=new Set();
   const results=await importLabelFiles([...form.querySelector('[type=file]').files],send,items=>{message.textContent=items.map(item=>`${item.file}: ${item.ok?'saved; proof queued':item.error}`).join('\n');},form.savedFiles);
   if(results.some(item=>!item.ok))return;
  }else await send();
  location.reload();
 }catch(error){message.textContent=error.message;}finally{button.disabled=false;}
});
async function privateAsset(url){
 const token=await window.ForestryEmbeddedApp?.getShopifySessionToken?.();
 const response=await fetch(url,{headers:token?{Authorization:`Bearer ${token}`}:{}});
 if(!response.ok)throw new Error('Artwork could not be retrieved. Reopen Custom Labels and try again.');
 return response;
}
const previews=new IntersectionObserver(entries=>entries.forEach(async entry=>{
 if(!entry.isIntersecting)return;const image=entry.target;previews.unobserve(image);
 try{image.src=URL.createObjectURL(await (await privateAsset(image.dataset.labelPreviewUrl)).blob());}catch(error){image.alt=error.message;}
}));
root?.querySelectorAll('[data-label-preview-url]').forEach(image=>previews.observe(image));
root?.addEventListener('click',async event=>{
 const link=event.target.closest('[data-label-download]');if(!link)return;event.preventDefault();
 try{const response=await privateAsset(link.href),url=URL.createObjectURL(await response.blob()),a=document.createElement('a');a.href=url;a.download=(response.headers.get('content-disposition')?.match(/filename="?([^";]+)/)?.[1]||'customer-artwork');a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(error){link.textContent=error.message;}
});
