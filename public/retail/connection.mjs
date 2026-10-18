// Authenticated state is refreshed from the server, never the offline pack.
// In particular an offline launch has no server-rendered CSRF meta tag.
let refreshing=null;
export function refreshRetailSession() {
 if(refreshing)return refreshing;
 refreshing=(async()=>{
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
  try {
   const response=await fetch('/retail/showroom/api/session',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
   if([401,403,419].includes(response.status))throw Error('Sign in as the showroom owner, then reopen voice.');
   if(!response.ok)throw Error(response.status===429?'Please wait a moment, then recheck the connection.':'The demo server is unavailable. Recheck when your connection returns.');
   const result=await response.json();
   if(typeof result.csrf_token!=='string'||!/^[A-Za-z0-9]{40}$/.test(result.csrf_token))throw Error('The demo session could not be refreshed. Sign in again.');
   let meta=document.querySelector('meta[name="csrf-token"]');
   if(!meta){meta=document.createElement('meta');meta.name='csrf-token';document.head.append(meta);}
   meta.content=result.csrf_token;
   window.dispatchEvent(new CustomEvent('retail:connected',{detail:{assistant:result.assistant}}));
   return result.csrf_token;
  } finally {clearTimeout(timer);}
 })().finally(()=>{refreshing=null;});
 return refreshing;
}
export async function retailFetch(path,options={}) {
 const method=(options.method??'GET').toUpperCase();
 if(['GET','HEAD','OPTIONS'].includes(method))return fetch(path,options);
 const token=await refreshRetailSession();
 if(options.signal?.aborted)throw new DOMException('Request cancelled.','AbortError');
 const headers=new Headers(options.headers);headers.set('X-CSRF-TOKEN',token);
 // Never replay a provider request or message after an uncertain result.
 return fetch(path,{...options,headers});
}
