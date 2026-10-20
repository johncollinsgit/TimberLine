/** Read projections always describe their loaded-page coverage. Native edits use task details. */
export function renderPlanning(container, tasks, { node, layout, members = [], openTask }) {
  const notice = node('p', 'This view summarizes the tasks loaded on this page. Load other pages to inspect additional work.', 'muted'); container.append(notice);
  const open = task => { const button = node('button', task.title, 'task-open'); button.onclick = () => openTask(task.id); return button; };
  if (layout === 'timeline') {
    const dated = tasks.filter(task => task.start_on || task.due_on || task.start_at || task.due_at);
    const dates = dated.flatMap(task => [task.start_on || task.start_at?.slice(0, 10), task.due_on || task.due_at?.slice(0, 10)]).filter(Boolean).sort();
    if (dates.length) {
      const start = Date.parse(dates[0] + 'T00:00:00Z'), end = Date.parse(dates.at(-1) + 'T00:00:00Z'), range = Math.max(86400000, end - start + 86400000);
      const heading = node('div', '', 'teams-timeline-heading'); heading.append(node('span', dates[0]), node('span', dates.at(-1))); container.append(heading);
      for (const task of dated) {
        const row = node('div', '', 'teams-timeline-row'), lane = node('div', '', 'teams-timeline-lane');
        const from = Date.parse((task.start_on || task.start_at?.slice(0, 10) || task.due_on || task.due_at.slice(0, 10)) + 'T00:00:00Z'), until = Date.parse((task.due_on || task.due_at?.slice(0, 10) || task.start_on || task.start_at.slice(0, 10)) + 'T00:00:00Z');
        const bar = node('button', '', 'teams-timeline-bar'); bar.style.left = ((from - start) / range * 100) + '%'; bar.style.width = Math.max(2, (Math.max(0, until - from) + 86400000) / range * 100) + '%'; bar.setAttribute('aria-label', task.title + ', ' + (task.start_on || task.start_at || 'No start date') + ' to ' + (task.due_on || task.due_at || 'No deadline')); bar.onclick = () => openTask(task.id); lane.append(bar); row.append(open(task), lane); container.append(row);
      }
    }
    const unscheduled = tasks.filter(task => !dated.includes(task)); if (unscheduled.length) { container.append(node('h3', 'Unscheduled · ' + unscheduled.length)); unscheduled.forEach(task => container.append(open(task))); }
    if (!tasks.length) container.append(node('p', 'No tasks in this view.', 'teams-empty'));
  } else if (layout === 'workload') {
    const groups = new Map(); for (const task of tasks.filter(task => !task.completed_at)) { const key = task.assignee_id || ''; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(task); }
    const max = Math.max(1, ...[...groups.values()].map(items => items.length));
    for (const [id, items] of groups) { const section = node('details', '', 'teams-workload-row'); const summary = node('summary'); summary.append(node('span', members.find(member => member.id === id)?.name || (id ? 'Assigned member' : 'Unassigned')), node('strong', String(items.length) + ' open tasks')); const bar = node('div', '', 'teams-workload-meter'), fill = node('span'); fill.style.width = (items.length / max * 100) + '%'; bar.append(fill); summary.append(bar); section.append(summary); items.forEach(task => section.append(open(task))); container.append(section); }
    container.append(node('p', 'Task counts indicate distribution, not hours, capacity, or employee performance.', 'muted'));
  } else if (layout === 'dashboard') {
    const stats = node('div', '', 'teams-dashboard-stats');
    const completed = tasks.filter(task => task.completed_at).length, today = new Date().toLocaleDateString('en-CA');
    for (const [label, value] of [['Loaded tasks', tasks.length], ['Completed', completed], ['Open', tasks.length - completed], ['Overdue', tasks.filter(task => !task.completed_at && (task.due_on || task.due_at?.slice(0, 10)) < today).length]]) { const item = node('div'); item.append(node('span', label), node('strong', String(value))); stats.append(item); } container.append(stats);
    if (tasks.some(task => task.priority)) {
      container.append(node('h3', 'Priority distribution'));
      for (const priority of ['urgent', 'high', 'normal', 'low']) { const count = tasks.filter(task => task.priority === priority).length, row = node('div', '', 'teams-workload-row'); row.append(node('span', priority[0].toUpperCase() + priority.slice(1)), node('strong', String(count))); const meter = node('div', '', 'teams-workload-meter'), fill = node('span'); fill.style.width = (tasks.length ? count / tasks.length * 100 : 0) + '%'; meter.append(fill); row.append(meter); container.append(row); }
    }
  }
}
