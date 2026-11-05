import '../../css/stockists/admin.css';

const root = document.querySelector('[data-stockist-admin]');
if (root) {
    const base = root.dataset.api, status = root.querySelector('[data-status]'), rows = root.querySelector('[data-rows]');
    const dialog = root.querySelector('dialog'), editor = root.querySelector('[data-editor-form]');
    let data, returnFocus;
    async function headers() {
        const fn = window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;
        if (typeof fn !== 'function') throw new Error('Shopify verification is loading. Reload Store locator if this continues.');
        return {...await fn(), Accept:'application/json', 'Content-Type':'application/json'};
    }
    async function api(path = '', method = 'GET', body) {
        const response = await fetch(base + path, {method, headers:await headers(), ...(body ? {body:JSON.stringify(body)} : {})});
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(Object.values(payload.errors || {}).flat().join(' ') || payload.message || 'The directory could not be loaded.');
        return payload;
    }
    function cell(text) { const td = document.createElement('td'); td.textContent = text; return td; }
    function render() {
        rows.replaceChildren();
        const query = root.querySelector('[data-filter]').value.toLowerCase();
        for (const row of data.locations.filter(item => `${item.name} ${item.address}`.toLowerCase().includes(query))) {
            const tr = document.createElement('tr'), action = cell(''), button = document.createElement('button');
            button.type = 'button'; button.textContent = 'Edit'; button.setAttribute('aria-label', `Edit ${row.name} at ${row.address}`); button.addEventListener('click', () => open(row, button)); action.append(button);
            tr.append(cell(row.name), cell(row.address), cell(row.visible ? 'Included' : 'Hidden'), cell(`${row.latitude}, ${row.longitude}`), action); rows.append(tr);
        }
        root.querySelector('[data-summary]').textContent = `${data.locations.filter(item => item.visible).length} draft locations · ${data.published_count} published${data.published_at ? ` · Last published ${new Date(data.published_at).toLocaleString()}` : ''} · Map ${data.has_maps_key ? 'connected' : 'connection needed'}`;
        root.querySelector('[data-preview]').href = data.preview_url;
        root.querySelector('[data-rollback]').disabled = !data.has_previous;
        root.querySelector('[data-publish]').disabled = !data.has_maps_key || !data.locations.some(item => item.visible);
    }
    async function load() { data = (await api()).data; render(); }
    function open(row = {}, trigger) {
        returnFocus = trigger; editor.reset();
        for (const key of ['id','name','address','latitude','longitude','sort_order']) editor.elements[key].value = row[key] ?? (key === 'sort_order' ? data.locations.length : '');
        editor.elements.visible.checked = row.visible ?? true; editor.elements.featured.checked = row.featured ?? false;
        root.querySelector('[data-editor-status]').textContent = ''; dialog.showModal(); editor.elements.name.focus();
    }
    function close() { dialog.close(); returnFocus?.focus(); }
    async function action(button, work, success) {
        button.disabled = true; status.textContent = 'Saving…';
        try { await work(); await load(); status.textContent = success; }
        catch (error) { status.textContent = error.message; }
        finally { button.disabled = false; }
    }
    editor.addEventListener('submit', async event => {
        event.preventDefault(); const button = event.submitter; button.disabled = true;
        const input = Object.fromEntries(new FormData(editor));
        input.latitude = Number(input.latitude); input.longitude = Number(input.longitude); input.sort_order = Number(input.sort_order);
        input.featured = editor.elements.featured.checked; input.visible = editor.elements.visible.checked; input.revision = data.revision;
        const id = input.id; delete input.id;
        try { await api(`/locations${id ? '/'+id : ''}`, id ? 'PATCH' : 'POST', input); await load(); close(); status.textContent = 'Draft saved. Publish changes when ready.'; }
        catch (error) { root.querySelector('[data-editor-status]').textContent = error.message; }
        finally { button.disabled = false; }
    });
    root.querySelector('[data-add]').addEventListener('click', event => { if(data) open({}, event.currentTarget); });
    for (const button of root.querySelectorAll('[data-cancel]')) button.addEventListener('click', close);
    dialog.addEventListener('cancel', () => returnFocus?.focus());
    root.querySelector('[data-filter]').addEventListener('input', () => { if(data) render(); });
    root.querySelector('[data-publish]').addEventListener('click', event => {
        if (!data || !window.confirm(`Publish ${data.locations.filter(item => item.visible).length} locations to this store's directory?`)) return;
        action(event.currentTarget, () => api('/publish', 'POST', {revision:data.revision}), 'Directory published.');
    });
    root.querySelector('[data-rollback]').addEventListener('click', event => {
        if (!data || !window.confirm('Restore the previous published directory? Your draft will be preserved.')) return;
        action(event.currentTarget, () => api('/publish', 'POST', {revision:data.revision,rollback:true}), 'Previous publication restored.');
    });
    root.querySelector('[data-key-form]').addEventListener('submit', event => {
        event.preventDefault(); const form = event.currentTarget, key = form.elements.maps_key.value; form.elements.maps_key.value = '';
        action(event.submitter, () => api('/maps-key', 'POST', {revision:data.revision,maps_key:key}), 'Map connection saved.');
    });
    root.querySelector('[data-export]').addEventListener('click', () => {
        if (!data) return;
        const blob = new Blob([JSON.stringify({locations:data.locations},null,2)], {type:'application/json'}), link = document.createElement('a');
        link.href = URL.createObjectURL(blob); link.download = 'store-locations.json'; link.click(); setTimeout(() => URL.revokeObjectURL(link.href),1000);
    });
    for (const button of root.querySelectorAll('button:not([data-cancel])')) button.disabled = true;
    load().then(() => { for (const button of root.querySelectorAll('button')) button.disabled = false; render(); status.textContent = 'Draft edits stay private until published.'; })
        .catch(error => { status.textContent = error.message; });
}
