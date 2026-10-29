(function () {
  'use strict';
  const host = document.getElementById('eb-teams-search'), root = document.getElementById('team-work-app');
  if (!host || !root || !globalThis.EverbranchSearch) return;
  const bridge = () => globalThis.TeamSearchBridge;
  const endpoint = params => { const url = new URL(host.dataset.searchUrl, location.origin); for (const [key, value] of Object.entries(params)) if (value !== '' && value != null) url.searchParams.set(key, value); return url; };
  const scope = root.dataset.user + ':' + root.dataset.workspaceId;
  const palette = EverbranchSearch.palette({
    host, scope, id: 'eb-teams', available: () => !!bridge()?.available(),
    beforeOpen: () => document.body.classList.remove('teams-menu-open'),
    async search({ q, filters, cursor, limit, recent, signal }) {
      const url = endpoint({ q, ...filters, cursor, limit }); recent.forEach(id => url.searchParams.append('recent[]', id));
      return bridge().request(url, { signal, cache: 'no-store' });
    },
    async open(item, current) {
      if (!current() || !bridge()?.available()) return false;
      if (item.type === 'command') {
        const fresh = await bridge().request(endpoint({ q: '>' + item.title, limit: 25 }), { cache: 'no-store' });
        item = fresh.items.find(row => row.id === item.id);
        if (!item || !current()) throw new Error('This command is no longer available.');
      } else {
        const fresh = await bridge().request(endpoint({ ref: item.id }), { cache: 'no-store' });
        if (!current()) return false;
        item = fresh.item;
      }
      if (['person', 'message', 'update'].includes(item.type)) {
        if (!bridge().readyToNavigate()) return false;
        const dialog = document.createElement('dialog'), title = document.createElement('h2'), detail = document.createElement('p'), close = document.createElement('button');
        dialog.className = 'eb-search-detail'; title.id = 'eb-search-detail-title'; dialog.setAttribute('aria-labelledby', title.id);
        title.textContent = item.title; detail.textContent = item.detail || 'Current workspace member'; detail.style.whiteSpace = 'pre-wrap'; close.textContent = 'Close'; close.onclick = () => dialog.close(); dialog.append(title, detail, close); document.body.append(dialog); dialog.addEventListener('close', () => { dialog.remove(); palette.input.focus(); }, { once: true }); dialog.showModal(); return true;
      }
      return bridge().open(item.target, current);
    },
  });
  globalThis.TeamSearchPalette = palette;
  document.addEventListener('team:search-refresh', () => { if (bridge()?.available()) { host.hidden = false; palette.setScope(scope); palette.refresh(); } });
  const retire = () => { palette.close(false); palette.setScope('retired:' + crypto.randomUUID()); host.hidden = true; };
  document.addEventListener('team:access-lost', retire);
  document.addEventListener('visibilitychange', () => { if (document.hidden) palette.close(false); });
  addEventListener('pagehide', retire);
})();
