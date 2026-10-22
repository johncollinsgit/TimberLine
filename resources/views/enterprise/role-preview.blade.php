{{-- Private current administrator's read-only intersection. No role switching or mutation transport. --}}
@if(($enterprisePresentation['runtime'] ?? 'private') === 'private' && config('enterprise.runtime') === true && config('enterprise.operations_enabled') === true && in_array($enterpriseRole ?? '', ['owner', 'admin'], true))
<section id="enterprise-role-preview" class="erp-panel" aria-labelledby="erp-heading" data-endpoint="{{ url('/enterprise/role-preview').'?'.http_build_query(['tenant' => $tenant->slug]) }}" hidden>
    <style>
        #enterprise-role-preview{padding:24px;margin:20px 0;background:#fff;border:1px solid #d8dfd0;border-radius:9px;min-width:0;max-width:100%;overflow-wrap:anywhere}#enterprise-role-preview *{box-sizing:border-box;min-width:0}#enterprise-role-preview .erp-eyebrow{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#5b694e}#enterprise-role-preview h2{font:28px/1.2 Georgia,serif;margin:8px 0 12px}#enterprise-role-preview h3{font:21px/1.3 Georgia,serif;margin:18px 0 10px}#enterprise-role-preview p,#enterprise-role-preview li{font-size:13px;line-height:1.65}#enterprise-role-preview .erp-muted{color:#64715c}#enterprise-role-preview .erp-controls{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:18px 0}#enterprise-role-preview label{display:block;flex:1 1 260px;font-size:12px}#enterprise-role-preview select{display:block;width:100%;max-width:100%;min-height:44px;padding:10px 12px;margin-top:6px;font:inherit;text-overflow:ellipsis;white-space:nowrap;overflow:hidden;appearance:none;-webkit-appearance:none;padding-right:38px;border:1px solid #b9c9af;background-color:white;background-image:linear-gradient(45deg,transparent 50%,#244d3d 50%),linear-gradient(135deg,#244d3d 50%,transparent 50%);background-position:right 17px center,right 11px center;background-size:6px 6px;background-repeat:no-repeat;border-radius:5px}#enterprise-role-preview button,#enterprise-role-preview a{min-height:44px}#enterprise-role-preview button{padding:10px 16px;font:inherit;border:1px solid #b9c9af;border-radius:5px;background:#f6f8f1;color:#244b37;cursor:pointer}#enterprise-role-preview button:disabled,#enterprise-role-preview select:disabled{opacity:.6;cursor:not-allowed}#enterprise-role-preview :focus-visible{outline:3px solid #ae892b;outline-offset:3px}#enterprise-role-preview .erp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}#enterprise-role-preview ul{list-style:none;margin:0;padding:0}#enterprise-role-preview li{padding:10px 0;border-bottom:1px solid #e5e9df}#enterprise-role-preview a{display:flex;align-items:center;color:#24543d;font-weight:600;text-decoration:underline;text-underline-offset:3px;overflow-wrap:anywhere}#enterprise-role-preview .erp-meta{display:block;color:#64715c;font-size:12px}#enterprise-role-preview [data-erp-status]{min-height:24px}#enterprise-role-preview [hidden]{display:none!important}@media(max-width:760px){#enterprise-role-preview{padding:18px}#enterprise-role-preview .erp-grid{grid-template-columns:minmax(0,1fr)}#enterprise-role-preview .erp-controls{align-items:stretch}#enterprise-role-preview .erp-controls button{width:100%}}
    </style>
    <p class="erp-eyebrow">Admin read-only data preview</p>
    <h2 id="erp-heading">Check a member's current visibility</h2>
    <p>This shows the current department's project and request summaries visible to both you and an existing member. You keep your own signed-in identity and permissions.</p>
    <p class="erp-muted">Descriptions, internal messages, files, finance and mailbox content are excluded. Opening a record returns to your own authorized workspace.</p>
    <div class="erp-controls"><label for="erp-member">Existing active, verified member<select id="erp-member" data-erp-member disabled><option value="">Choose a current member</option></select></label><button type="button" data-erp-refresh disabled>Refresh current access</button></div>
    <p data-erp-status role="status" aria-live="polite"></p>
    <p class="erp-muted" data-erp-as-of></p>
    <div data-erp-roster-wrap hidden><h3>Users and current department memberships</h3><p class="erp-muted">These are saved tenant and department roles, including read-only viewers.</p><ul data-erp-roster></ul><p class="erp-muted" data-erp-roster-coverage></p></div>
    <div data-erp-preview hidden><h3 data-erp-member-heading></h3><p data-erp-scope></p><p class="erp-muted" data-erp-coverage></p><div class="erp-grid"><section><h3>Returned projects</h3><ul data-erp-projects></ul></section><section><h3>Returned requests</h3><ul data-erp-requests></ul></section></div></div>
</section>
<script>
(() => {
    'use strict';
    const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
    const ID = /^[1-9][0-9]*$/;
    function mount(root, boot, dependencies = {}) {
        if (!root || root.dataset.rolePreviewMounted || boot.runtime !== 'private' || !['owner', 'admin'].includes(boot.role) || !boot.identity?.viewer || !boot.identity?.workspace) return null;
        root.dataset.rolePreviewMounted = 'true';
        const doc = dependencies.document || document, events = dependencies.events || doc;
        let transport = dependencies.transport || null;
        const get = name => root.querySelector(`[data-erp-${name}]`);
        let generation = 0, controller = null, current = null, selected = '', roster = [], busy = false;
        function clear() {
            generation++; controller?.abort(); controller = null; selected = ''; roster = []; busy = false;
            get('member').replaceChildren();
            const option = doc.createElement('option'); option.value = ''; option.textContent = 'Choose a current member'; get('member').append(option); get('member').value = '';
            for (const name of ['roster', 'projects', 'requests']) get(name).replaceChildren();
            for (const name of ['status', 'as-of', 'scope', 'coverage', 'member-heading', 'roster-coverage']) get(name).textContent = '';
            get('roster-wrap').hidden = true; get('preview').hidden = true; get('member').disabled = true; get('refresh').disabled = true;
            root.removeAttribute('aria-busy');
        }
        function eligible(state) {
            return state?.loaded === true && state.section === 'people' && state.features?.operations === true && UUID.test(String(state.area || ''))
                && String(state.viewer_id || '') === String(boot.identity.viewer);
        }
        function url(member = '') {
            const target = new URL(boot.endpoint, location.href);
            if (target.origin !== location.origin || target.username || target.password) throw new Error('The current workspace endpoint is unavailable.');
            target.searchParams.set('area_id', current.area); target.searchParams.delete('member_id');
            if (member) target.searchParams.set('member_id', member);
            return target.href;
        }
        function text(parent, tag, value, className = '') {
            const node = doc.createElement(tag); node.textContent = String(value ?? ''); if (className) node.className = className; parent.append(node); return node;
        }
        const membership = row => `${row.tenant_role} · ${row.area_role || 'No saved department membership'}`;
        function link(parent, row, section) {
            const anchor = text(parent, 'a', row.name || row.title || 'Untitled record');
            const target = new URL(boot.routes.back, location.href);
            if (target.origin !== location.origin || target.username || target.password) throw new Error('The current workspace link is unavailable.');
            target.searchParams.set('area_id', current.area); target.searchParams.set('section', section); target.searchParams.set('selected', row.id);
            anchor.href = target.href;
            anchor.addEventListener('click', event => {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (event.button ?? 0) !== 0) return;
                event.preventDefault();
                const area = current?.area; clear(); root.hidden = true;
                events.dispatchEvent(new CustomEvent('enterprise:navigate', {detail: {area, section, selected: row.id}}));
            });
        }
        function render(result, requested) {
            if (result.read_only !== true || result.impersonation !== false || String(result.observer?.id) !== String(boot.identity.viewer)
                || String(result.scope?.tenant_id) !== String(boot.identity.workspace) || result.scope?.area_id !== current.area
                || !Array.isArray(result.roster) || result.roster.some(row => !ID.test(String(row.id)))) throw new Error('The authorized preview context changed. Refresh the current workspace.');
            roster = result.roster;
            get('member').replaceChildren(); const choice = doc.createElement('option'); choice.value = ''; choice.textContent = 'Choose a current member'; get('member').append(choice);
            get('roster').replaceChildren();
            for (const member of roster) {
                const option = doc.createElement('option'); option.value = String(member.id); option.textContent = `${member.name} · ${membership(member)}`; get('member').append(option);
                const row = doc.createElement('li'); text(row, 'strong', member.name); text(row, 'span', membership(member), 'erp-meta'); get('roster').append(row);
            }
            get('roster-wrap').hidden = false;
            get('roster-coverage').textContent = result.roster_partial ? 'Partial roster: the server returned its bounded set of currently authorized members. No hidden-member total is shown.' : `${roster.length} returned current member${roster.length === 1 ? '' : 's'}.`;
            get('as-of').textContent = `Access checked ${result.as_of || 'just now'}. Refresh to check current permissions.`;
            const preview = result.preview;
            if (requested) {
                if (!preview || String(preview.member?.id) !== requested || !roster.some(row => String(row.id) === requested)
                    || !Array.isArray(preview.projects) || !Array.isArray(preview.requests) || !Array.isArray(preview.areas)
                    || [...preview.projects, ...preview.requests].some(row => !UUID.test(String(row.id)) || row.area_id !== current.area)
                    || preview.areas.some(row => row.id !== current.area)) throw new Error('The selected member or record scope changed. Refresh current access.');
                selected = requested; get('member').value = requested;
                get('member-heading').textContent = `Read-only preview: ${preview.member.name}`;
                get('scope').textContent = preview.areas.map(row => `${row.name} · ${row.visibility === 'shared' ? 'Shared workspace' : 'Department-only workspace'}`).join('; ');
                const partial = preview.coverage?.projects_partial || preview.coverage?.requests_partial || preview.coverage?.authorization_graph_partial;
                get('coverage').textContent = `${preview.projects.length} returned projects · ${preview.requests.length} returned requests. Counts cover only the authorized intersection.${partial ? ' This is a bounded partial result; it does not establish that other records are absent.' : ''}`;
                for (const [key, section] of [['projects', 'projects'], ['requests', 'requests']]) {
                    get(key).replaceChildren();
                    if (!preview[key].length) text(get(key), 'li', 'No records returned in this authorized intersection.');
                    for (const record of preview[key]) {
                        const row = doc.createElement('li'); link(row, record, section);
                        text(row, 'span', section === 'projects' ? (record.archived ? 'Archived project' : 'Current project') : `${record.status}${record.due_on ? ` · Due ${record.due_on}` : ''}`, 'erp-meta'); get(key).append(row);
                    }
                }
                get('preview').hidden = false;
                get('status').textContent = 'Read-only preview loaded. You are still signed in as yourself.';
            } else get('status').textContent = roster.length ? 'Choose an existing member to check current shared visibility.' : 'No current members were returned for this department.';
        }
        async function load(member = '') {
            if (!eligible(current)) return;
            // The partial can precede the common transport script in the document.
            transport ||= globalThis.EverbranchTransport?.create(boot);
            if (!transport) { get('status').textContent = 'The current workspace transport is unavailable. Refresh the workspace to check access.'; return; }
            const snapshot = current, requested = String(member), stamp = ++generation;
            controller?.abort(); controller = new AbortController();
            selected = requested; busy = true; get('preview').hidden = true; get('projects').replaceChildren(); get('requests').replaceChildren();
            for (const name of ['member-heading', 'scope', 'coverage', 'as-of']) get(name).textContent = '';
            get('member').disabled = true; get('refresh').disabled = true; root.setAttribute('aria-busy', 'true'); get('status').textContent = 'Checking current access…';
            try {
                const result = await transport.request(url(requested), undefined, {signal: controller.signal});
                if (stamp !== generation || current !== snapshot || !eligible(current)) return;
                render(result, requested);
            } catch (error) {
                if (stamp !== generation || current !== snapshot) return;
                clear();
                get('status').textContent = [401, 403, 404].includes(error.status) ? 'This preview is unavailable in your current access. No previous member data is retained.' : 'The server could not confirm current access. Refresh to check again.';
                get('refresh').disabled = !eligible(current);
            } finally {
                if (stamp === generation && current === snapshot && eligible(current)) {
                    busy = false;
                    root.removeAttribute('aria-busy'); get('refresh').disabled = false; get('member').disabled = busy || !roster.length;
                }
            }
        }
        function state(event) {
            clear(); current = event.detail; root.hidden = !eligible(current);
            if (!root.hidden) load();
        }
        get('member').addEventListener('change', () => { const value = String(get('member').value); if (!value || roster.some(row => String(row.id) === value)) load(value); });
        get('refresh').addEventListener('click', () => load(selected));
        events.addEventListener('enterprise:workspace-state', state);
        clear(); root.hidden = true;
        return Object.freeze({destroy() { clear(); current = null; root.hidden = true; events.removeEventListener('enterprise:workspace-state', state); }});
    }
    globalThis.EverbranchRolePreview = Object.freeze({mount});
})();
</script>
<script>
EverbranchRolePreview.mount(document.getElementById('enterprise-role-preview'), @json(array_merge($enterprisePresentation, ['role' => $enterpriseRole, 'endpoint' => url('/enterprise/role-preview').'?'.http_build_query(['tenant' => $tenant->slug])])));
</script>
@endif
