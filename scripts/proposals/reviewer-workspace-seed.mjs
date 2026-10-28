import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
import vm from 'node:vm';

/** Pure fresh fictional fixture export. No browser-state input, HTTP, runtime initialization or publication. */
export const SOURCE_PINS = Object.freeze({
  'resources/js/enterprise/reviewer/job-sample.js':'66cc7917bec20b0835364e6c6b5f41f7960dd98f401485e80bd69649a39511c1',
  'resources/js/enterprise/reviewer/sample-adapter.js':'291302611a287f97431dd573eb9806f7bb68f2f8e212afd66e9c48513f3fa653',
  'resources/data/enterprise/lees-mcrae-locations.json':'71d604a832a6ebf9e7e4e5203eb62b11aea54322471d394f456ddd99de85e287',
});
const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const FIXTURE_TIME = '2026-10-23T08:00:00.000Z';
const fail = message => { throw new Error(`Fictional seed export refused: ${message}`); };
const requireValue = (value, message) => { if (!value) fail(message); };
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const person = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,32}$/.test(value);
const integer = value => Number.isSafeInteger(value) && value >= 1;
const sha = value => createHash('sha256').update(value).digest('hex');
export function canonical(value) {
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`;
  return JSON.stringify(value);
}
const sortIds = rows => rows.sort((a,b) => a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
const clone = value => JSON.parse(JSON.stringify(value));
function index(rows, name, idCheck = uuid) {
  requireValue(Array.isArray(rows), `${name} collection absent`);
  const map = new Map();
  for (const row of rows) {
    requireValue(row && idCheck(row.id) && !map.has(row.id), `${name} identity invalid or duplicated`);
    map.set(row.id,row);
  }
  return map;
}
const text = (value, name, fallback) => {
  if (value === undefined && fallback !== undefined) return fallback;
  requireValue(typeof value === 'string', `${name} text absent`);
  return value;
};
function resolved(values, name) {
  const present = values.filter(value => value !== undefined && value !== null);
  requireValue(present.every(value => typeof value === 'string') && new Set(present).size <= 1, `${name} identities disagree`);
  return present[0] ?? null;
}
const nullable = value => value === undefined || value === '' ? null : value;

async function freshFixture(root) {
  const files = {};
  for (const [path,pin] of Object.entries(SOURCE_PINS)) {
    const bytes = await readFile(resolve(root,path));
    requireValue(sha(bytes) === pin, `source fingerprint changed: ${path}`);
    files[path] = bytes.toString('utf8');
  }
  const catalog = JSON.parse(files['resources/data/enterprise/lees-mcrae-locations.json']);
  let forbiddenCalls = 0;
  class FixedDate extends Date {
    constructor(...args) { super(...(args.length ? args : [FIXTURE_TIME])); }
    static now() { return Date.parse(FIXTURE_TIME); }
  }
  const deny = () => { forbiddenCalls++; fail('network, random identity or external storage requested'); };
  // VM is not a security sandbox for arbitrary code. Exact reviewed source fingerprints are mandatory.
  const context = vm.createContext({Date:FixedDate, URL, URLSearchParams, TextEncoder,
    fetch:deny, XMLHttpRequest:deny, WebSocket:deny,
    crypto:Object.freeze({randomUUID:deny,subtle:Object.freeze({digest:deny})}),
    location:Object.freeze({href:'https://fictional.invalid/platform/demos/lees-mcrae'}),
    history:Object.freeze({replaceState:deny}),
    __boot:{runtime:'sample',defaultPersona:'demo_manager',identity:{workspace:'source-only-fresh-seed'},locations:catalog.entries,
      routes:{back:'/platform/demos/lees-mcrae',workflowWorkspace:'/platform/demos/lees-mcrae/workflows'}},
  }, {codeGeneration:{strings:false,wasm:false}});
  Object.defineProperty(context,'sessionStorage',{get:deny});
  vm.runInContext(files['resources/js/enterprise/reviewer/job-sample.js'],context,{timeout:1000});
  vm.runInContext(files['resources/js/enterprise/reviewer/sample-adapter.js'],context,{timeout:1000});
  vm.runInContext(`__boot.sampleStorage=Object.freeze({getItem:()=>null,setItem:()=>{},removeItem:()=>{},get length(){return 0;},key:()=>null});
    globalThis.__adapter=EverbranchSampleTransport.create(__boot);`,context,{timeout:1000});
  const state = clone(context.__adapter.snapshot());
  const members = clone(context.EverbranchJobSample.members());
  const directories = [];
  for (const area of state.areas) {
    const view = await context.__adapter.request('/enterprise/data?area_id='+area.id);
    directories.push({area_id:area.id,members:clone(view.members)});
  }
  requireValue(forbiddenCalls === 0, 'forbidden fixture side effect');
  return {state,members,directories,forbiddenCalls};
}

function mapFixture({state,members,directories}) {
  requireValue(state.sample_migrations?.['campus-jobs-20-v1'] && state.sample_migrations?.['campus-job-plans-v2'], 'canonical job/plan repair not applied');
  requireValue(state.planning === undefined || Array.isArray(state.planning) && state.planning.length === 0, 'orphan planning collection is not canonical');
  const areas = index(state.areas,'areas'), projects = index(state.projects,'projects'), tasks = index(state.tasks,'tasks'), tickets = index(state.requests,'tickets');
  const locations = index(state.resources.filter(row => row.kind === 'location'),'buildings');
  const contractors = index(state.plans.filter(row => row.kind === 'contractor'),'contractors');
  const schedules = index(state.plans.filter(row => row.kind === 'schedule'),'plans');
  requireValue(state.plans.every(row => ['contractor','schedule'].includes(row.kind)), 'unexpected canonical plan kind; costs are not inferred');
  const memberMap = index(members,'members',person);
  const people = new Map();
  for (const directory of directories) {
    requireValue(areas.has(directory.area_id), 'directory department absent');
    for (const member of directory.members) {
      const original = memberMap.get(member.id);
      requireValue(original && original.name === member.name, 'directory identity differs from official fictional members');
      if (!people.has(member.id)) people.set(member.id,{id:member.id,name:member.name,version:1,area_ids:[],active:true,updated_at:null});
      const row = people.get(member.id);
      requireValue(!row.area_ids.includes(directory.area_id), 'duplicate directory department');
      row.area_ids.push(directory.area_id);
    }
  }
  for (const row of people.values()) row.area_ids.sort();
  const checkPerson = (id,area) => {
    if (id === null) return;
    requireValue(people.has(id) && people.get(id).area_ids.includes(area), 'assignment outside explicit fictional directory');
  };
  const taskProjects = new Map([...tasks.keys()].map(id => [id,[]]));
  for (const project of projects.values()) {
    requireValue(areas.has(project.enterprise_area_id) && Array.isArray(project.tasks), 'project department or explicit task list absent');
    requireValue(new Set(project.tasks).size === project.tasks.length, 'duplicate project/task link');
    for (const id of project.tasks) {
      requireValue(tasks.has(id), 'project links missing task');
      taskProjects.get(id).push(project.id);
    }
  }
  const taskProject = new Map();
  for (const task of tasks.values()) {
    const reverse = taskProjects.get(task.id);
    requireValue(reverse.length === 1, 'unassigned or shared task cannot be represented without inventing a project');
    const project = resolved([task.project_id,reverse[0]],'task/project');
    requireValue(projects.has(project) && projects.get(project).enterprise_area_id === task.enterprise_area_id, 'task/project department mismatch');
    taskProject.set(task.id,project);
  }
  const ticketLinks = new Map(), linksByTask = new Map([...tasks.keys()].map(id => [id,[]]));
  for (const ticket of tickets.values()) {
    requireValue(areas.has(ticket.area_id) && ticket.data && typeof ticket.data === 'object', 'ticket department/data absent');
    const taskId = resolved([ticket.task_id,ticket.data.task_id],'ticket/task');
    const projectId = resolved([ticket.project_id,ticket.data.project_id],'ticket/project');
    requireValue(projectId === null || projects.has(projectId) && projects.get(projectId).enterprise_area_id === ticket.area_id, 'ticket project absent or cross-department');
    if (taskId !== null) {
      requireValue(tasks.has(taskId) && tasks.get(taskId).enterprise_area_id === ticket.area_id && taskProject.get(taskId) === projectId, 'ticket/task/project ambiguity');
      linksByTask.get(taskId).push(ticket.id);
    }
    const buildingId = resolved([ticket.data.location_id,ticket.data.location_reference?.id],'ticket/building');
    if (buildingId !== null) {
      const building = locations.get(buildingId);
      requireValue(building && building.area_id === ticket.area_id && integer(building.version)
        && ticket.data.location_version === building.version
        && (ticket.data.location_reference?.version === undefined || ticket.data.location_reference.version === building.version), 'ticket saved building/version invalid');
    }
    ticketLinks.set(ticket.id,{taskId,projectId,buildingId});
  }
  const graph = {schema_version:1,
    areas:sortIds([...areas.values()].map(row => ({id:row.id,name:row.name,visibility:row.visibility}))),
    buildings:sortIds([...locations.values()].map(row => {
      requireValue(areas.has(row.area_id) && integer(row.version) && row.status === 'active', 'building scope/version/status invalid');
      return {id:row.id,area_id:row.area_id,title:text(row.title,'building'),version:row.version,status:row.status,updated_at:null};
    })),
    people:sortIds([...people.values()]),
    projects:sortIds([...projects.values()].map(row => {
      requireValue(integer(row.version) && typeof row.archived === 'boolean', 'project lifecycle/version invalid');
      const status = row.archived ? 'archived' : row.current_status?.code ?? 'active';
      requireValue(['active','on_track','on_hold','at_risk','archived'].includes(status), 'unsupported project status');
      const owner = nullable(row.owner_id) ?? null; checkPerson(owner,row.enterprise_area_id);
      return {id:row.id,area_id:row.enterprise_area_id,title:text(row.name,'project'),description:text(row.description,'project description',''),
        version:row.version,status,owner_id:owner,due_on:nullable(row.due_on) ?? null,updated_at:null};
    })),
    tasks:sortIds([...tasks.values()].map(row => {
      requireValue(integer(row.version) && ['active','in_progress','interrupted','awaiting_verification','completed','closed','archived'].includes(row.status)
        && !row.deleted, 'task lifecycle/version invalid');
      const linked = linksByTask.get(row.id);
      requireValue(linked.length <= 1, 'multiple tickets cannot choose an implied task building');
      const building = resolved([row.building_id,linked.length ? ticketLinks.get(linked[0]).buildingId : null],'task/building');
      const assignee = nullable(row.assignee_id) ?? null; checkPerson(assignee,row.enterprise_area_id);
      return {id:row.id,project_id:taskProject.get(row.id),area_id:row.enterprise_area_id,title:text(row.title,'task'),description:text(row.description,'task description',''),
        version:row.version,status:row.status,priority:nullable(row.priority) ?? null,due_on:nullable(row.due_on) ?? null,
        assignee_id:assignee,building_id:building,updated_at:null};
    })),
    tickets:[],contractors:[],plans:[],costs:[]};
  const positions = new Map();
  for (const row of tickets.values()) {
    requireValue(integer(row.version) && ['submitted','triaged','assigned','in_progress','completed','closed'].includes(row.status), 'ticket lifecycle/version invalid');
    const {taskId,projectId,buildingId} = ticketLinks.get(row.id);
    const linkedPriority = taskId ? nullable(tasks.get(taskId).priority) : null;
    const requestedPriority = nullable(row.data.priority);
    requireValue(linkedPriority == null || requestedPriority == null || linkedPriority === requestedPriority, 'ticket/task priority disagree');
    const key = row.area_id+'|'+row.status, position = positions.get(key) ?? 0; positions.set(key,position+1);
    graph.tickets.push({id:row.id,area_id:row.area_id,title:text(row.title,'ticket'),description:text(row.data.description,'ticket description',''),version:row.version,
      status:row.status,priority:linkedPriority ?? requestedPriority ?? null,due_on:nullable(row.due_on) ?? null,building_id:buildingId,
      project_id:projectId,task_id:taskId,owner_capability_hash:null,position,type:row.data.request_type ?? 'general',updated_at:null});
  }
  for (const row of contractors.values()) {
    requireValue(areas.has(row.area_id) && integer(row.version) && row.status === 'active', 'contractor scope/version/status invalid');
    graph.contractors.push({id:row.id,area_id:row.area_id,title:text(row.title,'contractor'),version:row.version,status:row.status,updated_at:null});
  }
  for (const row of schedules.values()) {
    const projectId = row.data?.project_id, contractorId = row.data?.contractor_id;
    requireValue(projects.has(projectId) && contractors.has(contractorId) && row.area_id === projects.get(projectId).enterprise_area_id
      && row.area_id === contractors.get(contractorId).area_id && integer(row.version) && row.status === 'planned', 'canonical contractor plan FK/status invalid');
    graph.plans.push({id:row.id,area_id:row.area_id,project_id:projectId,contractor_id:contractorId,version:row.version,status:row.status,
      starts_on:row.starts_on,ends_on:row.ends_on,updated_at:null});
  }
  for (const rows of [graph.tickets,graph.contractors,graph.plans]) sortIds(rows);
  const expected = {areas:4,projects:20,tasks:21,tickets:25,contractors:5,plans:18,people:11,costs:0};
  for (const [kind,count] of Object.entries(expected)) requireValue(graph[kind].length === count, `${kind} fixture count changed`);
  const workers = members.filter(row => row.field_worker === true).map(row => row.id).sort();
  requireValue(workers.length === 10 && workers.every(id => people.has(id)), 'ten explicit field workers absent');
  requireValue(graph.tickets.filter(row => row.task_id === null && row.project_id === null).length === 5, 'incoming queue count changed');
  return {graph,fieldWorkerIds:workers};
}

export async function exportFreshSeed(options = {}) {
  requireValue(options && Object.keys(options).every(key => key === 'root') && (options.root === undefined || typeof options.root === 'string'), 'only a source repository root is accepted; no browser state');
  const fixture = await freshFixture(options.root ?? ROOT);
  const {graph,fieldWorkerIds} = mapFixture(fixture);
  return {schema_version:1,purpose:'fresh_official_fictional_seed_only',graph,
    evidence:{source_pins:SOURCE_PINS,fixture_clock:FIXTURE_TIME,graph_sha256:sha(canonical(graph)),graph_bytes:Buffer.byteLength(canonical(graph)),
      counts:Object.fromEntries(Object.entries(graph).filter(([,value]) => Array.isArray(value)).map(([key,value]) => [key,value.length])),
      field_worker_ids:fieldWorkerIds,field_workers:fieldWorkerIds.length,incoming_tickets:5,canonical_plans_revision:'campus-job-plans-v2',forbidden_calls:fixture.forbiddenCalls,
      timestamp_policy:'Imported updated_at is null. Due dates and planned dates retain fictional source values; no server modification time is asserted.',
      relationship_policy:'Exact source project/task/ticket/building joins only. No ticket or building is invented for an unlinked task.',
      ownership_policy:'Seed ticket owner_capability_hash is null. Source requester names/created_by confer no recipient ownership.',
      directory_policy:'Fictional eligibility is the explicit per-department sample members directory; no native account or membership.',
      excluded:['browser/session state','notes/progress/checklists','files/photos/uploads','contacts','review comments/names','credentials/auth/receipts','native records','Finance packets and inferred costs'],
      recovers_browser_edits:false,initializes_runtime:false,publishes:false}};
}

/** Narrow fresh pinned source metadata only, for the separate map export; never accepts browser state. */
export async function exportFreshBuildingMapInputs(options = {}) {
  requireValue(options && Object.keys(options).every(key => key === 'root') && (options.root === undefined || typeof options.root === 'string'), 'only a source repository root is accepted; no browser state');
  const fixture = await freshFixture(options.root ?? ROOT);
  return fixture.state.resources.filter(row => row.kind === 'location').map(row => ({
    id:row.id, area_id:row.area_id, version:row.version, status:row.status,
    level:row.data?.level ?? null, map_number:row.data?.map_number ?? null,
    catalog_map_number:row.data?.catalog_map_number ?? null,
    source_url:row.data?.source_url ?? row.data?.source ?? null, source_page:row.data?.source_page ?? null,
  }));
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    requireValue(process.argv.slice(2).every(arg => arg === '--graph') && process.argv.slice(2).length <= 1, 'only optional --graph is accepted');
    const result = await exportFreshSeed();
    process.stdout.write(canonical(process.argv.includes('--graph') ? result.graph : result)+'\n');
  } catch (error) {
    process.stderr.write(String(error.message)+'\n'); process.exitCode=1;
  }
}
