/* A bounded presentation preference only. No discussion, file or authorization data is stored. */
(() => {
  'use strict';
  const valid = value => value === 'center' || value === 'side';
  function runtimeBoundary(pathname) {
    const path=typeof pathname==='string'?pathname:'';
    if(path==='/enterprise-campus'||path.startsWith('/enterprise-campus/'))return 'campus';
    if(path==='/enterprise-owner'||path.startsWith('/enterprise-owner/'))return 'owner';
    if(path.startsWith('/platform/demos/'))return 'legacy-sample';
    return 'native';
  }
  function preferenceKey(runtime, workspace, viewer, boundary='native') {
    if (!['campus','owner','legacy-sample','native'].includes(boundary) || !['sample','private'].includes(runtime) || ![workspace,viewer].every(value => /^[A-Za-z0-9_-]{1,100}$/.test(String(value)))) return null;
    return `everbranch-enterprise:detail-placement:v1:${boundary}:${runtime}:${workspace}:${viewer}`;
  }
  function create(storage, key) {
    return Object.freeze({
      read() {try {const saved = key && storage?.getItem(key); return valid(saved) ? saved : 'center';} catch {return 'center';}},
      save(value) {if (!valid(value)) return false; try {if (key) storage?.setItem(key, value);} catch {} return true;},
    });
  }
  globalThis.EverbranchDetailPlacement = Object.freeze({valid,runtimeBoundary,preferenceKey,create});
})();
