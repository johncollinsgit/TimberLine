import '../../css/team-setup.css';

const retained = new Map();
export const hasTeamsSetupDrafts = () => [...retained.values()].some(state => state.dirty || state.intent || state.busy);
const copy = value => structuredClone(value);
const node = (tag, text = '', cls = '') => { const item = document.createElement(tag); item.textContent = text; item.className = cls; return item; };
const button = (text, run, cls = '') => { const item = node('button', text, cls); item.type = 'button'; item.addEventListener('click', run); return item; };
const titles = ['Home base', 'Work area & people', 'Defaults & costs', 'Review'];
const costNames = { skip: 'Set up costs later', blank: 'Start a blank cost policy', modern_forestry: 'Modern Forestry event costs' };
const rateNames = { hourly_rate_minor: 'Hourly pay', mileage_rate_minor: 'Mileage per mile', per_diem_minor: 'Per diem per day', commission_rate_bps: 'Commission %' };
function minor(value) {
  if (!/^\d+(\.\d{1,2})?$/.test(value)) throw new Error('Use a positive number with up to 2 decimal places for each rate.');
  const [whole, fraction = ''] = value.split('.'), result = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
  if (result > BigInt(Number.MAX_SAFE_INTEGER)) throw new Error('This rate is too large.'); return Number(result);
}
function displayRate(value) { return Number.isSafeInteger(value) ? (value / 100).toFixed(2) : ''; }

