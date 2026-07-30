import type {
  CatalogComponent,
  MappingOption,
  StepLocation,
  WorkflowBranch,
  WorkflowDefinition,
  WorkflowStep,
} from "./types";

export function cloneDefinition(definition: WorkflowDefinition): WorkflowDefinition {
  return structuredClone(definition);
}

export function definitionForApi(definition: WorkflowDefinition): WorkflowDefinition {
  const next = cloneDefinition(definition);

  const serializeSteps = (steps: WorkflowStep[]): WorkflowStep[] => steps.map((step) => {
    if (step.kind !== "paths") {
      return step;
    }

    const uiBranches = (step.branches ?? []).map((branch) => ({
      ...branch,
      steps: serializeSteps(branch.steps),
    }));
    const apiBranches = uiBranches.map((branch) => ({
      id: branch.id,
      name: branch.name,
      rule_type: branch.type,
      type: branch.type,
      condition: branch.type === "custom"
        ? {
            logic: branch.logic ?? "and",
            conditions: branch.conditions ?? [],
          }
        : null,
      logic: branch.logic ?? "and",
      conditions: branch.conditions ?? [],
      steps: branch.steps,
    }));

    return {
      ...step,
      config: {
        ...step.config,
        branches: apiBranches,
      },
      branches: uiBranches,
    };
  });

  next.steps = serializeSteps(next.steps);
  return next;
}

export function findStep(definition: WorkflowDefinition, stepId: string): WorkflowStep | null {
  if (definition.trigger?.id === stepId) {
    return definition.trigger;
  }

  const visit = (steps: WorkflowStep[]): WorkflowStep | null => {
    for (const step of steps) {
      if (step.id === stepId) {
        return step;
      }
      for (const branch of step.branches ?? []) {
        const found = visit(branch.steps);
        if (found) return found;
      }
    }
    return null;
  };

  return visit(definition.steps);
}

export function findBranch(definition: WorkflowDefinition, parentStepId: string, branchId: string): WorkflowBranch | null {
  const parent = findStep(definition, parentStepId);
  return parent?.branches?.find((branch) => branch.id === branchId) ?? null;
}

function sequenceContainingStep(steps: WorkflowStep[], stepId: string): WorkflowStep[] | null {
  if (steps.some((step) => step.id === stepId)) {
    return steps;
  }

  for (const step of steps) {
    for (const branch of step.branches ?? []) {
      const found = sequenceContainingStep(branch.steps, stepId);
      if (found) return found;
    }
  }

  return null;
}

export function isStepTerminal(definition: WorkflowDefinition, stepId: string): boolean {
  const sequence = sequenceContainingStep(definition.steps, stepId);
  return Boolean(sequence && sequence[sequence.length - 1]?.id === stepId);
}

export function isTerminalLocation(definition: WorkflowDefinition, location: StepLocation): boolean {
  if (location.scope === "trigger") return false;
  if (location.scope === "root") return location.index >= definition.steps.length;

  const branch = findBranch(definition, location.parentStepId, location.branchId);
  return Boolean(branch && location.index >= branch.steps.length);
}

function pathsAreTerminal(steps: WorkflowStep[]): boolean {
  return steps.every((step, index) => {
    if (step.kind === "paths" && index !== steps.length - 1) return false;
    return (step.branches ?? []).every((branch) => pathsAreTerminal(branch.steps));
  });
}

export function insertStep(definition: WorkflowDefinition, location: StepLocation, step: WorkflowStep): WorkflowDefinition {
  const next = cloneDefinition(definition);

  if (location.scope === "trigger") {
    next.trigger = step;
    return next;
  }

  if (location.scope === "root") {
    next.steps.splice(Math.max(0, Math.min(location.index, next.steps.length)), 0, step);
    return pathsAreTerminal(next.steps) ? next : definition;
  }

  const branch = findBranch(next, location.parentStepId, location.branchId);
  if (branch) {
    branch.steps.splice(Math.max(0, Math.min(location.index, branch.steps.length)), 0, step);
  }

  return pathsAreTerminal(next.steps) ? next : definition;
}

export function updateStep(
  definition: WorkflowDefinition,
  stepId: string,
  updater: (step: WorkflowStep) => WorkflowStep,
): WorkflowDefinition {
  const next = cloneDefinition(definition);
  if (next.trigger?.id === stepId) {
    next.trigger = updater(next.trigger);
    return next;
  }

  const visit = (steps: WorkflowStep[]): boolean => {
    for (let index = 0; index < steps.length; index += 1) {
      if (steps[index].id === stepId) {
        steps[index] = updater(steps[index]);
        return true;
      }
      for (const branch of steps[index].branches ?? []) {
        if (visit(branch.steps)) return true;
      }
    }
    return false;
  };

  visit(next.steps);
  return next;
}

