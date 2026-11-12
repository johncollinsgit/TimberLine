import { installEventCommerce } from './event-commerce';
import '../../css/team-event-detail.css';

const node = (tag, text = '', cls = '') => { const item = document.createElement(tag); item.textContent = text; item.className = cls; return item; };
const date = value => /^\d{4}-\d{2}-\d{2}$/.test(value || '') ? new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(value + 'T12:00:00Z')) : 'To be confirmed';
const currency = (amount, code = 'USD') => Number.isSafeInteger(amount) && /^[A-Z]{3}$/.test(code) ? new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(amount / 100) : 'Not recorded';
const label = value => String(value || '').replaceAll('_', ' ').replace(/^./, char => char.toUpperCase());
const link = (title, href) => { const allowed = /^https:\/\/(www\.)?(google\.com|maps\.apple\.com)\//.test(href || '') || /^(tel:|mailto:)/.test(href || ''); const item = node(allowed ? 'a' : 'span', title); if (allowed) { item.href = href; if (href.startsWith('https:')) { item.target = '_blank'; item.rel = 'noopener noreferrer'; } } return item; };
const action = (title, callback, cls = '') => { const item = node('button', title, cls); item.type = 'button'; item.onclick = () => Promise.resolve().then(callback).catch(error => item.dispatchEvent(new CustomEvent('event:detail-error', { bubbles: true, detail: error }))); return item; };
const plain = value => typeof value === 'string' && value.trim() ? value.trim() : null;
const travelDrafts = new Map();
export const hasEventTravelDrafts = () => travelDrafts.size > 0;

export const eventDetailTitle = title => String(title || 'Event').replace(/^\s*\d{1,2}[./]\d{1,2}[./]\d{2,4}(?:\s*[-–—]\s*\d{1,2}[./]\d{1,2}[./]\d{2,4})?\s+/, '');

function card(title, cls = '') { const box = node('section', '', 'event-panel ' + cls); box.append(node('h3', title)); return box; }
function field(title, value, hint) { const box = node('div', '', 'event-fact'); box.append(node('dt', title)); const content = node('dd'); content.append(value instanceof Node ? value : document.createTextNode(value || 'To be confirmed')); box.append(content); if (hint) box.append(node('p', hint, 'event-fact-hint')); return box; }
function initials(name) { return name.split(/\s+/).filter(Boolean).slice(0, 2).map(word => word[0]).join('').toUpperCase(); }
function dateRange(start, end) { return start && end && start !== end ? date(start) + ' – ' + date(end) : date(start || end); }

export function installEventDetail(body, { record, api, tenant, scope, current, actions = [] }) {
  const draftKey = scope + '|' + record.id;
  const travelDraft = travelDrafts.get(draftKey) || { values: null, request: null };
  let active = true, payload, travelRequest = travelDraft.request;
  const valid = () => active && current();
  const summary = card('At a glance', 'event-glance'), facts = node('dl', '', 'event-facts'); summary.append(facts);
  const columns = node('div', '', 'event-detail-columns'), main = node('div', '', 'event-detail-main'), aside = node('aside', '', 'event-detail-aside');
  const updates = node('section', '', 'event-detail-updates');
  const notes = node('details', '', 'event-panel event-detail-notes'); notes.append(node('summary', 'Event notes'), node('p', plain(record.data?.original_notes) || plain(record.data?.notes) || 'No event notes yet.', 'event-detail-note-text'));
  main.append(updates, notes); columns.append(main, aside); body.append(summary, columns);
  const commerce = installEventCommerce(main, { record, api, tenant, current: () => active && current() });
  const status = node('p', '', 'event-detail-status'); status.setAttribute('role', 'status'); body.prepend(status);
  body.addEventListener('event:detail-error', event => { if (valid()) status.textContent = event.detail?.message || 'This action could not be completed.'; });

  function draw(data) {
    if (!valid()) return;
    payload = data; commerce.update(data);
    const dates = data.dates || {}, location = data.location || {}, hotel = data.hotel || {}, sales = data.sales || {};
    const team = node('div', '', 'event-people');
    for (const person of data.team_members || []) { const name = plain(person.name); if (!name) continue; const row = node('span', '', 'event-person'); const avatar = node('span', initials(name), 'event-avatar'); avatar.setAttribute('aria-hidden', 'true'); row.append(avatar, document.createTextNode(name)); team.append(row); }
    if (!team.childElementCount) team.append(node('span', 'Not assigned'));
    const venue = node('div', '', 'event-address');
    if (location.venue) venue.append(node('strong', location.venue));
    if (location.address) venue.append(link(location.address, location.maps_url));
    if (!venue.childElementCount) venue.append(node('span', 'Address to be confirmed'));
    const stay = node('div', '', 'event-address');
    if (hotel.name) stay.append(node('strong', hotel.name));
    if (hotel.address) stay.append(link(hotel.address, hotel.maps_url));
    if (!stay.childElementCount) stay.append(node('span', 'Not recorded'));
    facts.replaceChildren(field('Event dates', dateRange(dates.starts_on, dates.ends_on), dates.setup_dates?.length ? 'Setup · ' + dates.setup_dates.map(date).join(', ') : null), field('Location', venue), field('Team members', team));
    if (sales.can_view) {
      const linked = Boolean(data.commerce_event_id), recorded = sales.current?.status === 'recorded';
      const currentLabel = !linked && recorded ? 'Recorded sales comparison' : 'Current sales';
      const currentValue = linked ? 'See Sales below' : recorded ? currency(sales.current.amount_cents, sales.current.currency) : sales.current?.status === 'needs_event_link' ? 'Not linked' : sales.current?.status === 'needs_review' ? 'Needs review' : 'Not recorded';
      const currentHint = linked ? 'Confirmed Pay, cash and reviewed Square purchases' : recorded ? 'Historical net sales comparison' : sales.current?.status === 'needs_event_link' ? 'Link this event to its register' : sales.current?.status === 'temporarily_unavailable' ? 'Current sales are temporarily unavailable' : 'No confirmed total yet';
      facts.append(field(sales.previous?.year ? sales.previous.year + ' comparison' : 'Previous year comparison', sales.previous?.status === 'needs_review' ? 'Needs review' : currency(sales.previous?.amount_cents, sales.previous?.currency), sales.previous?.status === 'recorded' ? 'Historical net sales comparison' : 'No linked comparison yet'), field(currentLabel, currentValue, currentHint));
    }
    facts.append(field('Hotel', stay));
    aside.replaceChildren();
    renderTravel(data.travel || {});
    const contact = data.coordinator || {};
    if (contact.name || contact.phone || contact.email) { const box = card('Event coordinator'); if (contact.name) box.append(node('p', contact.name, 'event-contact-name')); if (contact.phone) box.append(link(contact.phone, contact.phone_url)); if (contact.email) box.append(link(contact.email, contact.email_url)); aside.append(box); }
    const schedule = card('Schedule');
    if (dates.sessions?.length) {
      const rows = node('ul', '', 'event-session-list');
      for (const session of dates.sessions) { const row = node('li'); row.append(node('strong', label(session.kind)), node('span', date(session.date)), node('span', session.opens && session.closes ? session.opens + '–' + session.closes : 'Hours to be confirmed')); if (session.kind === 'setup') row.classList.add('event-session-setup'); rows.append(row); }
      schedule.append(rows);
    } else schedule.append(node('p', 'Show and setup hours need confirmation.', 'event-soft'));
    if (dates.timezone) schedule.append(node('p', dates.timezone.replaceAll('_', ' '), 'event-soft event-small'));
    aside.append(schedule);
    if (!data.event_id) return;
    const tools = node('details', '', 'event-panel event-detail-tools'); tools.append(node('summary', 'More event options'));
    for (const item of actions) { if (item.render) item.render(tools); else tools.append(action(item.label, item.run)); }
    const studio = node('a', 'Edit schedule and event settings'); studio.href = '/teams/studio?tenant=' + encodeURIComponent(tenant); tools.append(studio);
    if (typeof record.data?.source_url === 'string' && record.data.source_url.startsWith('https://app.asana.com/')) { const source = node('a', 'Open connected task'); source.href = record.data.source_url; source.target = '_blank'; source.rel = 'noopener noreferrer'; tools.append(source); }
    aside.append(tools);
  }
  function travelTime(value, zone) { try { return value ? new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: zone || undefined }).format(new Date(value)) : null; } catch { return null; } }
  function renderTravel(travel) {
    const box = card('Travel plan'), items = node('dl', '', 'event-travel-times');
    const departure = travelTime(travel.departure_at, travel.home_base?.timezone), back = travelTime(travel.return_at, travel.home_base?.timezone);
    items.append(field('Leave home base', departure || 'Set up your travel plan'), field('Estimated return', back || 'Schedule and drive time needed'));
    box.append(items);
    if (travel.home_base?.address) box.append(node('p', travel.home_base.address, 'event-soft'));
    if (travel.home_base?.timezone) box.append(node('p', 'Home-base times · ' + travel.home_base.timezone.replaceAll('_', ' '), 'event-small event-soft'));
    if (travel.outbound_minutes != null) box.append(node('p', 'Outbound · ' + travel.outbound_minutes + ' min · Return · ' + (travel.return_minutes ?? '—') + ' min', 'event-small event-soft'));
    box.append(node('p', 'Return includes ' + (travel.teardown_minutes ?? 60) + ' minutes to take down the booth.', 'event-small event-soft'));
    if (travel.schedule_notice) box.append(node('p', travel.schedule_notice, 'event-small event-soft'));
    if (travel.outbound_directions_url) box.append(link('Driving directions ↗', travel.outbound_directions_url));
    if (travel.basis === 'needs_review') box.append(node('p', 'The event or route changed. Review the travel plan.', 'event-review-notice'));
    if (payload?.can_manage_travel) {
      const edit = node('details', '', 'event-travel-editor'); edit.append(node('summary', 'Edit travel plan'));
      const form = node('form'), inputs = {};
      for (const [name, title, value, type] of [['home_base', 'Home base address', travel.home_base?.address || '', 'text'], ['timezone', 'Home base time zone', travel.home_base?.timezone || payload.dates?.timezone || '', 'text'], ['outbound_minutes', 'Drive to event · minutes', travel.outbound_minutes ?? '', 'number'], ['return_minutes', 'Drive home · minutes', travel.return_minutes ?? '', 'number']]) { const wrap = node('label', title), input = node('input'); input.name = name; input.type = type; input.value = travelDraft.values?.[name] ?? value; if (type === 'number') { input.min = '0'; input.max = '2880'; input.step = '1'; } input.required = true; input.disabled = !!travelRequest; inputs[name] = input; wrap.append(input); form.append(wrap); }
      form.append(node('p', 'Check the driving directions, then enter the expected travel times. Recommendations use the confirmed setup and closing hours.', 'event-small event-soft'));
      const save = node('button', 'Save travel plan'); save.type = 'submit'; const feedback = node('p', '', 'event-small'); feedback.setAttribute('role', 'status'); form.append(save, feedback); edit.append(form); box.append(edit);
      form.addEventListener('input', () => { if (travelRequest) return; travelDraft.values = Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value])); travelDrafts.set(draftKey, travelDraft); });
      form.onsubmit = async event => { event.preventDefault(); if (save.disabled || !valid()) return; save.disabled = true; feedback.textContent = 'Saving…';
        travelRequest ||= { request_id: crypto.randomUUID(), expected_event_version: payload.event_version, expected_area_version: payload.area_version, reviewed: true, home_base: { address: inputs.home_base.value.trim(), timezone: inputs.timezone.value.trim() }, outbound_minutes: Number(inputs.outbound_minutes.value), return_minutes: Number(inputs.return_minutes.value) };
        travelDraft.request = travelRequest; travelDrafts.set(draftKey, travelDraft); for (const input of Object.values(inputs)) input.disabled = true;
        try { const result = await api('/events/' + record.id + '/travel', { method: 'POST', body: travelRequest }); travelDrafts.delete(draftKey); travelDraft.values = travelDraft.request = travelRequest = null; if (!valid()) return; draw({ ...payload, event_version: result.event_version, area_version: result.area_version, travel: result.travel }); }
        catch (error) { if (valid()) { feedback.textContent = error.message || 'The travel plan could not be saved. Your entries are retained.'; if ([400, 401, 403, 404, 409, 419, 422].includes(error.status)) { travelDraft.request = travelRequest = null; for (const input of Object.values(inputs)) input.disabled = false; } save.disabled = false; if (error.status === 409) { save.disabled = true; feedback.append(action('Review latest plan', async () => { const fresh = await api('/events/' + record.id + '/essentials'); if (valid()) draw(fresh); })); } else if (travelRequest) save.textContent = 'Retry same save'; } }
      };
    }
    aside.append(box);
  }
  draw({ dates: { starts_on: record.starts_on, ends_on: record.ends_on }, location: { venue: record.data?.venue, address: record.data?.address }, team_members: record.data?.source_assignee_name ? [{ name: record.data.source_assignee_name }] : [], sales: { can_view: false } });
  status.textContent = 'Loading sales and travel details…';
  const load = async () => { try { const result = await api('/events/' + record.id + '/essentials'); if (valid()) { draw(result); status.textContent = result.notice || ''; } } catch (error) { if (valid()) { status.textContent = error.message || 'Some event details could not be loaded.'; status.append(action('Try again', load)); } } };
  return { updatesHost: updates, ready: load(), dispose: () => { active = false; commerce.dispose(); } };
}
