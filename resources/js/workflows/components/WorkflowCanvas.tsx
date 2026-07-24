import React, { useMemo, useState } from "react";
import {
  CheckIcon,
  DelayIcon,
  DragIcon,
  FilterIcon,
  PathIcon,
  PlusIcon,
  TriggerIcon,
  WorkflowIcon,
  ZoomInIcon,
  ZoomOutIcon,
} from "../icons";
import { componentForStep } from "../normalize";
import type {
  CatalogComponent,
  StepLocation,
  WorkflowBranch,
  WorkflowDefinition,
  WorkflowStep,
} from "../types";
import { ProviderMark } from "./ProviderMark";

type WorkflowCanvasProps = {
  definition: WorkflowDefinition;
  components: CatalogComponent[];
  selectedStepId: string | null;
  testState: Record<string, unknown>;
  onSelectStep: (stepId: string | null) => void;
  onAddStep: (location: StepLocation) => void;
  onMoveStep: (stepId: string, direction: -1 | 1) => void;
  onReorderStep: (draggedId: string, targetId: string) => void;
};

function stepKindLabel(step: WorkflowStep): string {
  return {
    trigger: "Trigger",
    action: "Action",
    filter: "Filter",
    delay: "Delay",
    paths: "Paths",
    utility: "Utility",
  }[step.kind];
}

function stepStatus(testState: Record<string, unknown>, step: WorkflowStep): "passed" | "failed" | "untested" {
  const direct = testState[step.id];
  const legacy = step.kind === "trigger" ? testState.trigger : (step.kind === "action" ? testState.action : null);
  const raw = direct && typeof direct === "object"
    ? direct as Record<string, unknown>
    : (legacy && typeof legacy === "object" ? legacy as Record<string, unknown> : {});
  if (raw.ok === true || raw.status === "passed" || raw.status === "success") return "passed";
  if (raw.ok === false || raw.status === "failed") return "failed";
  return "untested";
}

function mappedSummary(value: unknown): string {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    const mapped = value as Record<string, unknown>;
    if (mapped.type === "mapping") return String(mapped.path ?? "");
    if (mapped.type === "literal") return String(mapped.value ?? "");
  }
  return String(value ?? "");
}

function configSummary(step: WorkflowStep, component: CatalogComponent | null): string {
  if (step.kind === "filter") {
    const conditions = Array.isArray(step.config.conditions) ? step.config.conditions.length : 0;
    return conditions > 0 ? `${conditions} condition${conditions === 1 ? "" : "s"}` : "Set conditions";
  }
  if (step.kind === "delay") {
    if (step.component_key.includes("until") || step.config.mode === "until") {
      return mappedSummary(step.config.datetime) || "Choose a date or mapped value";
    }
    const amount = step.config.duration;
    const unit = step.config.unit;
    return amount && unit ? `Wait ${mappedSummary(amount)} ${unit}` : "Choose how long to wait";
  }
  if (step.kind === "paths") {
    const count = step.branches?.length ?? 0;
    return `${count} path${count === 1 ? "" : "s"}`;
  }

  const configuredField = component?.config_fields.find((field) => {
    const value = step.config[field.key];
    return value !== null && value !== undefined && value !== "";
  });
  if (configuredField) {
    const option = configuredField.options?.find((item) => item.value === String(step.config[configuredField.key]));
    return option?.label ?? String(step.config[configuredField.key]);
  }
  if (step.connection_id) return "Account selected";
  return component?.connection_required ? "Needs setup" : "Ready to configure";
}

function StepKindMark({ step, component }: { step: WorkflowStep; component: CatalogComponent | null }) {
  if (component) {
    return <ProviderMark provider={component.provider} label={component.provider_label} kind={step.kind} size="md" />;
  }
  if (step.kind === "filter") return <span className="eb-step-kind-mark"><FilterIcon /></span>;
  if (step.kind === "delay") return <span className="eb-step-kind-mark"><DelayIcon /></span>;
  if (step.kind === "paths") return <span className="eb-step-kind-mark"><PathIcon /></span>;
  if (step.kind === "trigger") return <span className="eb-step-kind-mark"><TriggerIcon /></span>;
  return <span className="eb-step-kind-mark"><WorkflowIcon /></span>;
}