export function deleteStep(definition: WorkflowDefinition, stepId: string): WorkflowDefinition {
  const next = cloneDefinition(definition);
  if (next.trigger?.id === stepId) {
    next.trigger = null;
    next.steps = [];
    return next;
  }

  const visit = (steps: WorkflowStep[]): boolean => {
    const index = steps.findIndex((step) => step.id === stepId);
    if (index >= 0) {
      steps.splice(index, 1);
      return true;
    }
    for (const step of steps) {
      for (const branch of step.branches ?? []) {
        if (visit(branch.steps)) return true;
      }
    }
    return false;
  };

  visit(next.steps);
  return next;
}

export function moveStep(
  definition: WorkflowDefinition,
  stepId: string,
  direction: -1 | 1,
): WorkflowDefinition {
  const next = cloneDefinition(definition);
  const visit = (steps: WorkflowStep[]): boolean => {
    const index = steps.findIndex((step) => step.id === stepId);
    if (index >= 0) {
      const destination = index + direction;
      if (destination >= 0 && destination < steps.length) {
        const [step] = steps.splice(index, 1);
        steps.splice(destination, 0, step);
      }
      return true;
    }
    for (const step of steps) {
      for (const branch of step.branches ?? []) {
        if (visit(branch.steps)) return true;
      }
    }
    return false;
  };
  visit(next.steps);
  return pathsAreTerminal(next.steps) ? next : definition;
}

export function reorderStep(
  definition: WorkflowDefinition,
  draggedId: string,
  targetId: string,
): WorkflowDefinition {
  const next = cloneDefinition(definition);
  const visit = (steps: WorkflowStep[]): boolean => {
    const from = steps.findIndex((step) => step.id === draggedId);
    const to = steps.findIndex((step) => step.id === targetId);
    if (from >= 0 && to >= 0 && from !== to) {
      const [step] = steps.splice(from, 1);
      steps.splice(to, 0, step);
      return true;
    }
    for (const step of steps) {
      for (const branch of step.branches ?? []) {
        if (visit(branch.steps)) return true;
      }
    }
    return false;
  };
  visit(next.steps);
  return pathsAreTerminal(next.steps) ? next : definition;
}

export function countSteps(definition: WorkflowDefinition): number {
  const visit = (steps: WorkflowStep[]): number => steps.reduce(
    (total, step) => total + 1 + (step.branches ?? []).reduce((branchTotal, branch) => branchTotal + visit(branch.steps), 0),
    0,
  );

  return (definition.trigger ? 1 : 0) + visit(definition.steps);
}

export function hasAction(definition: WorkflowDefinition): boolean {
  const visit = (steps: WorkflowStep[]): boolean => steps.some(
    (step) => step.kind === "action" || (step.branches ?? []).some((branch) => visit(branch.steps)),
  );
  return visit(definition.steps);
}

export function everyPathBranchHasAction(definition: WorkflowDefinition): boolean {
  const containsAction = (steps: WorkflowStep[]): boolean => steps.some(
    (step) => step.kind === "action"
      || (step.branches ?? []).some((branch) => containsAction(branch.steps)),
  );
  const visit = (steps: WorkflowStep[]): boolean => steps.every((step) => {
    if (step.kind !== "paths") {
      return true;
    }

    const branches = step.branches ?? [];
    return branches.length > 0
      && branches.every(
        (branch) => containsAction(branch.steps) && visit(branch.steps),
      );
  });

  return visit(definition.steps);
}

export function mappingOptionsForStep(
  definition: WorkflowDefinition,
  components: CatalogComponent[],
  targetStepId: string,
): MappingOption[] {
  const componentFor = (step: WorkflowStep) => components.find(
    (component) => component.key === step.component_key,
  );
  const optionsFor = (
    step: WorkflowStep,
    source: string,
    pathPrefix: string,
  ): MappingOption[] => {
    const component = componentFor(step);
    return (component?.output_fields ?? []).map((field) => ({
      path: `${pathPrefix}.${field.key}`,
      label: field.label,
      source,
      type: field.type,
    }));
  };

  if (definition.trigger?.id === targetStepId) {
    return [];
  }

  const triggerOptions = definition.trigger
    ? optionsFor(
        definition.trigger,
        `Trigger · ${componentFor(definition.trigger)?.label ?? "Trigger"}`,
        "trigger.output",
      )
    : [];

  const visit = (
    steps: WorkflowStep[],
    available: MappingOption[],
  ): MappingOption[] | null => {
    let current = [...available];

    for (const step of steps) {
      if (step.id === targetStepId) {
        return current;
      }

      if (step.kind === "paths") {
        for (const branch of step.branches ?? []) {
          const branchResult = visit(branch.steps, current);
          if (branchResult) return branchResult;
        }
      }

      current = [
        ...current,
        ...optionsFor(
          step,
          `Step · ${componentFor(step)?.label ?? step.component_key}`,
          `steps.${step.id}.output`,
        ),
      ];
    }

    return null;
  };

  return visit(definition.steps, triggerOptions) ?? triggerOptions;
}
