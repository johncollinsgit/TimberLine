import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { WorkflowApiError, fillEndpoint, workflowRequest } from "./api";
import {
  cloneDefinition,
  countSteps,
  definitionForApi,
  deleteStep,
  everyPathBranchHasAction,
  findStep,
  hasAction,
  insertStep,
  isStepTerminal,
  isTerminalLocation,
  mappingOptionsForStep,
  moveStep,
  reorderStep,
  updateStep,
} from "./definition";
import {
  ArrowLeftIcon,
  CheckIcon,
  DotsIcon,
  RedoIcon,
  UndoIcon,
} from "./icons";
import {
  componentForStep,
  defaultStep,
  emptyDefinition,
  normalizeDefinition,
} from "./normalize";
import type {
  CatalogComponent,
  PickerCategory,
  StepLocation,
  WorkflowDefinition,
  WorkflowEndpoints,
  WorkflowRecord,
  WorkflowStep,
  WorkflowStudioBootstrap,
  WorkflowTemplate,
} from "./types";
import { StepInspector } from "./components/StepInspector";
import { StepPicker } from "./components/StepPicker";
import { WorkflowCanvas } from "./components/WorkflowCanvas";

type SaveState = "saved" | "unsaved" | "saving" | "error" | "conflict";

type Snapshot = {
  definition: WorkflowDefinition;
};

type PickerState = {
  open: boolean;
  category: PickerCategory;
  location: StepLocation;
  replaceStepId?: string;
};

type ApiPayload = Record<string, unknown> & {
  workflow?: Partial<WorkflowRecord> & {
    draft_definition?: WorkflowDefinition;
    test_state?: Record<string, unknown>;
  };
  definition?: WorkflowDefinition;
  draft_revision?: number;
  test_state?: Record<string, unknown>;
  endpoints?: WorkflowEndpoints;
  redirect_url?: string;
  url?: string;
  run?: {
    id?: string | number;
    status?: string;
    url?: string;
  };
};

type MergeWorkflowPayloadOptions = {
  acceptDefinition?: boolean;
  acceptTestState?: boolean;
  preserveLocalName?: boolean;
};

function endpointMethod(endpoint: string | undefined, fallback: "POST" | "PUT" | "PATCH" = "POST") {
  if (!endpoint) return fallback;
  return endpoint.includes("/draft") || endpoint.includes("/definition") ? "PUT" : fallback;
}

function readableError(error: unknown): string {
  if (error instanceof Error && error.message.trim() !== "") return error.message;
  return "Something went wrong. Your unsaved changes remain on this screen.";
}

function statusLabel(status: string): string {
  if (status === "active") return "On";
  if (status === "paused") return "Paused";
  return "Draft";
}

function stepPathDepth(definition: WorkflowDefinition, targetStepId: string): number {
  const visit = (steps: WorkflowStep[], depth: number): number => {
    for (const step of steps) {
      if (step.id === targetStepId) return depth;
      for (const branch of step.branches ?? []) {
        const found = visit(branch.steps, depth + (step.kind === "paths" ? 1 : 0));
        if (found >= 0) return found;
      }
    }
    return -1;
  };

  return visit(definition.steps, 1);
}

function pathsAllowedAt(
  definition: WorkflowDefinition,
  location: StepLocation,
  replaceStepId?: string,
): boolean {
  const terminal = replaceStepId
    ? isStepTerminal(definition, replaceStepId)
    : isTerminalLocation(definition, location);
  if (!terminal) return false;

  if (replaceStepId) {
    const depth = stepPathDepth(definition, replaceStepId);
    return depth < 0 || depth <= 3;
  }
  if (location.scope !== "branch") return true;
  return stepPathDepth(definition, location.parentStepId) < 3;
}

function allStepsHavePassedTests(
  definition: WorkflowDefinition,
  testState: Record<string, unknown>,
): boolean {
  const steps: WorkflowStep[] = [];
  if (definition.trigger) steps.push(definition.trigger);
  const collect = (items: WorkflowStep[]) => {
    items.forEach((step) => {
      steps.push(step);
      (step.branches ?? []).forEach((branch) => collect(branch.steps));
    });
  };
  collect(definition.steps);

  return steps.length > 0 && steps.every((step) => {
    const value = testState[step.id];
    if (!value || typeof value !== "object" || Array.isArray(value)) return false;
    const result = value as Record<string, unknown>;
    return result.ok === true || result.status === "passed" || result.status === "success";
  });
}

function payloadRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};
}

function initialStepId(definition: WorkflowDefinition): string | null {
  const requested = typeof window === "undefined"
    ? null
    : new URLSearchParams(window.location.search).get("step");
  if (requested && findStep(definition, requested)) return requested;
  return definition.trigger?.id ?? null;
}