/** Owner setup uses one versioned server draft and exact retry identities. */
export function createTeamsSetup({ api, scope, openDialog, closeDialog, onComplete = () => {}, trigger = null }) {
  if (!scope || typeof scope !== 'string') throw new Error('The original Teams setup scope is required.');
  const state = retained.get(scope) || { status: null, values: null, rates: {}, step: 0, dirty: false, intent: null, busy: false, sealed: false };
  retained.set(scope, state);
  let mounted = null, generation = 0, autoOpened = false, destroyed = false;
  const active = () => !destroyed && mounted && mounted.body.isConnected && (mounted.body.closest('dialog')?.open ?? true) && !state.sealed;
  function triggerState() { if (trigger) { trigger.hidden = state.status?.can_manage !== true; trigger.textContent = state.status?.suggested || !state.status?.completed_at ? 'Finish Teams setup' : 'Teams setup'; } }
  function hydrate(status) {
    state.status = status;
    if (!state.values || (!state.dirty && !state.intent && !state.busy)) {
      const defaults = status.defaults || {}, areas = status.choices?.areas || [], existing = areas.find(area => area.id === defaults.area_id) || areas.find(area => area.profile === 'events') || areas[0];
      state.baseVersion = status.version;
      state.values = copy(status.draft?.values || { home_base: defaults.home_base || { address: '', timezone: '' }, area: existing ? { id: existing.id, expected_version: existing.version, name: existing.name, profile: existing.profile } : { name: '', profile: 'events' }, member_ids: defaults.member_ids || [], event_defaults: defaults.event_defaults || { teardown_minutes: 60, arrival_buffer_minutes: 0 }, cost_policy: { template: 'skip' }, connection: 'later' });
      state.step = status.draft?.step || 0;
      state.values.home_base ||= copy(defaults.home_base || { address: '', timezone: '' }); state.values.area ||= existing ? { id: existing.id, expected_version: existing.version, name: existing.name, profile: existing.profile } : { name: '', profile: 'events' };
      if (state.values.area.id && state.values.area.expected_version === undefined) state.values.area.expected_version = areas.find(area => area.id === state.values.area.id)?.version;
      state.values.member_ids ||= []; state.values.event_defaults ||= copy(defaults.event_defaults || { teardown_minutes: 60, arrival_buffer_minutes: 0 }); state.values.cost_policy ||= { template: 'skip' }; state.values.connection ||= 'later';
      for (const key of Object.keys(rateNames)) state.rates[key] = displayRate(state.values.cost_policy[key]);
    }
    triggerState();
  }
  async function read() {
    const status = await api('/setup'); if (destroyed) return null;
    if (status.can_manage !== true) { state.status = status; state.sealed = true; triggerState(); if (mounted?.body.isConnected) mounted.body.replaceChildren(node('p', 'Verify the original owner account to recover your setup draft.', 'teams-setup-notice')); return status; }
    hydrate(status); return status;
  }
  function feedback(text, isError = false) { state.notice = text; state.errorNotice = isError; if (!mounted?.body.isConnected) return; mounted.notice.textContent = text; mounted.notice.classList.toggle('teams-setup-error', isError); }
  function modified() { state.dirty = true; state.notice = ''; state.errorNotice = false; if (mounted?.notice) { mounted.notice.textContent = ''; mounted.notice.classList.remove('teams-setup-error'); } }
  function field(parent, title, value, set, { type = 'text', required = false, hint = '', min, max, step } = {}) {
    const label = node('label', '', 'teams-setup-field'); label.append(node('span', title)); const input = node('input'); input.type = type; input.value = value ?? ''; input.required = required; input.setAttribute('aria-label', title);
    if (min !== undefined) input.min = String(min); if (max !== undefined) input.max = String(max); if (step !== undefined) input.step = String(step);
    input.addEventListener('input', () => { if (state.busy || state.intent) return; set(input.value); modified(); }); label.append(input); if (hint) { const help = node('small', hint); help.id = 'setup-hint-' + crypto.randomUUID(); input.setAttribute('aria-describedby', help.id); label.append(help); } parent.append(label); return input;
  }
  function select(parent, title, options, value, set) {
    const label = node('label', '', 'teams-setup-field'); label.append(node('span', title)); const control = node('select'); control.setAttribute('aria-label', title);
    for (const option of options) { const item = node('option', option.name); item.value = option.id; control.append(item); } control.value = value;
    control.addEventListener('change', () => { if (state.busy || state.intent) return; set(control.value); modified(); render(); }); label.append(control); parent.append(label); return control;
  }
  function valuesForSave() {
    const values = copy(state.values); if (values.cost_policy.template !== 'skip') {
      for (const key of Object.keys(rateNames)) { const value = String(state.rates[key] || '').trim(); if (value) values.cost_policy[key] = minor(value); else delete values.cost_policy[key]; }
      values.cost_policy.currency = String(values.cost_policy.currency || '').trim().toUpperCase();
    } else values.cost_policy = { template: 'skip' };
    return values;
  }
  function reviewLine(parent, title, text) { const row = node('div', '', 'teams-setup-review-line'); row.append(node('dt', title), node('dd', text || 'Not set')); parent.append(row); }
  function render() {
    if (!active()) return;
    const body = mounted.body; body.replaceChildren();
    const root = node('div', '', 'teams-setup'), progress = node('ol', '', 'teams-setup-progress'); progress.setAttribute('aria-label', 'Teams setup steps');
    titles.forEach((title, index) => { const item = node('li'); if (index === state.step) item.setAttribute('aria-current', 'step'); if (index < state.step) item.className = 'teams-setup-done'; item.append(node('span', String(index + 1)), node('strong', title)); progress.append(item); });
    root.append(progress, node('h3', titles[state.step]));
    const form = node('form', '', 'teams-setup-form'), fields = node('div', '', 'teams-setup-fields'); form.append(fields);
    const values = state.values, choices = state.status.choices || {};
    if (state.step === 0) {
      field(fields, 'Home base address', values.home_base.address, value => values.home_base.address = value, { required: true, hint: 'Used for event departure and return plans.' });
      const zone = field(fields, 'Time zone', values.home_base.timezone, value => values.home_base.timezone = value, { required: true }); zone.placeholder = Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/New_York';
      const zoneList = node('datalist'); zoneList.id = 'teams-setup-timezones'; for (const value of Intl.supportedValuesOf?.('timeZone') || []) { const option = node('option'); option.value = value; zoneList.append(option); } zone.setAttribute('list', zoneList.id); fields.append(zoneList);
    } else if (state.step === 1) {
      const areas = choices.areas || [];
      if (areas.length) select(fields, 'Work area', [{ id: '', name: 'Create a new work area' }, ...areas.map(area => ({ id: area.id, name: area.name }))], values.area.id || '', value => { const area = areas.find(item => item.id === value); values.area = area ? { id: area.id, expected_version: area.version, name: area.name, profile: area.profile } : { name: '', profile: 'events' }; });
      if (!values.area.id) {
        field(fields, 'Work area name', values.area.name, value => values.area.name = value, { required: true });
        select(fields, 'Work style', (choices.profiles || [{ key: 'events', name: 'Events' }, { key: 'custom', name: 'Custom' }]).map(profile => ({ id: profile.key, name: profile.name })), values.area.profile || 'events', value => values.area.profile = value);
      }
      const people = node('fieldset', '', 'teams-setup-people'); people.append(node('legend', 'Default team members'));
      for (const person of choices.members || []) { const label = node('label'), check = node('input'); check.type = 'checkbox'; check.checked = values.member_ids.map(String).includes(String(person.id)); check.addEventListener('change', () => { if (state.busy || state.intent) return; values.member_ids = check.checked ? [...new Set([...values.member_ids.map(String), String(person.id)])] : values.member_ids.filter(id => String(id) !== String(person.id)); modified(); }); label.append(check, document.createTextNode(person.name)); people.append(label); }
      if (!choices.members?.length) people.append(node('p', 'People can be added later.')); fields.append(people);
    } else if (state.step === 2) {
      const times = node('div', '', 'teams-setup-pair');
      field(times, 'Booth teardown · minutes', values.event_defaults.teardown_minutes, value => values.event_defaults.teardown_minutes = value === '' ? '' : Number(value), { type: 'number', min: 0, max: 1440, step: 1, required: true });
      field(times, 'Arrival buffer · minutes', values.event_defaults.arrival_buffer_minutes, value => values.event_defaults.arrival_buffer_minutes = value === '' ? '' : Number(value), { type: 'number', min: 0, max: 1440, step: 1, required: true }); fields.append(times);
      select(fields, 'Cost policy', (choices.cost_templates || ['skip', 'blank']).map(key => ({ id: key, name: costNames[key] || key })), values.cost_policy.template || 'skip', value => values.cost_policy.template = value);
      if (values.cost_policy.template !== 'skip') {
        const policy = node('div', '', 'teams-setup-policy');
        field(policy, 'Policy name · optional', values.cost_policy.name, value => values.cost_policy.name = value);
        const required = values.cost_policy.template === 'blank';
        const pair = node('div', '', 'teams-setup-pair'); const currency = field(pair, 'Currency' + (required ? '' : ' · optional override'), values.cost_policy.currency, value => values.cost_policy.currency = value.toUpperCase(), { required }); currency.maxLength = 3; currency.placeholder = 'USD';
        field(pair, 'Effective date' + (required ? '' : ' · optional override'), values.cost_policy.effective_on, value => values.cost_policy.effective_on = value, { type: 'date', required }); policy.append(pair);
        const rates = node('div', '', 'teams-setup-pair'); for (const [key, name] of Object.entries(rateNames)) field(rates, name, state.rates[key], value => state.rates[key] = value, { type: 'number', min: 0, max: key === 'commission_rate_bps' ? 100 : 100000, step: '.01', hint: key === 'mileage_rate_minor' ? 'Up to 2 decimal places; leave blank if your rate needs more precision.' : '' }); policy.append(rates, node('p', 'Optional rates. The policy is saved as a draft for review.', 'teams-setup-help')); fields.append(policy);
      }
      select(fields, 'Optional integrations', [{ id: 'later', name: 'Skip integrations' }, { id: 'asana', name: 'Connect Asana after setup' }], values.connection || 'later', value => values.connection = value);
    } else {
      const review = node('dl', '', 'teams-setup-review');
      reviewLine(review, 'Home base', values.home_base.address); reviewLine(review, 'Time zone', values.home_base.timezone); reviewLine(review, 'Work area', values.area.name);
      reviewLine(review, 'Default team members', (choices.members || []).filter(person => values.member_ids.map(String).includes(String(person.id))).map(person => person.name).join(', ') || 'Choose later');
      reviewLine(review, 'Event defaults', values.event_defaults.teardown_minutes + ' min teardown · ' + values.event_defaults.arrival_buffer_minutes + ' min arrival buffer');
      reviewLine(review, 'Cost policy', values.cost_policy.template === 'skip' ? 'Set up later' : (values.cost_policy.name || costNames[values.cost_policy.template] || 'Event costs') + ' · Draft');
      if (values.cost_policy.template !== 'skip') {
        if (values.cost_policy.currency) reviewLine(review, 'Policy currency', values.cost_policy.currency);
        if (values.cost_policy.effective_on) reviewLine(review, 'Policy effective date', new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(values.cost_policy.effective_on + 'T12:00:00Z')));
      }
      if (values.cost_policy.template !== 'skip') for (const [key, title] of Object.entries(rateNames)) if (state.rates[key]) reviewLine(review, title, state.rates[key] + (key === 'commission_rate_bps' ? '%' : ' ' + (values.cost_policy.currency || '')));
      reviewLine(review, 'Integrations', values.connection === 'asana' ? 'Connect Asana next' : 'No integration required'); fields.append(review);
    }
    mounted.notice = node('p', state.notice || (state.dirty ? 'Changes are kept in this page until saved.' : state.status.draft ? 'Draft saved' : ''), 'teams-setup-notice'); mounted.notice.classList.toggle('teams-setup-error', !!state.errorNotice); mounted.notice.setAttribute('role', 'status'); mounted.notice.setAttribute('aria-live', 'polite');
    const controls = node('div', '', 'teams-setup-controls');
    if (state.step > 0) controls.append(button('Back', () => void save('draft', state.step - 1)));
    const close = button('Save & close', () => void save('draft', state.step, true), 'teams-setup-save');
    const next = node('button', state.intent ? state.intent.kind === 'finish' ? 'Retry same setup' : 'Retry same save' : state.step === 3 ? 'Finish setup' : 'Next', 'teams-setup-primary'); next.type = 'submit'; controls.append(close, next);
    form.addEventListener('submit', event => { event.preventDefault(); if (state.intent) void retry(); else if (form.reportValidity()) void save(state.step === 3 ? 'finish' : 'draft', Math.min(3, state.step + 1)); });
    root.append(form, mounted.notice); form.append(controls); body.append(root);
    if (state.busy || state.intent) for (const control of form.querySelectorAll('input,select,button')) control.disabled = true;
    if (state.intent && !state.busy) { next.disabled = false; if (state.errorStatus === 409) { const review = button('Review latest saved setup', () => void reviewConflict(), 'teams-setup-review-latest'); mounted.notice.after(review); } }
    if (state.busy) mounted.notice.textContent = 'Saving…';
  }
  async function save(kind, step, close = false) {
    if (!active() || state.busy) return;
    if (state.intent) { await retry(); return; }
    try {
      const values = valuesForSave();
      // Review is persisted first, then Finish has its own exact command identity.
      if (kind === 'finish' && state.dirty) { await save('draft', 3); if (state.dirty || state.intent) return; }
      if (!active()) return;
      state.intent = { kind, close, mount: mounted.token, payload: kind === 'finish' ? { request_id: crypto.randomUUID(), expected_version: state.baseVersion, reviewed: true } : { request_id: crypto.randomUUID(), expected_version: state.baseVersion, step, values } };
      await retry();
    } catch (error) { feedback(error.message || 'Review the setup fields and try again.', true); }
  }
  async function retry() {
    if (!active() || state.busy || !state.intent) return;
    const intent = state.intent; state.busy = true; render();
    try {
      const result = await api('/setup/' + (intent.kind === 'finish' ? 'finish' : 'draft'), { method: 'POST', body: intent.payload });
      state.intent = null; state.dirty = false; state.notice = ''; state.errorNotice = false; state.errorStatus = null; hydrate(result); state.baseVersion = result.version; state.step = result.draft?.step ?? (intent.kind === 'draft' ? intent.payload.step : state.step);
      if (intent.kind === 'finish') { state.values = null; state.rates = {}; if (mounted?.token === intent.mount) closeDialog(); await onComplete(result); }
      else if (intent.close && mounted?.token === intent.mount) closeDialog(); else render();
    } catch (error) {
      if ([401, 403, 404, 419].includes(error.status)) { state.sealed = true; if (mounted?.body.isConnected) mounted.body.replaceChildren(node('p', 'Verify the original account to recover your setup draft.', 'teams-setup-notice')); }
      else { state.errorStatus = error.status; if ([400, 422].includes(error.status)) state.intent = null; render(); feedback(error.message || 'The save is not confirmed. Retry the same save.', true); }
    } finally { state.busy = false; if (active()) render(); }
  }
  async function reviewConflict() {
    if (!active() || state.busy) return;
    try {
      const fresh = await read(); if (!fresh?.can_manage || !active()) return;
      if (state.intent?.kind === 'finish') { feedback('Retry the same setup to confirm its outcome before changing this draft.', true); return; }
      state.intent = null; state.baseVersion = fresh.version; state.errorStatus = null; render();
      const saved = fresh.draft?.values, home = saved?.home_base?.address || fresh.defaults?.home_base?.address || 'Not set', area = saved?.area?.name || fresh.choices?.areas?.find(item => item.id === fresh.defaults?.area_id)?.name || 'Not set';
      feedback('Saved home base: ' + home + '. Saved work area: ' + area + '. Your entries remain; review them before saving.');
    } catch (error) { feedback(error.message || 'Saved setup could not be checked.', true); }
  }
  async function open() {
    if (destroyed || state.busy && !state.status) return;
    try {
      const fresh = await read(); if (!fresh?.can_manage) return false;
      state.sealed = false; const body = openDialog('Set up Everbranch Teams'); mounted = { body, token: ++generation, notice: node('p') };
      render(); return true;
    } catch (error) { if (trigger) { trigger.title = error.message || 'Teams setup is unavailable'; trigger.disabled = false; } return false; }
  }
  const triggerClick = () => void open(); trigger?.addEventListener('click', triggerClick);
  const lost = () => { state.sealed = true; generation++; if (mounted?.body.isConnected) mounted.body.replaceChildren(node('p', 'Verify the original account to recover your setup draft.', 'teams-setup-notice')); };
  document.addEventListener('team:access-lost', lost);
  return {
    async inspect() { const status = await read(); if (status?.can_manage && status.required && !autoOpened && !document.querySelector('dialog[open]')) { autoOpened = true; await open(); } return status; },
    open, hasDraft: () => !!(state.dirty || state.intent || state.busy),
    dispose() { destroyed = true; generation++; trigger?.removeEventListener('click', triggerClick); document.removeEventListener('team:access-lost', lost); mounted = null; if (!state.dirty && !state.intent && !state.busy) retained.delete(scope); },
  };
}
