import '../../css/team-task-cards.css';

function recordedDay(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(value + 'T12:00:00Z');
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
}

/** One semantic action; literal source dates never establish an event span. */
export function taskCard({ title, dueOn, dueAt, completed = false, onOpen, dateLabel = 'Due', unknownLabel = 'No due date', note = '', id }) {
  const card = document.createElement('button'); card.type = 'button';
  card.className = 'teams-task-card' + (completed ? ' is-complete' : '');
  if (id) card.dataset.taskId = id;
  const day = recordedDay(dueOn);
  const timestamp = !dueOn && typeof dueAt === 'string' && /T.*(?:Z|[+-]\d\d:\d\d)$/.test(dueAt) ? new Date(dueAt) : null;
  const date = day ?? (timestamp && Number.isFinite(timestamp.getTime()) ? timestamp : null);
  const heading = document.createElement('strong'); heading.className = 'teams-task-card-title'; heading.textContent = title;
  const badge = document.createElement(date ? 'time' : 'span'); badge.className = 'teams-task-card-date';
  if (date) {
    badge.dateTime = day ? dueOn : dueAt;
    const basis = document.createElement('span'); basis.textContent = dateLabel;
    const prominent = document.createElement('strong'); prominent.textContent = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', ...(day ? { timeZone: 'UTC' } : {}) });
    const detail = document.createElement('span'); detail.textContent = date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', ...(day ? { timeZone: 'UTC' } : { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }) });
    badge.append(basis, prominent, detail);
  } else badge.textContent = unknownLabel;
  card.append(heading, badge);
  if (note) { const context = document.createElement('span'); context.className = 'teams-task-card-context'; context.textContent = note; card.append(context); }
  if (completed) { const status = document.createElement('span'); status.textContent = 'Completed'; card.append(status); }
  card.onclick = onOpen;
  return card;
}
