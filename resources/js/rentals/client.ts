const root = document.getElementById('rental-workspace')!;
export const bootstrap = JSON.parse(root.dataset.bootstrap!);
let current: any;
let propertyId = '';
const pendingKey = (id = propertyId) => `rental-command:${bootstrap.tenant}:${bootstrap.userId}:${id}`;
type UploadIdentity = {name:string; size:number; type:string; sha256:string; teamId:string};
type Pending = {path: string; input: Record<string, any>; upload?:UploadIdentity};
function pending(id = propertyId): Pending | null {
  const value = sessionStorage.getItem(pendingKey(id));
  if (!value) return null;
  try { return JSON.parse(value); } catch { throw Error('The saved change needs review before another save.'); }
}
export function hasPending() { return !!pending(); }
export function pendingUpload() { return pending()?.upload || null; }
export function hasPendingCreate() { return !!sessionStorage.getItem(`rental-create:${bootstrap.tenant}:${bootstrap.userId}`); }
async function request(path: string, options: RequestInit = {}) {
  const url = new URL(bootstrap.baseUrl + path, location.origin);
  url.searchParams.set('tenant', bootstrap.tenant);
  let response: Response;
  try {
    response = await fetch(url, {...options, credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(30000),
      headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')!.content, ...options.headers}});
  } catch { throw Error(options.method==='POST'?'The save response may be delayed. Review or retry the original change before saving again.':'The workspace could not be loaded. Try again when the connection is available.'); }
  let data: any;
  try { data = await response.json(); }
  catch {
    if (response.ok) throw Error(options.method==='POST'?'The save response could not be read. Confirm the original change before saving again.':'The workspace response could not be read. Try loading it again.');
    data = {};
  }
  if (!response.ok) {
    const error = Object.assign(new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'This request could not be completed.'), {status:response.status});
    throw error;
  }
  return data;
}
export async function loadProperty(id?: string) {
  const list = await request('/properties');
  const nextId = id || new URLSearchParams(location.search).get('property') || list.properties[0]?.id || '';
  if (!nextId) return {needsSetup:true, ...list};
  let next = await request(`/properties/${encodeURIComponent(nextId)}`);
  next.properties = list.properties;
  const saved = pending(nextId);
  if (saved) {
    const receipt = await request(`/properties/${nextId}/commands/${saved.input.requestKey}`);
    if (receipt.applied) { sessionStorage.removeItem(pendingKey(nextId)); next = {...receipt.state, properties:list.properties,
      recoveredUpload:saved.upload?receipt.state.documents.find((file:any)=>file.id===saved.input.requestKey):undefined}; }
  }
  // Keep mutation routing bound to the displayed property until its replacement is fully loaded.
  propertyId = nextId;
  current = next;
  const address = new URL(location.href); address.searchParams.set('property',propertyId); history.replaceState(null,'',address);
  return current;
}
export async function createProperty(values: Record<string, unknown>) {
  const key = `rental-create:${bootstrap.tenant}:${bootstrap.userId}`;
  const prior = sessionStorage.getItem(key);
  const input = prior ? JSON.parse(prior) : {id:crypto.randomUUID(), ...values};
  sessionStorage.setItem(key, JSON.stringify(input));
  let next;
  try { next = await request('/properties', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(input)}); }
  catch(error:any) { if(error.status===422) sessionStorage.removeItem(key); throw error; }
  sessionStorage.removeItem(key);
  return loadProperty(next.property.id);
}
async function deliver(saved: Pending) {
  try {
    const next = await request(saved.path, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(saved.input)});
    sessionStorage.removeItem(pendingKey());
    current = {...next,properties:current.properties};
    return current;
  } catch (error:any) {
    if ([409,422].includes(error.status)) sessionStorage.removeItem(pendingKey());
    if (error.status === 409) current = await loadProperty(propertyId);
    throw error;
  }
}
export async function command(action: string, fields: Record<string, unknown> = {}) {
  if (pending()) throw Error('Review the original saved change first.');
  const saved = {path:`/properties/${propertyId}/commands`,input:{...fields,action,version:current.version,requestKey:crypto.randomUUID()}};
  sessionStorage.setItem(pendingKey(),JSON.stringify(saved));
  return deliver(saved);
}
export async function retryPending() {
  const saved = pending();
  if (!saved) return loadProperty(propertyId);
  if (saved.upload) {
    const recovered = await confirmUpload(saved);
    if (recovered) return {...recovered.state,recoveredUpload:recovered.file};
    throw Error('The original upload is not confirmed. Reselect the same file to retry it.');
  }
  return deliver(saved);
}
async function confirmUpload(saved:Pending) {
  const receipt = await request(`/properties/${propertyId}/commands/${saved.input.requestKey}`);
  if (!receipt.applied) return null;
  current = {...receipt.state,properties:current.properties};
  const file = current.documents.find((file:any)=>file.id===saved.input.requestKey);
  sessionStorage.removeItem(pendingKey());
  return {state:current,file};
}
export async function uploadFile(file: File, teamId = '') {
  let saved = pending();
  if (saved && !saved.upload) throw Error('Review the original saved change first.');
  if (saved) {
    const recovered = await confirmUpload(saved);
    if (recovered) return recovered;
  }
  const sha256 = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await file.arrayBuffer())),value=>value.toString(16).padStart(2,'0')).join('');
  if (saved) {
    const original = saved.upload!;
    if (file.name!==original.name || file.size!==original.size || file.type!==original.type || sha256!==original.sha256) throw Error('Choose the same original file. Its name, type, size and contents must match the pending upload.');
  } else {
    saved = {path:`/properties/${propertyId}/files`,input:{requestKey:crypto.randomUUID(),version:current.version},
      upload:{name:file.name,size:file.size,type:file.type,sha256,teamId}};
    sessionStorage.setItem(pendingKey(),JSON.stringify(saved));
  }
  const input = new FormData();
  input.append('file',file); input.append('teamId',saved.upload!.teamId); input.append('requestKey',saved.input.requestKey); input.append('version',String(saved.input.version));
  try {
    const result = await request(saved.path,{method:'POST',body:input});
    if (!result.state?.property || !result.file?.id) throw Error('The upload response could not be read. Confirm the original upload before saving again.');
    current = {...result.state,properties:current.properties};
    sessionStorage.removeItem(pendingKey());
    return {...result,state:current};
  } catch(error:any) {
    if ([409,413,422].includes(error.status)) sessionStorage.removeItem(pendingKey());
    if (error.status===409) current = await loadProperty(propertyId);
    throw error;
  }
}
