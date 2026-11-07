// Read-only dated event windows. A source deadline or a range never fills gap days.
const phases = new Set(['setup', 'selling', 'teardown', 'travel']);
const viewState = new Map(); // Presentation choices only, isolated to this page and tenant/area.
const palette = ['#315b4a', '#4c64a0', '#985331', '#795d99', '#266f76', '#776622'];
const node = (tag, text = '', cls = '') => { const element = document.createElement(tag); element.textContent = text; element.className = cls; return element; };
const validDate = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value + 'T12:00:00Z')) && new Date(value + 'T12:00:00Z').toISOString().slice(0, 10) === value;
const when = value => validDate(value) ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(value + 'T12:00:00Z')) : 'Date unknown';
const title = value => String(value || '').replace(/^./, letter => letter.toUpperCase());
const calendar = item => item.data?.event_schedule || {};
const sessions = item => (Array.isArray(calendar(item).sessions) ? calendar(item).sessions : []).filter(session => session && phases.has(session.kind));
const people = item => (Array.isArray(item.calendar_people) ? item.calendar_people : []).filter(person => typeof person?.id === 'string' && /^(native:[1-9]\d*|asana:\d+:\d+)$/.test(person.id));
const personName = person => person.name || 'Recorded ' + (person.provider || 'source') + ' identity ' + person.id;
const stableColor = key => { let hash = 0; for (const char of key) hash = (hash * 31 + char.charCodeAt(0)) >>> 0; return palette[hash % palette.length]; };
const eventColor = (item, tenant) => /^#[a-f\d]{6}$/i.test(calendar(item).calendar_color || '') ? calendar(item).calendar_color : stableColor(tenant + '|event|' + item.id);
const evidence = (item, session) => {
  if (calendar(item).provenance?.source_changed_since_review) return 'Source changed · review evidence';
  const status = calendar(item).session_evidence?.find(row => row.session_id === session.id)?.status;
  return ({ source_verified_at_review: 'Source verified at review', staff_attested: 'Staff-attested source', staff_attested_external: 'Staff-attested external source' })[status] || 'Date evidence unconfirmed';
};
const hours = session => session.opens && session.closes ? session.opens + '–' + session.closes : 'Hours unknown';
const studioLink = tenant => { const link = node('a', 'Review schedules in Teams Studio'); const url = new URL('/teams/studio', location.origin); url.searchParams.set('tenant', tenant); link.href = url.href; return link; };

export function renderScheduleSummary(parent, item, tenant) {
  const schedule = calendar(item), section = node('section', '', 'tops-section tops-schedule-summary');
  section.append(node('h3', 'Dated event schedule'), node('p', schedule.provenance?.source_changed_since_review ? 'Reviewed dates · source changed; review needed' : schedule.schedule_complete ? 'Confirmed date set · ' + (schedule.selling_day_count ?? 'Unknown') + ' distinct selling days' : 'Partial or unconfirmed calendar · missing dates remain unknown', 'tops-muted'));
  section.append(node('p', 'Local timezone: ' + (schedule.timezone || 'unknown') + '. Calendar windows do not establish staff shifts, attendance, paid hours or per diem.', 'tops-muted'));
  const list = node('ul');
  for (const session of sessions(item)) list.append(node('li', title(session.kind) + ' · ' + when(session.date) + ' · ' + hours(session) + (session.label ? ' · ' + session.label : '') + ' · ' + evidence(item, session)));
  if (list.childElementCount) section.append(list);
  else section.append(node('p', 'No explicit dated sessions are recorded. Stored bounds are legacy/source context, not confirmed activity on every intervening day.'));
  const source = schedule.source_schedule || item.data?.source_schedule || {};
  if (source.due_on || source.due_at) section.append(node('p', (source.due_on ? 'Asana due anchor: ' + when(source.due_on) : 'Asana due timestamp: ' + source.due_at) + '. This deadline does not verify setup, the selling span or teardown.', 'tops-muted'));
  for (const issue of schedule.review_items || []) section.append(node('p', String(issue), 'tops-muted'));
  for (const issue of schedule.budget_review_items || []) section.append(node('p', String(issue), 'tops-muted'));
  section.append(studioLink(tenant)); parent.append(section);
}

