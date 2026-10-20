import '../css/marketplace-management.css';

// Presentation only: no network, local storage, tenant selection or mutations.
const sidebar = document.getElementById('mm-navigation');
const workspace = document.querySelector('[data-mm-workspace]');
const menu = document.querySelector('[data-mm-menu]');
const backdrop = document.querySelector('[data-mm-backdrop]');
const mobile = matchMedia('(max-width: 800px)');
let navOpener = null;
function setNav(open, restore = true) {
    open = open && mobile.matches;
    // WebKit does not always focus a clicked button, and inert can blur it.
    // The menu is this drawer's explicit opener, so retain it before inert.
    if (open) navOpener = menu;
    document.body.classList.toggle('mm-nav-open', open);
    sidebar.inert = mobile.matches && !open;
    workspace.inert = open;
    backdrop.hidden = !open;
    menu.setAttribute('aria-expanded', String(open));
    if (open) {
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        sidebar.querySelector('[data-mm-close-nav]').focus();
    } else {
        sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal');
        if (restore && navOpener) { navOpener.focus(); navOpener = null; }
    }
}
menu?.addEventListener('click', () => setNav(true));
backdrop?.addEventListener('click', () => setNav(false));
sidebar?.querySelector('[data-mm-close-nav]').addEventListener('click', () => setNav(false));
mobile.addEventListener('change', () => setNav(false));
if (sidebar) setNav(false, false);
document.addEventListener('keydown', event => {
    if (!document.body.classList.contains('mm-nav-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); setNav(false); }
    if (event.key === 'Tab') {
        const items = [...sidebar.querySelectorAll('a,button')].filter(el => el.getClientRects().length);
        const first = items[0], last = items.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});

const search = document.querySelector('[data-mm-search]');
const status = document.querySelector('[data-mm-status]');
const rows = [...document.querySelectorAll('[data-mm-row]')];
const count = document.querySelector('[data-mm-count]');
const empty = document.querySelector('[data-mm-empty]');
const records = [...document.querySelectorAll('details[data-mm-detail]')];
let restoring = false;
function urlState(values, push = false) {
    const url = new URL(location.href);
    Object.entries(values).forEach(([key, value]) => value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
    if (url.href !== location.href) history[push ? 'pushState' : 'replaceState'](null, '', url);
}
function applyFilters() {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    let shown = 0;
    rows.forEach(row => {
        const matches = (row.dataset.mmSearch || row.textContent).toLocaleLowerCase().includes(query)
            && (!status?.value || row.dataset.mmStatus === status.value);
        row.hidden = !matches;
        if (matches) shown++;
    });
    if (count) count.textContent = shown + ' of ' + rows.length + ' loaded';
    if (empty) empty.hidden = shown !== 0;
}
search?.addEventListener('input', () => { applyFilters(); urlState({ q: search.value }); });
status?.addEventListener('change', () => { applyFilters(); urlState({ status: status.value }); });
document.querySelector('[data-mm-clear]')?.addEventListener('click', () => {
    if (search) search.value = '';
    if (status) status.value = '';
    applyFilters(); urlState({ q: '', status: '' }); search?.focus();
});
records.forEach(record => record.addEventListener('toggle', () => {
    if (restoring) return;
    const selected = new URL(location.href).searchParams.get('detail');
    if (record.open && selected !== record.dataset.mmDetail) {
        records.forEach(other => { if (other !== record) other.open = false; });
        urlState({ detail: record.dataset.mmDetail }, true);
    } else if (!record.open && selected === record.dataset.mmDetail && !records.some(item => item.open)) {
        urlState({ detail: '' }, true);
    }
}));
function restoreState() {
    restoring = true;
    const params = new URL(location.href).searchParams;
    if (search) search.value = params.get('q') || '';
    if (status) status.value = [...status.options].some(option => option.value === params.get('status')) ? params.get('status') : '';
    records.forEach(record => { record.open = record.dataset.mmDetail === params.get('detail'); });
    applyFilters();
    const selected = records.find(record => record.open && !record.hidden);
    if (selected) requestAnimationFrame(() => selected.scrollIntoView({ block: 'nearest' }));
    // Native toggle events are queued tasks; wait until they have been delivered.
    setTimeout(() => { restoring = false; }, 0);
}
addEventListener('popstate', restoreState);
restoreState();

// Maker hash destinations stay useful without JS; enhance to one focused pane.
const paneLinks = [...document.querySelectorAll('[data-mm-pane-link]')];
const panes = [...document.querySelectorAll('[data-mm-pane]')];
function showPane() {
    if (!panes.length) return;
    const id = location.hash.slice(1);
    if (id === 'new-product') document.getElementById('new-product').open = true;
    const selected = panes.find(pane => pane.id === id) || panes.find(pane => pane.id === 'products');
    panes.forEach(pane => { pane.hidden = pane !== selected && !(selected.id === 'products' && pane.id === 'new-product'); });
    paneLinks.forEach(link => link.toggleAttribute('aria-current', false));
    const active = paneLinks.find(link => link.dataset.mmPaneLink === (selected.id === 'new-product' ? 'products' : selected.id));
    active?.setAttribute('aria-current', 'page');
    const title = document.querySelector('[data-mm-page-title]');
    if (title && active) title.textContent = active.textContent.trim();
}
paneLinks.forEach(link => link.addEventListener('click', () => {
    if (mobile.matches) setNav(false);
}));
addEventListener('hashchange', showPane);
showPane();
if (document.body.dataset.mmRole === 'maker') {
    document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
        const pane = form.closest('[data-mm-pane]');
        if (!pane) return;
        // A redirect without a fragment inherits this one in the browser.
        // The server still receives the exact existing command path and fields.
        const action = new URL(form.action, location.href);
        action.hash = pane.id;
        form.action = action.href;
    }));
}
document.body.dataset.mmReady = 'true';
