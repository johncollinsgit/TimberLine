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
const dueOn = item => item.data?.source_schedule?.due_on ?? calendar(item).source_schedule?.due_on;
const legacyBounds = item => [calendar(item).legacy_starts_on || item.starts_on, calendar(item).legacy_ends_on || item.ends_on];

export function eventCalendarWindow(scope) {
  const today = new Date(), localToday = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
  const state = viewState.get(scope) || { mode: 'month', anchor: localToday, colorMode: 'event' };
  viewState.set(scope, state);
  const end = new Date(state.anchor + 'T12:00:00Z');
  if (state.mode === 'month') {
    end.setUTCMonth(end.getUTCMonth() + 1, 0);
    return { calendar_from: state.anchor.slice(0, 7) + '-01', calendar_to: end.toISOString().slice(0, 10) };
  }
  end.setUTCDate(end.getUTCDate() + Number(state.mode) - 1);
  return { calendar_from: state.anchor, calendar_to: end.toISOString().slice(0, 10) };
}
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

export function renderEventCalendar(host, items, coverage, tenant, open, scope = tenant, onWindowChange) {
  const today = new Date(), localToday = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
  const previous = viewState.get(scope);
  let mode = previous?.mode || 'month', anchor = previous?.anchor || localToday, colorMode = previous?.colorMode || 'event';
  const controls = node('div', '', 'tops-event-calendar-controls'), modes = node('div', '', 'tops-calendar-views'), month = node('input'), day = node('input'), colors = node('select');
  modes.setAttribute('role', 'group'); modes.setAttribute('aria-label', 'Calendar view');
  month.type = 'month'; month.value = anchor.slice(0, 7); month.setAttribute('aria-label', 'Calendar month');
  day.type = 'date'; day.value = anchor; day.setAttribute('aria-label', 'Calendar first day'); day.hidden = mode === 'month'; month.hidden = mode !== 'month';
  colors.setAttribute('aria-label', 'Calendar colors');
  for (const [value, text] of [['event', 'Event colors'], ['person', 'Crew colors']]) { const option = node('option', text); option.value = value; colors.append(option); }
  const button = (text, action) => { const element = node('button', text); element.type = 'button'; element.onclick = action; return element; };
  for (const [value, text] of [['month', 'Month'], ['1', '1 day'], ['2', '2 days'], ['3', '3 days']]) { const tab = button(text, () => { if (mode === value) return; mode = value; month.hidden = mode !== 'month'; day.hidden = mode === 'month'; changeWindow(); }); tab.dataset.calendarView = value; modes.append(tab); }
  const filters = node('div', '', 'tops-calendar-filters'), dateField = node('div'), dateLabel = node('span'), colorField = node('label');
  dateField.append(dateLabel, month, day); colorField.append(node('span', 'Color by'), colors); filters.append(dateField, colorField);
  const periods = node('div', '', 'tops-calendar-periods'); periods.setAttribute('role', 'group'); periods.setAttribute('aria-label', 'Calendar period');
  periods.append(button('Previous period', () => shift(-1)), button('Next period', () => shift(1))); controls.append(modes, filters, periods); host.append(controls);
  host.append(node('p', 'Loaded results · ' + items.length + ' event records' + (coverage.has_more ? ' · more pages remain' : '') + (coverage.partial || coverage.complete === false ? ' · source coverage partial or unconfirmed' : ''), 'tops-calendar-scope'));
  if (coverage.calendar_window) host.append(node('p', when(coverage.calendar_window.from) + ' → ' + when(coverage.calendar_window.to) + (coverage.calendar_window.scan_limited ? ' · Load more events to continue checking retained dates.' : ' · Unspecified dates remain unknown.'), 'tops-calendar-scope'));
  const guide = node('details', '', 'tops-calendar-guide'); guide.append(node('summary', 'Calendar scope & source notes'), node('p', 'Explicit sessions show reviewed activity; gap days stay empty. Setup is lighter. Dashed task dates and range-start markers identify unconfirmed dates; they do not establish event activity. Other pages and unavailable source feeds are not included.'), node('p', 'Crew colors identify recorded event assignments, not shifts or attendance at each session. Event times use the displayed local timezone.')); host.append(guide);
  const hint = node('p', 'Swipe or scroll across the dates →', 'tops-calendar-scroll-hint'); host.append(hint);
  if (host.closest('#team-operations')?.classList.contains('tops-inline-calendar')) {
    const compact = node('nav', '', 'tops-inline-calendar-nav'); compact.setAttribute('aria-label', 'Event calendar navigation');
    const previousButton = button('‹', () => shift(-1)); previousButton.setAttribute('aria-label', 'Previous period');
    const todayButton = button('Today', () => { anchor = localToday; month.value = anchor.slice(0, 7); day.value = anchor; changeWindow(); });
    const nextButton = button('›', () => shift(1)); nextButton.setAttribute('aria-label', 'Next period');
    document.getElementById('tops-inline-options')?.remove();
    const settings = node('section', '', 'tops-inline-calendar-options'); settings.id = 'tops-inline-options'; settings.append(node('h3', 'Event calendar'));
    dateField.remove(); periods.remove(); compact.append(previousButton, todayButton, nextButton, month, day);
    settings.append(controls, ...host.querySelectorAll(':scope > .tops-calendar-scope'), guide);
    document.getElementById('tw-controls-panel')?.append(settings);
    host.prepend(compact);
  }
  const scroll = node('div', '', 'tops-event-calendar-scroll'), grid = node('div', '', 'tops-calendar tops-event-calendar'); scroll.tabIndex = 0; scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', 'Dated event calendar. Scroll horizontally for more dates.'); scroll.append(grid); host.append(scroll);
  function shift(direction) {
    const value = new Date(anchor + 'T12:00:00Z');
    if (mode === 'month') { value.setUTCDate(1); value.setUTCMonth(value.getUTCMonth() + direction); } else value.setUTCDate(value.getUTCDate() + direction * Number(mode));
    anchor = value.toISOString().slice(0, 10); month.value = anchor.slice(0, 7); day.value = anchor; changeWindow();
  }
  function changeWindow() { paint(); onWindowChange?.(); }
  function card(item, session) {
    const crew = people(item), chip = button('', () => open(item)); chip.className = 'tops-calendar-session is-' + session.kind;
    const color = colorMode === 'person' && crew.length ? stableColor(tenant + '|' + (crew[0].provider || '') + '|' + crew[0].id) : eventColor(item, tenant);
    chip.style.setProperty('--event-color', color); chip.dataset.calendarEvent = item.id; chip.dataset.calendarSession = session.id || ''; chip.dataset.calendarKind = session.kind;
    const description = [item.title, title(session.kind), hours(session), calendar(item).timezone || 'Timezone unknown', session.label, !calendar(item).schedule_complete ? 'Partial schedule' : '', calendar(item).provenance?.source_changed_since_review ? 'Source changed; review needed' : '', ...(colorMode === 'person' ? (crew.length ? crew.map(personName) : ['No verified crew identity']) : [])].filter(Boolean).join(' · ');
    chip.setAttribute('aria-label', description); chip.title = description;
    if (calendar(item).provenance?.source_changed_since_review) chip.dataset.calendarNeedsReview = 'true';
    const timezone = calendar(item).timezone, zone = node('small', timezone ? timezone.split('/').at(-1).replaceAll('_', ' ') + ' · local' : 'Timezone unknown');
    if (timezone) { zone.title = timezone; zone.setAttribute('aria-label', 'Local timezone: ' + timezone); }
    chip.append(node('span', title(session.kind), 'tops-calendar-phase'), node('strong', item.title), node('span', hours(session), 'tops-calendar-hours'), zone);
    if (session.label) chip.append(node('span', session.label, 'tops-calendar-session-label'));
    if (!calendar(item).schedule_complete) chip.append(node('span', 'Partial schedule', 'tops-calendar-status'));
    if (calendar(item).provenance?.source_changed_since_review) chip.append(node('span', 'Source changed · review needed', 'tops-calendar-status'));
    if (colorMode === 'person') {
      const footer = node('span', '', 'tops-calendar-crew');
      if (!crew.length) footer.append(node('span', 'No verified crew identity'));
      for (const person of crew) { const badge = node('span', personName(person), 'tops-calendar-person'); badge.style.setProperty('--person-color', stableColor(tenant + '|' + (person.provider || '') + '|' + person.id)); badge.dataset.calendarPerson = person.id; footer.append(badge); }
      chip.append(footer);
    }
    return chip;
  }
  function paint() {
    colors.value = colorMode;
    if (validDate(anchor)) viewState.set(scope, { mode, anchor, colorMode });
    grid.replaceChildren(); grid.classList.toggle('is-month', mode === 'month'); grid.style.setProperty('--calendar-columns', mode === 'month' ? '7' : mode);
    dateLabel.textContent = mode === 'month' ? 'Month' : 'First day'; scroll.dataset.calendarMode = mode; hint.hidden = mode === '1';
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
      const cell = node('section', '', 'tops-day'), heading = node('time', mode === 'month' ? key.slice(-2).replace(/^0/, '') : new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(key + 'T12:00:00Z')), 'tops-calendar-date'); heading.dateTime = key; cell.dataset.calendarDate = key; cell.setAttribute('aria-label', when(key)); cell.append(heading);
      for (const item of items) {
        for (const session of sessions(item)) if (validDate(session.date) && session.date === key) cell.append(card(item, session));
        if (validDate(dueOn(item)) && dueOn(item) === key && !sessions(item).some(session => session.date === key)) {
          const marker = button('', () => open(item)); marker.className = 'tops-calendar-session is-source-due'; marker.dataset.calendarEvent = item.id; marker.dataset.calendarKind = 'source_due';
          marker.setAttribute('aria-label', item.title + ' · Asana due date · Task date, not a confirmed event day'); marker.title = marker.getAttribute('aria-label');
          marker.append(node('span', 'Asana due date', 'tops-calendar-phase'), node('strong', item.title), node('span', 'Task date · not a confirmed event day', 'tops-calendar-status')); cell.append(marker);
        }
        const [start, end] = legacyBounds(item);
        if (dueOn(item) !== start && !sessions(item).some(session => validDate(session.date)) && validDate(start) && start === key) {
          const marker = button('', () => open(item)); marker.className = 'tops-calendar-session is-range-anchor'; marker.dataset.calendarEvent = item.id; marker.dataset.calendarKind = 'range_anchor';
          marker.setAttribute('aria-label', item.title + ' · Unconfirmed range · ' + when(start) + (validDate(end) && end !== start ? ' – ' + when(end) : '')); marker.title = marker.getAttribute('aria-label');
          marker.append(node('span', 'Unconfirmed range', 'tops-calendar-phase'), node('strong', item.title), node('span', when(start) + (validDate(end) && end !== start ? ' – ' + when(end) : ''), 'tops-calendar-status')); cell.append(marker);
        }
      }
      if (cell.childElementCount === 1 && mode !== 'month') cell.append(node('p', 'No dated sessions on this page', 'tops-calendar-empty'));
      grid.append(cell);
    }
  }
  month.onchange = () => { if (!/^\d{4}-\d{2}$/.test(month.value)) return; anchor = month.value + '-01'; day.value = anchor; changeWindow(); }; day.onchange = () => { if (!validDate(day.value)) return; anchor = day.value; month.value = anchor.slice(0, 7); changeWindow(); }; colors.onchange = () => { colorMode = colors.value; paint(); }; paint();
  const pending = items.flatMap(item => sessions(item).filter(session => !validDate(session.date)).map(session => ({ item, session })));
  const recordRow = (item, text) => { const row = node('div', '', 'tops-calendar-record-row'), action = button(item.title, () => open(item)); action.className = 'tops-calendar-record-open'; row.append(action, node('span', text)); return row; };
  if (pending.length) { const section = node('section', '', 'tops-section tops-calendar-records'); section.append(node('h3', 'Sessions with unknown dates')); for (const { item, session } of pending) section.append(recordRow(item, title(session.kind) + ' · date unknown')); host.append(section); }
  const bounds = legacyBounds;
  const legacy = items.filter(item => !sessions(item).some(session => validDate(session.date)) && bounds(item).some(validDate));
  if (legacy.length) { const section = node('section', '', 'tops-section tops-calendar-legacy tops-calendar-records'); section.append(node('h3', 'Legacy ranges · activity unconfirmed'), node('p', 'Stored bounds only. Activity on the dates between them is unconfirmed.', 'tops-calendar-record-note')); for (const item of legacy) section.append(recordRow(item, when(bounds(item)[0]) + ' → ' + when(bounds(item)[1]))); host.append(section); }
  const undated = items.filter(item => !sessions(item).length && !bounds(item).some(validDate));
  if (undated.length) { const section = node('section', '', 'tops-section tops-calendar-records'); section.append(node('h3', 'Events without dates')); for (const item of undated) section.append(recordRow(item, 'Calendar unknown')); host.append(section); }
}