function AddStepButton({
  onClick,
  label = "Add a workflow step",
}: {
  onClick: () => void;
  label?: string;
}) {
  return (
    <div className="eb-flow-connector">
      <span />
      <button type="button" onClick={onClick} aria-label={label}>
        <PlusIcon size={14} />
      </button>
      <span />
    </div>
  );
}

function StepNode({
  step,
  component,
  selected,
  status,
  canMoveUp,
  canMoveDown,
  onSelect,
  onMove,
  onDragStart,
  onDrop,
}: {
  step: WorkflowStep;
  component: CatalogComponent | null;
  selected: boolean;
  status: "passed" | "failed" | "untested";
  canMoveUp: boolean;
  canMoveDown: boolean;
  onSelect: () => void;
  onMove: (direction: -1 | 1) => void;
  onDragStart: () => void;
  onDrop: () => void;
}) {
  return (
    <article
      className={`eb-workflow-node ${selected ? "is-selected" : ""} is-${status}`}
      draggable={step.kind !== "trigger" && step.kind !== "paths"}
      onDragStart={(event) => {
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", step.id);
        onDragStart();
      }}
      onDragOver={(event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
      }}
      onDrop={(event) => {
        event.preventDefault();
        onDrop();
      }}
    >
      <button
        type="button"
        className="eb-workflow-node__main"
        role="treeitem"
        aria-selected={selected}
        data-workflow-step-id={step.id}
        onClick={onSelect}
      >
        <StepKindMark step={step} component={component} />
        <span className="eb-workflow-node__copy">
          <span className="eb-workflow-node__meta">
            <strong>{stepKindLabel(step)}</strong>
            {status === "passed" && <small><CheckIcon size={12} /> Tested</small>}
            {status === "failed" && <small className="is-error">Needs attention</small>}
          </span>
          <span className="eb-workflow-node__title">
            {component?.label ?? step.component_key.replaceAll(/[._]/g, " ")}
          </span>
          <span className="eb-workflow-node__summary">{configSummary(step, component)}</span>
        </span>
        <span className="eb-workflow-node__chevron" aria-hidden="true">›</span>
      </button>
      {step.kind !== "trigger" && (
        <div className="eb-workflow-node__tools" aria-label="Reorder step">
          <span className="eb-workflow-node__drag" title="Drag to reorder"><DragIcon size={16} /></span>
          <button type="button" disabled={!canMoveUp} onClick={() => onMove(-1)} aria-label="Move step up">↑</button>
          <button type="button" disabled={!canMoveDown} onClick={() => onMove(1)} aria-label="Move step down">↓</button>
        </div>
      )}
    </article>
  );
}

