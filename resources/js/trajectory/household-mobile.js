const instances = new WeakMap();

/** Responsive presentation only. Existing navigation, periods and permissions stay authoritative. */
export function initHouseholdMobile(root, options = {}) {
  if (!root || instances.has(root)) return instances.get(root);
  const doc = root.ownerDocument;
  const win = doc.defaultView;
  const drawer = root.querySelector('#tr-mobile-drawer');
  const workspace = root.querySelector('.tr-workspace');
  const trigger = root.querySelector('#tr-mobile-menu');
  const closeButton = root.querySelector('#tr-mobile-close');
  const overlay = root.querySelector('#tr-mobile-overlay');
  const splash = root.querySelector('#tr-mobile-splash');
  const skip = root.querySelector('#tr-splash-skip');
  const content = root.querySelector('#tr-content');
  if (!drawer || !workspace || !trigger || !overlay) return null;
  const small = win.matchMedia('(max-width: 900px)');
  const reduced = win.matchMedia('(prefers-reduced-motion: reduce)');
  const storageKey = 'trajectory:introduction-seen:v1';
  let open = false, introducing = false, ready = false, previousFocus = null;
  let savedOverflow = null;
  const cleanup = [];
  const listen = (node, type, callback) => {
    if (!node) return;
    node.addEventListener(type, callback);
    cleanup.push(() => node.removeEventListener(type, callback));
  };
  function lockScroll(lock) {
    if (lock && savedOverflow === null) {
      savedOverflow = doc.body.style.overflow;
      doc.body.style.overflow = 'hidden';
    } else if (!lock && savedOverflow !== null) {
      doc.body.style.overflow = savedOverflow;
      savedOverflow = null;
    }
  }
  function setInert(node, value) {
    node.inert = value;
    if (value) node.setAttribute('inert', '');
    else node.removeAttribute('inert');
  }
  function sync() {
    root.classList.add('tr-mobile-enhanced');
    root.classList.toggle('tr-drawer-open', open);
    root.classList.toggle('tr-introducing', introducing);
    trigger.setAttribute('aria-expanded', String(open));
    overlay.hidden = !open;
    if (small.matches) {
      drawer.setAttribute('aria-hidden', String(!open));
      if (open) {
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
      } else {
        drawer.removeAttribute('role');
        drawer.removeAttribute('aria-modal');
      }
    } else {
      drawer.removeAttribute('aria-hidden');
      drawer.removeAttribute('role');
      drawer.removeAttribute('aria-modal');
    }
    setInert(drawer, introducing || (small.matches && !open));
    setInert(workspace, introducing || open);
    lockScroll(introducing || open);
  }
  function close(restore = true) {
    if (!open) return;
    open = false;
    sync();
    if (restore && trigger.isConnected) trigger.focus({preventScroll: true});
  }
  function show() {
    if (!small.matches || introducing) return;
    previousFocus = doc.activeElement;
    open = true;
    sync();
    (closeButton || drawer).focus({preventScroll: true});
  }
  function finish() {
    if (ready) return;
    ready = true;
    introducing = false;
    if (splash) splash.hidden = true;
    try { win.sessionStorage.setItem(storageKey, '1'); } catch { /* Storage is optional. */ }
    sync();
    if (doc.activeElement === skip) (previousFocus?.isConnected ? previousFocus : trigger).focus({preventScroll: true});
  }
  function visibleFocusable() {
    return [...drawer.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex="0"]')].filter(node => !node.hidden && node.getClientRects().length);
  }
  listen(trigger, 'click', () => open ? close() : show());
  listen(closeButton, 'click', () => close());
  listen(overlay, 'click', () => close());
  listen(skip, 'click', finish);
  listen(drawer, 'click', event => {
    if (event.target.closest('[data-tab],a[href]')) close();
  });
  listen(doc, 'keydown', event => {
    if (!open) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab') return;
    const nodes = visibleFocusable();
    const first = nodes[0], last = nodes.at(-1);
    if (!first) { event.preventDefault(); drawer.focus(); return; }
    if (event.shiftKey && (doc.activeElement === first || !drawer.contains(doc.activeElement))) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && (doc.activeElement === last || !drawer.contains(doc.activeElement))) {
      event.preventDefault(); first.focus();
    }
  });
  const onMedia = () => { if (!small.matches) open = false; sync(); };
  const onReduced = () => { if (reduced.matches) finish(); };
  small.addEventListener('change', onMedia);
  reduced.addEventListener('change', onReduced);
  cleanup.push(() => small.removeEventListener('change', onMedia), () => reduced.removeEventListener('change', onReduced));
  const observer = new win.MutationObserver(() => {
    if (content?.getAttribute('aria-busy') === 'false') finish();
  });
  if (content) observer.observe(content, {attributes: true, attributeFilter: ['aria-busy']});
  let seen = false;
  try { seen = win.sessionStorage.getItem(storageKey) === '1'; } catch { /* No preference is assumed when storage is blocked. */ }
  introducing = !!splash && !seen && !reduced.matches && content?.getAttribute('aria-busy') === 'true';
  if (splash) splash.hidden = !introducing;
  if (introducing) {
    previousFocus = doc.activeElement;
    skip?.focus({preventScroll: true});
  }
  sync();
  if (!introducing && content?.getAttribute('aria-busy') === 'false') finish();
  const api = {
    ready: finish,
    open: show,
    close,
    destroy() {
      observer.disconnect();
      cleanup.forEach(fn => fn());
      open = false;
      introducing = false;
      sync();
      setInert(drawer, false);
      setInert(workspace, false);
      drawer.removeAttribute('aria-hidden');
      drawer.removeAttribute('role');
      drawer.removeAttribute('aria-modal');
      root.classList.remove('tr-mobile-enhanced', 'tr-drawer-open', 'tr-introducing');
      if (splash) splash.hidden = true;
      instances.delete(root);
    },
  };
  instances.set(root, api);
  return api;
}
