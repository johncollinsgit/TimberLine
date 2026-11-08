import '../../../public/js/everbranch-search.js';
import '../../../public/css/everbranch-search.css';
import '../../css/team-universal-search.css';

export function installUniversalSearch(root) {
  const host = root.querySelector('[data-team-universal-search]');
  if (!host || !host.dataset.searchUrl) return;
  const bridge = () => globalThis.TeamSearchBridge;
  const scope = `${root.dataset.user}:${root.dataset.workspaceId}`;
  const endpoint = params => {
    const url = new URL(host.dataset.searchUrl, location.origin);
    for (const [key, value] of Object.entries(params)) if (value !== '' && value != null) url.searchParams.set(key, value);
    return url;
  };
  const coverage = document.createElement('p');
  coverage.className = 'teams-search-coverage'; coverage.hidden = true;
  let query = '';
  const palette = globalThis.EverbranchSearch.palette({
    host, scope, id: 'teams-universal', collapsibleFilters: true, available: () => !!bridge()?.available(),
    beforeOpen: () => document.body.classList.remove('teams-menu-open'),
    async search({ q, filters, cursor, limit, recent, signal, onProgress }) {
      query = q;
      const request = async params => {
        const url = endpoint({ q, ...filters, ...params });
        recent.forEach(id => url.searchParams.append('recent[]', id));
        return bridge().request(url, { signal, cache: 'no-store' });
      };
      const result = await request({ cursor, limit, ...(!cursor ? { stage: 'teams' } : {}) });
      if (!signal.aborted) {
        coverage.textContent = result.coverage?.notice || '';
        coverage.hidden = !coverage.textContent;
      }
      if (cursor || !result.deferred_apps || signal.aborted) return result;
      if (onProgress?.(result) === false) return result;
      try {
        const apps = await request({ stage: 'apps', cursor: result.next_cursor, limit: Math.max(1, limit - result.items.length) });
        if (signal.aborted) return result;
        const types = new Map([...(result.facets?.type || []), ...(apps.facets?.type || [])].map(type => [type.id, type]));
        return { ...result, items: [...new Map([...(result.items || []), ...(apps.items || [])].map(item => [item.id, item])).values()], next_cursor: apps.next_cursor,
          facets: { ...result.facets, type: [...types.values()] }, coverage: result.coverage, deferred_apps: false };
      } catch (error) {
        if (signal.aborted || error.name === 'AbortError') throw error;
        if (error.status === 422) return { ...result, next_cursor: null, partial_notice: 'Search again to refresh these results.' };
        return { ...result, partial_notice: 'Other apps could not load. Use View all results to retry.' };
      }
    },
    async open(item, current) {
      if (!current() || !bridge()?.available()) return false;
      if (item.type === 'command') {
        const fresh = await bridge().request(endpoint({ q: '>' + item.title, limit: 25 }), { cache: 'no-store' });
        item = fresh.items.find(row => row.id === item.id);
      } else {
        const fresh = await bridge().request(endpoint({ q: query, ref: item.id }), { cache: 'no-store' });
        item = fresh.item;
      }
      if (!current()) return false;
      if (!item) throw new Error('This item is no longer available. Search again.');
      if (item.target?.kind === 'app') {
        if (!bridge().readyToNavigate()) return false;
        const destination = new URL(item.target.url, location.origin);
        if (destination.origin !== location.origin || destination.searchParams.get('tenant') !== root.dataset.workspaceId) throw new Error('This destination is not available in the current workspace.');
        location.assign(destination.href);
        return true;
      }
      if (['person', 'message', 'update'].includes(item.type)) {
        if (!bridge().readyToNavigate()) return false;
        const dialog = document.createElement('dialog'), title = document.createElement('h2'), detail = document.createElement('p'), close = document.createElement('button');
        dialog.className = 'eb-search-detail'; title.id = 'teams-universal-detail'; dialog.setAttribute('aria-labelledby', title.id);
        title.textContent = item.title; detail.textContent = item.detail || 'Current workspace member'; detail.style.whiteSpace = 'pre-wrap';
        close.textContent = 'Close'; close.onclick = () => dialog.close(); dialog.append(title, detail, close); document.body.append(dialog);
        dialog.addEventListener('close', () => { dialog.remove(); palette.input.focus(); }, { once: true }); dialog.showModal();
        return true;
      }
      return bridge().open(item.target, current, { fromSearch: true });
    },
  });
  palette.input.placeholder = 'Search everything you can access';
  palette.input.setAttribute('aria-label', 'Search everything you can access in this workspace');
  palette.input.setAttribute('autocorrect', 'off'); palette.input.setAttribute('autocapitalize', 'none'); palette.input.spellcheck = false;
  palette.panel.querySelector('.eb-search-status').after(coverage);
  globalThis.TeamSearchPalette = palette;
  document.addEventListener('team:search-refresh', () => { if (bridge()?.available()) { host.hidden = false; palette.setScope(scope); palette.refresh(); } });
  const retire = () => { palette.close(false); palette.setScope('retired:' + crypto.randomUUID()); host.hidden = true; coverage.textContent = ''; };
  document.addEventListener('team:access-lost', retire);
  document.addEventListener('visibilitychange', () => { if (document.hidden) palette.close(false); });
  addEventListener('pagehide', retire);
  return palette;
}
