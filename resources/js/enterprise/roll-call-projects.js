/* Presentation over already-authorized responses. This module never grants access or sends commands. */
(() => {
  'use strict';
  const unique = rows => [...new Map(rows.filter(Boolean).map(row => [String(row.id), row])).values()];
  const names = rows => unique(rows).filter(row => row.name);
  const text = value => typeof value === 'string' ? value : '';
  function projectRows(scopes) {
    return scopes.flatMap(scope => {
      const source = scope.data || {}, members = source.members || [], operations = scope.operations?.items || [], planning = scope.planning?.items || [];
      const person = id => members.find(row => String(row.id) === String(id));
      return (source.projects || []).map(project => {
        const tasks = Array.isArray(project.tasks) ? project.tasks.filter(row => row && typeof row === 'object' && row.id) : [];
        const work = operations.filter(row => String(row.project_id) === String(project.id));
        const requests = (source.requests || []).filter(row => String(row.data?.project_id || row.task?.project_id || '') === String(project.id));
        const owner = project.owner?.name ? project.owner : person(project.owner_id);
        const assigned = names([...tasks.flatMap(row => [row.assignee?.name ? row.assignee : person(row.assignee_id)]), ...work.flatMap(row => [person(row.owner_user_id), person(row.ball_user_id)]), ...requests.map(row => person(row.task?.assignee_id))]);
        const schedules = planning.filter(row => row.kind === 'schedule' && row.status !== 'cancelled' && String(row.data?.project_id) === String(project.id));
        const contractors = names(schedules.map(schedule => { const contact = planning.find(row => row.kind === 'contractor' && String(row.id) === String(schedule.data?.contractor_id)); return contact ? {id:contact.id, name:contact.title} : null; }));
        const locations = [...new Set([...(project.locations || []).map(row => typeof row === 'string' ? row : row.label || row.name || row.title), ...requests.map(row => row.data?.location)].filter(Boolean))];
        const status = project.archived ? 'Archived' : text(project.current_status?.code || project.current_status?.status || project.current_status?.label || project.current_status?.title) || text(project.status) || 'Active';
        const completed = tasks.filter(row => row.completed_at).length;
        return {id:String(project.id), areaId:String(source.selected_area_id || scope.areaId), areaName:scope.areaName || '', name:project.name || project.title || 'Untitled project', description:project.description || '', owner:owner || null, people:assigned, contractors, locations, status, archived:Boolean(project.archived), tasks, work, completed, updatedAt:project.last_visible_work_at || project.updated_at || null, coverage:project.tasks_coverage || 'Only returned, readable work is shown.', raw:project};
      });
    });
  }
  function filter(rows, filters = {}) {
    const query = String(filters.query || '').trim().toLocaleLowerCase();
    return rows.filter(row => (!filters.area || row.areaId === filters.area) && (!filters.person || String(row.owner?.id) === filters.person || row.people.some(person => String(person.id) === filters.person)) && (!filters.contractor || row.contractors.some(person => String(person.id) === filters.contractor)) && (!filters.status || row.status === filters.status) && (!filters.location || row.locations.includes(filters.location)) && (!query || [row.name,row.areaName,row.description,row.owner?.name,...row.people.map(person => person.name),...row.contractors.map(person => person.name),...row.locations].filter(Boolean).join(' ').toLocaleLowerCase().includes(query)));
  }
  globalThis.EverbranchRollProjects = Object.freeze({projectRows,filter,unique});
})();
