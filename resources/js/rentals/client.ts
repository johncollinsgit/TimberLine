const root = document.getElementById('rental-workspace')!;
export const bootstrap = JSON.parse(root.dataset.bootstrap!);
let current: any;
let propertyId = '';
const pendingKey = () => `rental-command:${bootstrap.tenant}:${bootstrap.userId}:${propertyId}`;
type Pending = {path: string; input: Record<string, any>};
function pending(): Pending | null {
  const value = sessionStorage.getItem(pendingKey());
  if (!value) return null;
  try { return JSON.parse(value); } catch { throw Error('The saved change needs review before another save.'); }
}
export function hasPending() { return !!pending(); }
export function hasPendingCreate() { return !!sessionStorage.getItem(`rental-create:${bootstrap.tenant}:${bootstrap.userId}`); }
async function request(path: string, options: RequestInit = {}) {
  const url = new URL(bootstrap.baseUrl + path, location.origin);
  url.searchParams.set('tenant', bootstrap.tenant);
  let response: Response;
  try {
    response = await fetch(url, {...options, credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(30000),
      headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')!.content, ...options.headers}});
  } catch { throw Error(options.method==='POST'?'The save response may be delayed. Review or retry the original change before saving again.':'The workspace could not be loaded. Try again when the connection is available.'); }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = Object.assign(new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'This request could not be completed.'), {status:response.status});
    throw error;
  }
  return data;
}
export async function loadProperty(id?: string) {
  const list = await request('/properties');
  propertyId = id || new URLSearchParams(location.search).get('property') || list.properties[0]?.id || '';
  if (!propertyId) return {needsSetup:true, ...list};
  current = await request(`/properties/${encodeURIComponent(propertyId)}`);
  const address = new URL(location.href); address.searchParams.set('property',propertyId); history.replaceState(null,'',address);
  current.properties = list.properties;
  const saved = pending();
  if (saved) {
    const receipt = await request(`/properties/${propertyId}/commands/${saved.input.requestKey}`);
    if (receipt.applied) { sessionStorage.removeItem(pendingKey()); current = {...receipt.state, properties:list.properties}; }
  }
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
  return deliver(saved);
}
export async function uploadFile(file: File, teamId = '') {
  if (pending()) throw Error('Review the original saved change first.');
  const input = new FormData();
  input.append('file',file); input.append('teamId',teamId); input.append('requestKey',crypto.randomUUID()); input.append('version',String(current.version));
  const result = await request(`/properties/${propertyId}/files`,{method:'POST',body:input});
  current = {...result.state,properties:current.properties};
  return {...result,state:current};
}
