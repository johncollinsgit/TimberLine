// Select helper calls only. All groups retain scenario=all's feature flags,
// fixture, request pacing, assertions and cleanup in the parent harness.
export const operationsGroups = Object.freeze({
  work: Object.freeze(['mapped', 'roll-call', 'work-views', 'resources', 'task-evidence']),
  tour: Object.freeze(['tour']),
  workspace: Object.freeze(['procedures', 'finance', 'navigation', 'roles', 'voice']),
});

export function operationsCheckSelector({enabled, scenario, group = ''}) {
  if (group && (!enabled || scenario !== 'all' || !Object.hasOwn(operationsGroups, group))) {
    throw new Error('Operations group requires enabled scenario=all and a known group');
  }
  return check => scenario === check || (scenario === 'all' && (!group || operationsGroups[group].includes(check)));
}