export function StudioApp({ bootstrap }: { bootstrap: WorkflowStudioBootstrap }) {
  const [workflow, setWorkflow] = useState<WorkflowRecord>(bootstrap.workflow);
  const [definition, setDefinition] = useState<WorkflowDefinition>(bootstrap.definition);
  const [workflowName, setWorkflowName] = useState(bootstrap.workflow.name);
  const [endpoints, setEndpoints] = useState<WorkflowEndpoints>(bootstrap.endpoints);
  const [testState, setTestState] = useState<Record<string, unknown>>(bootstrap.test_state);
  const [selectedStepId, setSelectedStepId] = useState<string | null>(
    initialStepId(bootstrap.definition),
  );
  const [picker, setPicker] = useState<PickerState>({
    open: bootstrap.mode === "create" && !bootstrap.definition.trigger,
    category: bootstrap.initial_picker ?? "home",
    location: { scope: "trigger" },
  });
  const [saveState, setSaveState] = useState<SaveState>("saved");
  const [changeVersion, setChangeVersion] = useState(0);
  const [history, setHistory] = useState<{ past: Snapshot[]; future: Snapshot[] }>({ past: [], future: [] });
  const [busyAction, setBusyAction] = useState<"creating" | "testing" | "publish" | "test-run" | "status" | null>(null);
  const [announcement, setAnnouncement] = useState("");
  const [errorMessage, setErrorMessage] = useState("");
  const [testRunNotice, setTestRunNotice] = useState<{ message: string; url?: string } | null>(null);
  const [overflowOpen, setOverflowOpen] = useState(false);

  const definitionRef = useRef(definition);
  const workflowRef = useRef(workflow);
  const nameRef = useRef(workflowName);
  const endpointsRef = useRef(endpoints);
  const changeVersionRef = useRef(changeVersion);
  const savedVersionRef = useRef(0);
  const saveInFlightRef = useRef<Promise<boolean> | null>(null);
  const autosaveTimerRef = useRef<number | null>(null);
  const testSamplesRef = useRef<Record<string, unknown>>({});

  useEffect(() => { definitionRef.current = definition; }, [definition]);
  useEffect(() => { workflowRef.current = workflow; }, [workflow]);
  useEffect(() => { nameRef.current = workflowName; }, [workflowName]);
  useEffect(() => { endpointsRef.current = endpoints; }, [endpoints]);
  useEffect(() => { changeVersionRef.current = changeVersion; }, [changeVersion]);

  const components = bootstrap.catalog.components;
  const selectedStep = useMemo(
    () => selectedStepId ? findStep(definition, selectedStepId) : null,
    [definition, selectedStepId],
  );
  const selectedComponent = useMemo(
    () => componentForStep(components, selectedStep),
    [components, selectedStep],
  );
  const selectedConnections = selectedComponent
    ? (bootstrap.connections[selectedComponent.provider] ?? [])
    : [];
  const connectionsUrl = useMemo(() => {
    if (!endpoints.connections || typeof window === "undefined") return endpoints.connections;
    const url = new URL(endpoints.connections, window.location.origin);
    const returnPath = workflow.id
      ? `/workflows/${workflow.id}${selectedStepId ? `?step=${encodeURIComponent(selectedStepId)}` : ""}`
      : "/workflows/new";
    url.searchParams.set("return_path", returnPath);
    return `${url.pathname}${url.search}${url.hash}`;
  }, [endpoints.connections, selectedStepId, workflow.id]);
  const mappingOptions = useMemo(
    () => selectedStepId
      ? mappingOptionsForStep(definition, components, selectedStepId)
      : [],
    [components, definition, selectedStepId],
  );

  const markChanged = useCallback(() => {
    setChangeVersion((value) => {
      const next = value + 1;
      changeVersionRef.current = next;
      return next;
    });
    setSaveState((current) => current === "conflict" ? current : "unsaved");
  }, []);

  const commitDefinition = useCallback((nextDefinition: WorkflowDefinition, nextSelectedStepId?: string | null) => {
    setHistory((current) => ({
      past: [...current.past.slice(-49), { definition: cloneDefinition(definitionRef.current) }],
      future: [],
    }));
    setDefinition(nextDefinition);
    definitionRef.current = nextDefinition;
    if (nextSelectedStepId !== undefined) setSelectedStepId(nextSelectedStepId);
    setTestState({});
    testSamplesRef.current = {};
    markChanged();
  }, [markChanged]);

  const undo = useCallback(() => {
    setHistory((current) => {
      const previous = current.past[current.past.length - 1];
      if (!previous) return current;
      const currentSnapshot = { definition: cloneDefinition(definitionRef.current) };
      setDefinition(previous.definition);
      definitionRef.current = previous.definition;
      setSelectedStepId((selected) => selected && findStep(previous.definition, selected) ? selected : previous.definition.trigger?.id ?? null);
      setTestState({});
      testSamplesRef.current = {};
      markChanged();
      return {
        past: current.past.slice(0, -1),
        future: [currentSnapshot, ...current.future].slice(0, 50),
      };
    });
  }, [markChanged]);

  const redo = useCallback(() => {
    setHistory((current) => {
      const next = current.future[0];
      if (!next) return current;
      const currentSnapshot = { definition: cloneDefinition(definitionRef.current) };
      setDefinition(next.definition);
      definitionRef.current = next.definition;
      setSelectedStepId((selected) => selected && findStep(next.definition, selected) ? selected : next.definition.trigger?.id ?? null);
      setTestState({});
      testSamplesRef.current = {};
      markChanged();
      return {
        past: [...current.past, currentSnapshot].slice(-50),
        future: current.future.slice(1),
      };
    });
  }, [markChanged]);

  useEffect(() => {
    const handleKeyboard = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      const isEditable = target?.matches("input, textarea, select, [contenteditable='true']");

      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") {
        event.preventDefault();
        void saveDraft();
        return;
      }

      if (isEditable || !(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== "z") return;
      event.preventDefault();
      if (event.shiftKey) redo();
      else undo();
    };

    document.addEventListener("keydown", handleKeyboard);
    return () => document.removeEventListener("keydown", handleKeyboard);
  });

  const mergeWorkflowPayload = useCallback((
    payload: ApiPayload,
    options: MergeWorkflowPayloadOptions = {},
  ) => {
    const returnedWorkflow = payload.workflow ?? {};
    const nextId = returnedWorkflow.id ?? workflowRef.current.id;
    const nextRevision = Number(
      returnedWorkflow.draft_revision
      ?? payload.draft_revision
      ?? workflowRef.current.draft_revision,
    );
    const nextStatus = String(returnedWorkflow.status ?? workflowRef.current.status);
    const nextPublishedVersion = Number(
      returnedWorkflow.published_version
      ?? workflowRef.current.published_version
      ?? 0,
    ) || null;
    const nextWorkflow: WorkflowRecord = {
      ...workflowRef.current,
      ...returnedWorkflow,
      id: nextId ?? null,
      name: options.preserveLocalName
        ? nameRef.current
        : String(returnedWorkflow.name ?? nameRef.current),
      status: nextStatus,
      draft_revision: nextRevision,
      published_version: nextPublishedVersion,
    };
    setWorkflow(nextWorkflow);
    workflowRef.current = nextWorkflow;
    if (payload.endpoints) {
      const nextEndpoints = { ...endpointsRef.current, ...payload.endpoints };
      setEndpoints(nextEndpoints);
      endpointsRef.current = nextEndpoints;
    }
    if (options.acceptTestState !== false && payload.test_state) {
      setTestState(payload.test_state);
    }
    if (options.acceptDefinition !== false && (payload.definition || returnedWorkflow.draft_definition)) {
      const returnedDefinition = normalizeDefinition(
        payload.definition ?? returnedWorkflow.draft_definition,
      );
      setDefinition(returnedDefinition);
      definitionRef.current = returnedDefinition;
    }
    const nextUrl = payload.redirect_url ?? payload.url;
    if (typeof nextUrl === "string" && nextUrl && window.location.pathname !== new URL(nextUrl, window.location.origin).pathname) {
      window.history.replaceState({}, "", nextUrl);
    }
  }, []);

  const mergeOperationalPayload = useCallback((payload: ApiPayload) => {
    const returnedWorkflow = payload.workflow ?? {};
    const nextWorkflow: WorkflowRecord = {
      ...workflowRef.current,
      id: returnedWorkflow.id ?? workflowRef.current.id,
      status: String(returnedWorkflow.status ?? workflowRef.current.status),
      published_version: Number(
        returnedWorkflow.published_version
        ?? workflowRef.current.published_version
        ?? 0,
      ) || null,
    };
    setWorkflow(nextWorkflow);
    workflowRef.current = nextWorkflow;
    if (payload.endpoints) {
      const nextEndpoints = { ...endpointsRef.current, ...payload.endpoints };
      setEndpoints(nextEndpoints);
      endpointsRef.current = nextEndpoints;
    }
  }, []);

  const saveDraft = useCallback(async (): Promise<boolean> => {
    if (!workflowRef.current.id) return false;
    if (changeVersionRef.current <= savedVersionRef.current) return true;
    if (saveState === "conflict") return false;
    if (saveInFlightRef.current) {
      const inFlightSaved = await saveInFlightRef.current;
      if (!inFlightSaved) return false;
      if (changeVersionRef.current > savedVersionRef.current) return saveDraft();
      return true;
    }

    const endpoint = fillEndpoint(endpointsRef.current.save, {
      workflow: workflowRef.current.id,
    });
    if (!endpoint) {
      setSaveState("error");
      setErrorMessage("Draft saving is not available for this workflow.");
      return false;
    }

    const requestVersion = changeVersionRef.current;
    setSaveState("saving");
    setAnnouncement("Saving draft");

    const promise = (async () => {
      try {
        const payload = await workflowRequest<ApiPayload>(
          endpoint,
          bootstrap.csrf_token,
          {
            method: endpointMethod(endpoint, "PUT"),
            body: {
              name: nameRef.current.trim() || "Untitled workflow",
              definition: definitionForApi(definitionRef.current),
              draft_definition: definitionForApi(definitionRef.current),
              draft_revision: workflowRef.current.draft_revision,
            },
          },
        );
        const hasNewerChanges = changeVersionRef.current > requestVersion;
        mergeWorkflowPayload(payload, {
          acceptDefinition: !hasNewerChanges,
          acceptTestState: !hasNewerChanges,
          preserveLocalName: hasNewerChanges,
        });
        savedVersionRef.current = requestVersion;
        if (hasNewerChanges) {
          setSaveState("unsaved");
        } else {
          setSaveState("saved");
          setErrorMessage("");
          setAnnouncement("Draft saved");
        }
        return true;
      } catch (error) {
        if (error instanceof WorkflowApiError && error.status === 409) {
          setSaveState("conflict");
          setErrorMessage("A newer draft was saved elsewhere. Reload before making more changes.");
          setAnnouncement("Save conflict");
        } else {
          setSaveState("error");
          setErrorMessage(readableError(error));
          setAnnouncement("Draft could not be saved");
        }
        return false;
      } finally {
        saveInFlightRef.current = null;
      }
    })();

    saveInFlightRef.current = promise;
    return promise;
  }, [bootstrap.csrf_token, mergeWorkflowPayload, saveState]);

  const saveLatestDraft = useCallback(async (): Promise<boolean> => {
    let saved = await saveDraft();
    let attempts = 0;
    while (saved && changeVersionRef.current > savedVersionRef.current && attempts < 4) {
      attempts += 1;
      saved = await saveDraft();
    }
    if (saved && changeVersionRef.current > savedVersionRef.current) {
      setSaveState("unsaved");
      setErrorMessage("Finish editing, then try again so the latest draft can be saved first.");
      return false;
    }
    return saved;
  }, [saveDraft]);

  useEffect(() => {
    if (!workflow.id || changeVersion <= savedVersionRef.current || saveState === "conflict") return undefined;
    if (autosaveTimerRef.current) window.clearTimeout(autosaveTimerRef.current);
    autosaveTimerRef.current = window.setTimeout(() => {
      void saveDraft();
    }, 700);
    return () => {
      if (autosaveTimerRef.current) window.clearTimeout(autosaveTimerRef.current);
    };
  }, [changeVersion, saveDraft, saveState, workflow.id]);

  const createDraft = useCallback(async (
    nextDefinition: WorkflowDefinition,
    options: { templateKey?: string; selectedStepId?: string } = {},
  ) => {
    if (workflowRef.current.id || busyAction === "creating") return true;
    const endpoint = endpointsRef.current.create ?? "";
    if (!endpoint) {
      setSaveState("error");
      setErrorMessage("Workflow creation is not available.");
      return false;
    }
    setBusyAction("creating");
    setSaveState("saving");
    setAnnouncement("Creating workflow draft");
    const requestVersion = changeVersionRef.current;

    try {
      const payload = await workflowRequest<ApiPayload>(
        endpoint,
        bootstrap.csrf_token,
        {
          method: "POST",
          body: {
            name: nameRef.current.trim() || "Untitled workflow",
            template_key: options.templateKey ?? "blank",
            definition: definitionForApi(nextDefinition),
            draft_definition: definitionForApi(nextDefinition),
          },
        },
      );
      const hasNewerChanges = changeVersionRef.current > requestVersion;
      mergeWorkflowPayload(payload, {
        acceptDefinition: !hasNewerChanges,
        acceptTestState: !hasNewerChanges,
        preserveLocalName: hasNewerChanges,
      });
      const id = payload.workflow?.id ?? workflowRef.current.id;
      if (id) {
        const nextWorkflow = { ...workflowRef.current, id };
        setWorkflow(nextWorkflow);
        workflowRef.current = nextWorkflow;
      }
      if (!hasNewerChanges) {
        const returnedDefinition = normalizeDefinition(
          payload.definition ?? payload.workflow?.draft_definition ?? nextDefinition,
        );
        setDefinition(returnedDefinition);
        definitionRef.current = returnedDefinition;
      }
      setSelectedStepId(options.selectedStepId ?? nextDefinition.trigger?.id ?? null);
      savedVersionRef.current = requestVersion;
      setSaveState(hasNewerChanges ? "unsaved" : "saved");
      setAnnouncement(hasNewerChanges ? "Workflow created; newer edits are not saved yet" : "Workflow draft created");

      const showUrl = fillEndpoint(
        payload.endpoints?.show ?? endpointsRef.current.show,
        { workflow: payload.workflow?.id ?? workflowRef.current.id },
      );
      if (showUrl) window.history.replaceState({}, "", showUrl);
      return true;
    } catch (error) {
      setSaveState("error");
      setErrorMessage(readableError(error));
      setAnnouncement("Workflow could not be created");
      return false;
    } finally {
      setBusyAction(null);
    }
  }, [bootstrap.csrf_token, busyAction, mergeWorkflowPayload]);

  const openPicker = useCallback((
    location: StepLocation,
    category: PickerCategory = "home",
    replaceStepId?: string,
  ) => {
    if (countSteps(definitionRef.current) >= 100 && !replaceStepId) {
      setErrorMessage("This workflow has reached the 100-step limit.");
      return;
    }
    setPicker({ open: true, category, location, replaceStepId });
  }, []);

  const chooseComponent = useCallback(async (component: CatalogComponent) => {
    if (component.kind === "paths") {
      const canPlacePaths = pathsAllowedAt(
        definitionRef.current,
        picker.location,
        picker.replaceStepId,
      );
      if (!canPlacePaths) {
        setErrorMessage("Paths must be the final step in their workflow or branch.");
        setPicker((current) => ({ ...current, open: false }));
        return;
      }
    }

    let nextStep = defaultStep(component);
    let nextDefinition: WorkflowDefinition;

    if (picker.replaceStepId) {
      const current = findStep(definitionRef.current, picker.replaceStepId);
      if (!current) return;
      nextStep = { ...nextStep, id: current.id };
      nextDefinition = updateStep(definitionRef.current, current.id, () => nextStep);
    } else {
      nextDefinition = insertStep(definitionRef.current, picker.location, nextStep);
      if (!findStep(nextDefinition, nextStep.id)) {
        setErrorMessage("That step cannot be inserted here. Paths must remain terminal.");
        setPicker((current) => ({ ...current, open: false }));
        return;
      }
    }

    setPicker((current) => ({ ...current, open: false }));

    if (!workflowRef.current.id && picker.location.scope === "trigger") {
      setDefinition(nextDefinition);
      definitionRef.current = nextDefinition;
      setSelectedStepId(nextStep.id);
      await createDraft(nextDefinition, {
        templateKey: component.category || "blank",
        selectedStepId: nextStep.id,
      });
      return;
    }

    commitDefinition(nextDefinition, nextStep.id);
  }, [commitDefinition, createDraft, picker.location, picker.replaceStepId]);

  const chooseTemplate = useCallback(async (template: WorkflowTemplate) => {
    if (countSteps(definitionRef.current) > 0) {
      setPicker((current) => ({ ...current, open: false }));
      setErrorMessage("Templates are only available for an empty workflow. Delete the current steps or create a new workflow.");
      return;
    }

    const nextDefinition = template.definition ?? emptyDefinition();
    setPicker((current) => ({ ...current, open: false }));
    setWorkflowName(template.name);
    nameRef.current = template.name;
    if (!workflowRef.current.id) {
      setDefinition(nextDefinition);
      definitionRef.current = nextDefinition;
      await createDraft(nextDefinition, {
        templateKey: template.key,
        selectedStepId: nextDefinition.trigger?.id,
      });
    } else {
      commitDefinition(nextDefinition, nextDefinition.trigger?.id ?? null);
    }
  }, [commitDefinition, createDraft]);

  const updateSelectedStep = useCallback((step: WorkflowStep) => {
    const next = updateStep(definitionRef.current, step.id, () => step);
    commitDefinition(next, step.id);
  }, [commitDefinition]);

  const removeStep = useCallback((stepId: string) => {
    const next = deleteStep(definitionRef.current, stepId);
    commitDefinition(next, next.trigger?.id ?? null);
  }, [commitDefinition]);

  const testStep = useCallback(async (step: WorkflowStep) => {
    if (!workflowRef.current.id) return;
    const saved = await saveLatestDraft();
    if (!saved) return;
    const rawEndpoint = endpointsRef.current.test_step
      ?? endpointsRef.current.testStep
      ?? (step.kind === "trigger" ? endpointsRef.current.test_trigger : endpointsRef.current.test_action);
    const endpoint = fillEndpoint(rawEndpoint, {
      workflow: workflowRef.current.id,
      step: step.id,
    });
    setBusyAction("testing");
    setAnnouncement(`Testing ${componentForStep(components, step)?.label ?? "step"}`);
    setErrorMessage("");
    const testVersion = changeVersionRef.current;
    try {
      const payload = await workflowRequest<ApiPayload>(endpoint, bootstrap.csrf_token, {
        method: "POST",
        body: {
          step_id: step.id,
          draft_revision: workflowRef.current.draft_revision,
          sample: testSamplesRef.current,
        },
      });
      const hasNewerChanges = changeVersionRef.current > testVersion;
      mergeWorkflowPayload(payload, {
        acceptDefinition: !hasNewerChanges,
        acceptTestState: !hasNewerChanges,
        preserveLocalName: hasNewerChanges,
      });
      const result = payloadRecord(payload.result);
      if (!hasNewerChanges && step.kind === "trigger") {
        const triggerSample = payloadRecord(result.sample);
        testSamplesRef.current = {
          trigger: payloadRecord(triggerSample.payload),
          steps: {},
        };
      } else if (!hasNewerChanges) {
        const output = payloadRecord(result.output);
        if (Object.keys(output).length > 0) {
          testSamplesRef.current = {
            ...testSamplesRef.current,
            steps: {
              ...payloadRecord(testSamplesRef.current.steps),
              [step.id]: output,
            },
          };
        }
      }
      if (!hasNewerChanges) {
        const returnedState = payload.test_state ?? payloadRecord(payload.workflow?.test_state);
        if (Object.keys(returnedState).length > 0) setTestState(returnedState);
      }
      setAnnouncement(hasNewerChanges ? "Step test finished for the prior saved draft" : "Step test passed");
    } catch (error) {
      const message = readableError(error);
      setErrorMessage(message);
      if (changeVersionRef.current === testVersion) {
        setTestState((current) => ({
          ...current,
          [step.id]: { ok: false, status: "failed", message },
        }));
      }
      setAnnouncement("Step test failed");
    } finally {
      setBusyAction(null);
    }
  }, [bootstrap.csrf_token, components, mergeWorkflowPayload, saveLatestDraft]);

  const runTopAction = useCallback(async (action: "publish" | "test-run" | "status") => {
    if (!workflowRef.current.id) {
      setErrorMessage("Choose a trigger before running this action.");
      return;
    }
    if (action !== "status") {
      const saved = await saveLatestDraft();
      if (!saved) return;
    }

    let rawEndpoint: string | undefined;
    if (action === "publish") rawEndpoint = endpointsRef.current.publish;
    if (action === "test-run") rawEndpoint = endpointsRef.current.test_run ?? endpointsRef.current.testRun ?? endpointsRef.current.run;
    if (action === "status") rawEndpoint = workflowRef.current.status === "active" ? endpointsRef.current.pause : endpointsRef.current.resume;
    const endpoint = fillEndpoint(rawEndpoint, { workflow: workflowRef.current.id });
    setBusyAction(action);
    setErrorMessage("");
    if (action === "test-run") setTestRunNotice(null);
    const actionVersion = changeVersionRef.current;

    try {
      const payload = await workflowRequest<ApiPayload>(endpoint, bootstrap.csrf_token, {
        method: "POST",
        body: action === "test-run"
          ? {
              draft_revision: workflowRef.current.draft_revision,
              mode: "test",
              sample: testSamplesRef.current,
            }
          : { draft_revision: workflowRef.current.draft_revision },
      });
      const hasNewerChanges = changeVersionRef.current > actionVersion;
      if (action === "status") {
        mergeOperationalPayload(payload);
      } else {
        mergeWorkflowPayload(payload, {
          acceptDefinition: !hasNewerChanges,
          acceptTestState: !hasNewerChanges,
          preserveLocalName: hasNewerChanges,
        });
      }
      if (action === "publish") {
        const nextWorkflow = { ...workflowRef.current, status: String(payload.workflow?.status ?? "active") };
        setWorkflow(nextWorkflow);
        workflowRef.current = nextWorkflow;
        setAnnouncement(hasNewerChanges
          ? "Saved version published; newer edits remain in the draft"
          : "Workflow published and turned on");
      } else if (action === "test-run") {
        const runStatus = payload.run?.status;
        const completed = runStatus === "completed" || runStatus === "succeeded" || runStatus === "success";
        const failed = runStatus === "failed" || runStatus === "error";
        const message = completed
          ? "Test run completed successfully."
          : failed
            ? "Test run finished with errors. Open the details to review each step."
            : runStatus
              ? `Test run status: ${runStatus.replaceAll("_", " ")}.`
              : "Test run finished.";
        setTestRunNotice({
          message: hasNewerChanges
            ? `${message} It used the previously saved draft; newer edits remain in the editor.`
            : message,
          url: payload.run?.url ?? endpointsRef.current.history,
        });
        setAnnouncement(message);
      } else {
        const nextStatus = workflowRef.current.status === "active" ? "paused" : "active";
        const nextWorkflow = { ...workflowRef.current, status: String(payload.workflow?.status ?? nextStatus) };
        setWorkflow(nextWorkflow);
        workflowRef.current = nextWorkflow;
        setAnnouncement(nextWorkflow.status === "active" ? "Workflow turned on" : "Workflow paused");
      }
    } catch (error) {
      setErrorMessage(readableError(error));
      if (action === "test-run") {
        setTestRunNotice({
          message: "Test run failed. Open run history for step-by-step details.",
          url: endpointsRef.current.history,
        });
      }
      setAnnouncement(`${action === "publish" ? "Publish" : "Action"} failed`);
    } finally {
      setBusyAction(null);
    }
  }, [bootstrap.csrf_token, mergeOperationalPayload, mergeWorkflowPayload, saveLatestDraft]);

  const manageHeldItems = useCallback(async (action: "release" | "discard") => {
    if (!workflowRef.current.id) return;
    const prompt = action === "release"
      ? "Turn this workflow on and release its held items from their saved checkpoints?"
      : "Discard every held item for this workflow? Discarded customer communications will not be sent.";
    if (!window.confirm(prompt)) return;

    const rawEndpoint = action === "release"
      ? endpointsRef.current.resume
      : endpointsRef.current.discard_held;
    const endpoint = fillEndpoint(rawEndpoint, { workflow: workflowRef.current.id });
    if (!endpoint) {
      setErrorMessage("Held-item controls are not available for this workflow.");
      return;
    }

    setBusyAction("status");
    setOverflowOpen(false);
    setErrorMessage("");
    try {
      const payload = await workflowRequest<ApiPayload>(endpoint, bootstrap.csrf_token, {
        method: "POST",
        body: action === "release" ? { release_held_items: true } : {},
      });
      mergeOperationalPayload(payload);
      setAnnouncement(action === "release" ? "Held items released" : "Held items discarded");
    } catch (error) {
      setErrorMessage(readableError(error));
      setAnnouncement("Held-item action failed");
    } finally {
      setBusyAction(null);
    }
  }, [bootstrap.csrf_token, mergeOperationalPayload]);

  const closePicker = useCallback(() => {
    setPicker((current) => ({ ...current, open: false }));
  }, []);
  const changePickerCategory = useCallback((category: PickerCategory) => {
    setPicker((current) => ({ ...current, category }));
  }, []);

  useEffect(() => {
    const hasUnsavedWork = workflow.id
      && (changeVersion > savedVersionRef.current || saveState === "saving");
    if (!hasUnsavedWork) return undefined;
    const preventAccidentalExit = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", preventAccidentalExit);
    return () => window.removeEventListener("beforeunload", preventAccidentalExit);
  }, [changeVersion, saveState, workflow.id]);

  const allowPaths = pathsAllowedAt(definition, picker.location, picker.replaceStepId);
  const allowTemplates = !picker.replaceStepId && countSteps(definition) === 0;
  const allStepsTested = allStepsHavePassedTests(definition, testState);
  const allPathBranchesHaveActions = everyPathBranchHasAction(definition);
  const canPublish = Boolean(
    definition.trigger
    && hasAction(definition)
    && allPathBranchesHaveActions
    && allStepsTested
    && saveState !== "conflict",
  );
  const publishReason = saveState === "conflict"
    ? "Reload the newer draft before publishing."
    : !definition.trigger
      ? "Add a trigger before publishing."
      : !hasAction(definition)
        ? "Add at least one action before publishing."
        : !allPathBranchesHaveActions
          ? "Add at least one action to every path branch before publishing."
        : !allStepsTested
          ? "Test every step after the latest draft change before publishing."
          : "";
  const saveCopy = {
    saved: "Draft saved",
    unsaved: "Unsaved changes",
    saving: "Saving…",
    error: "Save failed",
    conflict: "Newer draft found",
  }[saveState];
  const backUrl = endpoints.index || "/workflows";

  return (
    <div className="eb-workflow-studio">
      <header className="eb-studio-header">
        <a href={backUrl} className="eb-studio-header__back" aria-label="Back to workflows">
          <ArrowLeftIcon />
        </a>
        <div className="eb-studio-header__identity">
          <input
            aria-label="Workflow name"
            value={workflowName}
            maxLength={160}
            onChange={(event) => {
              setWorkflowName(event.target.value);
              nameRef.current = event.target.value;
              setTestState({});
              testSamplesRef.current = {};
              markChanged();
            }}
            onBlur={() => {
              if (workflowName.trim() === "") {
                setWorkflowName("Untitled workflow");
                nameRef.current = "Untitled workflow";
              }
            }}
          />
          <div>
            <span className={`eb-save-state is-${saveState}`}>
              {saveState === "saved" && <CheckIcon size={12} />}
              {saveCopy}
            </span>
            <span aria-hidden="true">·</span>
            <span>{workflow.published_version ? `Version ${workflow.published_version}` : "Not published"}</span>
          </div>
        </div>

        <div className="eb-studio-header__history" aria-label="Edit history">
          <button type="button" className="eb-icon-button" disabled={history.past.length === 0} onClick={undo} aria-label="Undo">
            <UndoIcon />
          </button>
          <button type="button" className="eb-icon-button" disabled={history.future.length === 0} onClick={redo} aria-label="Redo">
            <RedoIcon />
          </button>
        </div>

        <div className="eb-studio-header__actions">
          {workflow.id && workflow.published_version ? (
            <button
              type="button"
              className={`eb-status-button is-${workflow.status}`}
              disabled={busyAction !== null}
              onClick={() => void runTopAction("status")}
            >
              <span />
              {statusLabel(workflow.status)}
            </button>
          ) : (
            <span className="eb-draft-badge">Draft</span>
          )}
          <button
            type="button"
            className="eb-secondary-button"
            disabled={!definition.trigger || busyAction !== null}
            onClick={() => void runTopAction("test-run")}
          >
            {busyAction === "test-run" ? "Running…" : "Test run"}
          </button>
          <button
            type="button"
            className="eb-primary-button"
            disabled={busyAction !== null}
            aria-disabled={!canPublish || busyAction !== null}
            aria-describedby={!canPublish ? "eb-publish-explanation" : undefined}
            onClick={() => {
              if (!canPublish) {
                setErrorMessage(publishReason);
                setAnnouncement(`Cannot publish. ${publishReason}`);
                return;
              }
              void runTopAction("publish");
            }}
          >
            {busyAction === "publish" ? "Publishing…" : "Publish"}
          </button>
          {!canPublish && (
            <span className="sr-only" id="eb-publish-explanation">{publishReason}</span>
          )}
          <div className="eb-overflow-menu">
            <button
              type="button"
              className="eb-icon-button"
              aria-label="More workflow actions"
              aria-expanded={overflowOpen}
              onClick={() => setOverflowOpen((value) => !value)}
            >
              <DotsIcon />
            </button>
            {overflowOpen && (
              <div className="eb-overflow-menu__popover">
                {endpoints.history && <a href={endpoints.history}>Run history</a>}
                {connectionsUrl && <a href={connectionsUrl}>Manage connections</a>}
                {workflow.published_version && (
                  <button type="button" onClick={() => void manageHeldItems("release")}>
                    Resume and release held items
                  </button>
                )}
                {workflow.published_version && endpoints.discard_held && (
                  <button type="button" onClick={() => void manageHeldItems("discard")}>
                    Discard held items
                  </button>
                )}
                <button type="button" onClick={() => {
                  setOverflowOpen(false);
                  void saveDraft();
                }}>Save now</button>
              </div>
            )}
          </div>
        </div>
      </header>

      <div className="eb-studio-messages">
        {errorMessage && (
          <div className={`eb-studio-alert ${saveState === "conflict" ? "is-conflict" : ""}`} role="alert">
            <span>
              <strong>{saveState === "conflict" ? "This draft changed somewhere else." : "Workflow needs attention."}</strong>
              {errorMessage}
            </span>
            {saveState === "conflict" ? (
              <button type="button" onClick={() => window.location.reload()}>Reload latest draft</button>
            ) : (
              <button type="button" onClick={() => setErrorMessage("")}>Dismiss</button>
            )}
          </div>
        )}

        {testRunNotice && (
          <div className="eb-studio-notice" role="status">
            <span>
              <strong>Test run result</strong>
              {testRunNotice.message}
            </span>
            <span className="eb-studio-notice__actions">
              {testRunNotice.url && <a href={testRunNotice.url}>View run details</a>}
              <button type="button" onClick={() => setTestRunNotice(null)}>Dismiss</button>
            </span>
          </div>
        )}
      </div>

      <div className={`eb-studio-workspace ${selectedStep ? "has-inspector" : ""}`}>
        <WorkflowCanvas
          definition={definition}
          components={components}
          selectedStepId={selectedStepId}
          testState={testState}
          onSelectStep={setSelectedStepId}
          onAddStep={(location) => openPicker(location, "home")}
          onMoveStep={(stepId, direction) => {
            const current = definitionRef.current;
            const next = moveStep(current, stepId, direction);
            if (next !== current) commitDefinition(next, stepId);
          }}
          onReorderStep={(draggedId, targetId) => {
            const current = definitionRef.current;
            const next = reorderStep(current, draggedId, targetId);
            if (next !== current) commitDefinition(next, draggedId);
          }}
        />

        <StepInspector
          step={selectedStep}
          component={selectedComponent}
          connections={selectedConnections}
          connectionsUrl={connectionsUrl}
          testState={testState}
          mappingOptions={mappingOptions}
          busyTest={busyAction === "testing"}
          onUpdate={updateSelectedStep}
          onDelete={removeStep}
          onChangeComponent={() => {
            if (!selectedStep) return;
            openPicker(
              selectedStep.kind === "trigger" ? { scope: "trigger" } : { scope: "root", index: 0 },
              selectedStep.kind === "filter" || selectedStep.kind === "delay" || selectedStep.kind === "paths" ? "controls" : "apps",
              selectedStep.id,
            );
          }}
          onTest={(step) => void testStep(step)}
          onClose={() => setSelectedStepId(null)}
        />
      </div>

      <StepPicker
        open={picker.open}
        category={picker.category}
        components={components}
        templates={bootstrap.templates}
        triggerOnly={picker.location.scope === "trigger"}
        allowPaths={allowPaths}
        allowTemplates={allowTemplates}
        onCategoryChange={changePickerCategory}
        onChooseComponent={(component) => void chooseComponent(component)}
        onChooseTemplate={(template) => void chooseTemplate(template)}
        onClose={closePicker}
      />

      <div className="sr-only" aria-live="polite" aria-atomic="true">{announcement}</div>
    </div>
  );
}