function BranchColumn({
  parentStep,
  branch,
  branchIndex,
  components,
  selectedStepId,
  testState,
  draggedId,
  onDraggedIdChange,
  onSelectStep,
  onAddStep,
  onMoveStep,
  onReorderStep,
}: {
  parentStep: WorkflowStep;
  branch: WorkflowBranch;
  branchIndex: number;
  components: CatalogComponent[];
  selectedStepId: string | null;
  testState: Record<string, unknown>;
  draggedId: string | null;
  onDraggedIdChange: (stepId: string | null) => void;
  onSelectStep: (stepId: string) => void;
  onAddStep: (location: StepLocation) => void;
  onMoveStep: (stepId: string, direction: -1 | 1) => void;
  onReorderStep: (draggedId: string, targetId: string) => void;
}) {
  return (
    <section className="eb-path-branch">
      <header>
        <span>{branch.type === "fallback" ? "F" : String.fromCharCode(65 + branchIndex)}</span>
        <div>
          <strong>{branch.name}</strong>
          <small>
            {branch.type === "fallback"
              ? "When no path matches"
              : branch.type === "always"
                ? "Always runs"
                : `${branch.conditions?.length ?? 0} rule${branch.conditions?.length === 1 ? "" : "s"}`}
          </small>
        </div>
      </header>
      <div className="eb-path-branch__flow">
        <AddStepButton
          label={`Add the first step to ${branch.name}`}
          onClick={() => onAddStep({ scope: "branch", parentStepId: parentStep.id, branchId: branch.id, index: 0 })}
        />
        {branch.steps.map((step, index) => {
          const component = componentForStep(components, step);
          return (
            <React.Fragment key={step.id}>
              <StepNode
                step={step}
                component={component}
                selected={selectedStepId === step.id}
                status={stepStatus(testState, step)}
                canMoveUp={index > 0 && step.kind !== "paths"}
                canMoveDown={step.kind !== "paths"
                  && index < branch.steps.length - 1
                  && branch.steps[index + 1]?.kind !== "paths"}
                onSelect={() => onSelectStep(step.id)}
                onMove={(direction) => onMoveStep(step.id, direction)}
                onDragStart={() => onDraggedIdChange(step.id)}
                onDrop={() => {
                  if (draggedId && draggedId !== step.id) onReorderStep(draggedId, step.id);
                  onDraggedIdChange(null);
                }}
              />
              {step.kind === "paths" && (
                <PathsBranches
                  step={step}
                  components={components}
                  selectedStepId={selectedStepId}
                  testState={testState}
                  draggedId={draggedId}
                  onDraggedIdChange={onDraggedIdChange}
                  onSelectStep={onSelectStep}
                  onAddStep={onAddStep}
                  onMoveStep={onMoveStep}
                  onReorderStep={onReorderStep}
                />
              )}
              {step.kind !== "paths" && (
                <AddStepButton
                  onClick={() => onAddStep({ scope: "branch", parentStepId: parentStep.id, branchId: branch.id, index: index + 1 })}
                  label={`Add a step after ${component?.label ?? "this step"}`}
                />
              )}
            </React.Fragment>
          );
        })}
        {branch.steps.length === 0 && <p className="eb-path-branch__empty">Add an action or control</p>}
      </div>
    </section>
  );
}

function PathsBranches({
  step,
  components,
  selectedStepId,
  testState,
  draggedId,
  onDraggedIdChange,
  onSelectStep,
  onAddStep,
  onMoveStep,
  onReorderStep,
}: {
  step: WorkflowStep;
  components: CatalogComponent[];
  selectedStepId: string | null;
  testState: Record<string, unknown>;
  draggedId: string | null;
  onDraggedIdChange: (stepId: string | null) => void;
  onSelectStep: (stepId: string) => void;
  onAddStep: (location: StepLocation) => void;
  onMoveStep: (stepId: string, direction: -1 | 1) => void;
  onReorderStep: (draggedId: string, targetId: string) => void;
}) {
  return (
    <div className="eb-paths" role="group" aria-label="Workflow paths">
      <div className="eb-paths__stem" aria-hidden="true" />
      <div
        className="eb-paths__grid"
        style={{ "--eb-path-count": Math.max(1, step.branches?.length ?? 0) } as React.CSSProperties}
      >
        {(step.branches ?? []).map((branch, branchIndex) => (
          <BranchColumn
            key={branch.id}
            parentStep={step}
            branch={branch}
            branchIndex={branchIndex}
            components={components}
            selectedStepId={selectedStepId}
            testState={testState}
            draggedId={draggedId}
            onDraggedIdChange={onDraggedIdChange}
            onSelectStep={onSelectStep}
            onAddStep={onAddStep}
            onMoveStep={onMoveStep}
            onReorderStep={onReorderStep}
          />
        ))}
      </div>
    </div>
  );
}