export function renderEventCalendar(host, items, coverage, tenant, open, scope = tenant) {
  const today = new Date(), localToday = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
  const previous = viewState.get(scope);
  let mode = previous?.mode || 'month', anchor = previous?.anchor || localToday, colorMode = previous?.colorMode || 'event';
  const controls = node('div', '', 'tops-event-calendar-controls'), modes = node('div', '', 'tops-calendar-views'), month = node('input'), day = node('input'), colors = node('select');
  month.type = 'month'; month.value = anchor.slice(0, 7); month.setAttribute('aria-label', 'Calendar month');
  day.type = 'date'; day.value = anchor; day.setAttribute('aria-label', 'Calendar first day'); day.hidden = mode === 'month'; month.hidden = mode !== 'month';
  colors.setAttribute('aria-label', 'Calendar colors');
  for (const [value, text] of [['event', 'Event colors'], ['person', 'Crew colors']]) { const option = node('option', text); option.value = value; colors.append(option); }
  const button = (text, action) => { const element = node('button', text); element.type = 'button'; element.onclick = action; return element; };
  for (const [value, text] of [['month', 'Month'], ['1', '1 day'], ['2', '2 days'], ['3', '3 days']]) { const tab = button(text, () => { mode = value; month.hidden = mode !== 'month'; day.hidden = mode === 'month'; paint(); }); tab.dataset.calendarView = value; modes.append(tab); }
  controls.append(modes, month, day, colors, button('Previous period', () => shift(-1)), button('Next period', () => shift(1))); host.append(controls);
  host.append(node('p', 'This page only · ' + items.length + ' event records. ' + (coverage.has_more ? 'More pages remain. ' : '') + (coverage.partial || coverage.complete === false ? 'Source coverage is partial or unconfirmed. ' : '') + 'Other pages and unavailable source feeds are not included.', 'tops-muted'));
  host.append(node('p', 'Only explicit dated sessions appear. Setup is lighter; labels identify every phase. Crew colors show recorded event assignments, not shifts or attendance at each session.', 'tops-muted'));
  const scroll = node('div', '', 'tops-event-calendar-scroll'), grid = node('div', '', 'tops-calendar tops-event-calendar'); scroll.append(grid); host.append(scroll);
  function shift(direction) {
    const value = new Date(anchor + 'T12:00:00Z');
    if (mode === 'month') { value.setUTCDate(1); value.setUTCMonth(value.getUTCMonth() + direction); } else value.setUTCDate(value.getUTCDate() + direction * Number(mode));
    anchor = value.toISOString().slice(0, 10); month.value = anchor.slice(0, 7); day.value = anchor; paint();
  }
  function card(item, session) {
    const crew = people(item), chip = button('', () => open(item)); chip.className = 'tops-calendar-session is-' + session.kind;
    const color = colorMode === 'person' && crew.length ? stableColor(tenant + '|' + (crew[0].provider || '') + '|' + crew[0].id) : eventColor(item, tenant);
    chip.style.setProperty('--event-color', color); chip.dataset.calendarEvent = item.id; chip.dataset.calendarSession = session.id || ''; chip.dataset.calendarKind = session.kind;
    const timezone = calendar(item).timezone, zone = node('small', timezone ? timezone.split('/').at(-1).replaceAll('_', ' ') + ' · local' : 'Timezone unknown');
    if (timezone) { zone.title = timezone; zone.setAttribute('aria-label', 'Local timezone: ' + timezone); }
    chip.append(node('strong', item.title), node('span', title(session.kind) + ' · ' + hours(session)), zone);
    if (session.label) chip.append(node('small', session.label));
    if (!calendar(item).schedule_complete) chip.append(node('small', 'Partial schedule'));
    if (calendar(item).provenance?.source_changed_since_review) chip.append(node('small', 'Source changed · review needed'));
    if (colorMode === 'person') {
      if (!crew.length) chip.append(node('small', 'No verified crew identity'));
      for (const person of crew) { const badge = node('span', personName(person), 'tops-calendar-person'); badge.style.setProperty('--person-color', stableColor(tenant + '|' + (person.provider || '') + '|' + person.id)); badge.dataset.calendarPerson = person.id; chip.append(badge); }
    }
    return chip;
  }
  function paint() {
    colors.value = colorMode;
    if (validDate(anchor)) viewState.set(scope, { mode, anchor, colorMode });
    grid.replaceChildren(); grid.classList.toggle('is-month', mode === 'month'); grid.style.setProperty('--calendar-columns', mode === 'month' ? '7' : mode);
    for (const tab of modes.children) tab.setAttribute('aria-pressed', String(tab.dataset.calendarView === mode));
    let keys = [];
    if (mode === 'month') {
      if (!/^\d{4}-\d{2}$/.test(month.value)) return;
      const [year, number] = month.value.split('-').map(Number), first = new Date(Date.UTC(year, number - 1, 1)), count = new Date(Date.UTC(year, number, 0)).getUTCDate();
      for (const name of ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']) grid.append(node('strong', name, 'tops-weekday'));
      for (let i = 0; i < first.getUTCDay(); i++) grid.append(node('div', '', 'tops-day tops-blank'));
      keys = Array.from({ length: count }, (_, i) => month.value + '-' + String(i + 1).padStart(2, '0'));
    } else {
      if (!validDate(anchor)) return;
      keys = Array.from({ length: Number(mode) }, (_, i) => { const value = new Date(anchor + 'T12:00:00Z'); value.setUTCDate(value.getUTCDate() + i); return value.toISOString().slice(0, 10); });
    }
    for (const key of keys) {
      const cell = node('section', '', 'tops-day'); cell.dataset.calendarDate = key; cell.setAttribute('aria-label', when(key)); cell.append(node('span', mode === 'month' ? key.slice(-2).replace(/^0/, '') : when(key)));
      for (const item of items) for (const session of sessions(item)) if (validDate(session.date) && session.date === key) cell.append(card(item, session));
      grid.append(cell);
    }
  }
  month.onchange = () => { anchor = month.value + '-01'; day.value = anchor; paint(); }; day.onchange = () => { anchor = day.value; month.value = anchor.slice(0, 7); paint(); }; colors.onchange = () => { colorMode = colors.value; paint(); }; paint();
  const pending = items.flatMap(item => sessions(item).filter(session => !validDate(session.date)).map(session => ({ item, session })));
  if (pending.length) { const section = node('section', '', 'tops-section'); section.append(node('h3', 'Sessions with unknown dates')); for (const { item, session } of pending) section.append(button(item.title + ' · ' + title(session.kind) + ' · date unknown', () => open(item))); host.append(section); }
  const bounds = item => [calendar(item).legacy_starts_on || item.starts_on, calendar(item).legacy_ends_on || item.ends_on];
  const legacy = items.filter(item => !sessions(item).some(session => validDate(session.date)) && bounds(item).some(validDate));
  if (legacy.length) { const section = node('section', '', 'tops-section tops-calendar-legacy'); section.append(node('h3', 'Legacy ranges · activity unconfirmed'), node('p', 'These bounds do not establish activity on each date, and are not painted into the calendar.', 'tops-muted')); for (const item of legacy) { const row = node('div'); row.append(button(item.title, () => open(item)), node('span', when(calendar(item).legacy_starts_on || item.starts_on) + ' → ' + when(calendar(item).legacy_ends_on || item.ends_on))); section.append(row); } host.append(section); }
  const undated = items.filter(item => !sessions(item).length && !bounds(item).some(validDate));
  if (undated.length) { const section = node('section', '', 'tops-section'); section.append(node('h3', 'Events without dates')); for (const item of undated) section.append(button(item.title + ' · calendar unknown', () => open(item))); host.append(section); }
}