export function WorkflowCanvas({
  definition,
  components,
  selectedStepId,
  testState,
  onSelectStep,
  onAddStep,
  onMoveStep,
  onReorderStep,
}: WorkflowCanvasProps) {
  const [zoom, setZoom] = useState(100);
  const [draggedId, setDraggedId] = useState<string | null>(null);
  const triggerComponent = useMemo(
    () => componentForStep(components, definition.trigger),
    [components, definition.trigger],
  );

  return (
    <section
      className="eb-workflow-canvas"
      role="region"
      aria-label="Workflow canvas"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onSelectStep(null);
      }}
    >
      <div className="eb-workflow-canvas__dots" aria-hidden="true" />
      <div className="eb-workflow-canvas__meta">
        <span>Workflow</span>
        <span>Checks every {definition.settings.poll_interval_minutes} minutes</span>
      </div>

      <div
        className="eb-workflow-flow"
        role="tree"
        aria-label="Workflow steps"
        style={{ "--eb-workflow-zoom": zoom / 100 } as React.CSSProperties}
      >
        {definition.trigger ? (
          <StepNode
            step={definition.trigger}
            component={triggerComponent}
            selected={selectedStepId === definition.trigger.id}
            status={stepStatus(testState, definition.trigger)}
            canMoveUp={false}
            canMoveDown={false}
            onSelect={() => onSelectStep(definition.trigger!.id)}
            onMove={() => undefined}
            onDragStart={() => undefined}
            onDrop={() => undefined}
          />
        ) : (
          <button
            type="button"
            className="eb-empty-trigger"
            onClick={() => onAddStep({ scope: "trigger" })}
          >
            <span><TriggerIcon /></span>
            <strong>Choose a trigger</strong>
            <small>Select the event that starts this workflow</small>
          </button>
        )}

        {definition.trigger && (
          <AddStepButton
            onClick={() => onAddStep({ scope: "root", index: 0 })}
            label="Add the first action or control"
          />
        )}

        {definition.steps.map((step, index) => {
          const component = componentForStep(components, step);
          return (
            <React.Fragment key={step.id}>
              <StepNode
                step={step}
                component={component}
                selected={selectedStepId === step.id}
                status={stepStatus(testState, step)}
                canMoveUp={index > 0 && step.kind !== "paths"}
                canMoveDown={step.kind !== "paths"
                  && index < definition.steps.length - 1
                  && definition.steps[index + 1]?.kind !== "paths"}
                onSelect={() => onSelectStep(step.id)}
                onMove={(direction) => onMoveStep(step.id, direction)}
                onDragStart={() => setDraggedId(step.id)}
                onDrop={() => {
                  if (draggedId && draggedId !== step.id) onReorderStep(draggedId, step.id);
                  setDraggedId(null);
                }}
              />

              {step.kind === "paths" && (
                <PathsBranches
                  step={step}
                  components={components}
                  selectedStepId={selectedStepId}
                  testState={testState}
                  draggedId={draggedId}
                  onDraggedIdChange={setDraggedId}
                  onSelectStep={onSelectStep}
                  onAddStep={onAddStep}
                  onMoveStep={onMoveStep}
                  onReorderStep={onReorderStep}
                />
              )}

              {step.kind !== "paths" && (
                <AddStepButton
                  onClick={() => onAddStep({ scope: "root", index: index + 1 })}
                  label={`Add a step after ${component?.label ?? "this step"}`}
                />
              )}
            </React.Fragment>
          );
        })}

        {definition.trigger && definition.steps.length === 0 && (
          <div className="eb-flow-empty-message">
            <strong>Add what happens next</strong>
            <span>Choose an action or add flow control.</span>
          </div>
        )}
      </div>

      <div className="eb-canvas-zoom" aria-label="Canvas zoom">
        <button type="button" aria-label="Zoom out" onClick={() => setZoom((value) => Math.max(70, value - 10))}>
          <ZoomOutIcon size={16} />
        </button>
        <button type="button" className="eb-canvas-zoom__value" onClick={() => setZoom(100)} aria-label="Reset zoom to 100 percent">
          {zoom}%
        </button>
        <button type="button" aria-label="Zoom in" onClick={() => setZoom((value) => Math.min(120, value + 10))}>
          <ZoomInIcon size={16} />
        </button>
      </div>
    </section>
  );
}
