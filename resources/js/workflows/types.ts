export type WorkflowStepKind = "trigger" | "action" | "filter" | "delay" | "paths" | "utility";

export type WorkflowCondition = {
  id: string;
  field: string;
  operator: string;
  value?: unknown;
};

export type WorkflowBranch = {
  id: string;
  name: string;
  type: "custom" | "always" | "fallback";
  logic?: "and" | "or";
  conditions?: WorkflowCondition[];
  steps: WorkflowStep[];
};

export type WorkflowStep = {
  id: string;
  kind: WorkflowStepKind;
  component_key: string;
  connection_id?: string | number | null;
  config: Record<string, unknown>;
  branches?: WorkflowBranch[];
};

export type WorkflowDefinition = {
  schema_version: 2;
  trigger: WorkflowStep | null;
  steps: WorkflowStep[];
  settings: {
    poll_interval_minutes: number;
    max_items_per_poll: number;
  };
};

export type CatalogOption = {
  value: string;
  label: string;
};

export type CatalogField = {
  key: string;
  label: string;
  type?: "text" | "textarea" | "number" | "select" | "boolean" | "mapping" | "date" | "datetime" | "object" | "array";
  required?: boolean;
  help?: string;
  placeholder?: string;
  min?: number;
  max?: number;
  options?: CatalogOption[];
  default?: unknown;
};

export type CatalogComponent = {
  key: string;
  label: string;
  description: string;
  kind: WorkflowStepKind;
  provider: string;
  provider_label: string;
  available: boolean;
  config_fields: CatalogField[];
  input_fields: CatalogField[];
  output_fields: CatalogField[];
  connection_required: boolean;
  test_policy?: string;
  category?: string;
};

export type MappingOption = {
  path: string;
  label: string;
  source: string;
  type?: CatalogField["type"];
};

export type WorkflowConnection = {
  id: string | number;
  provider: string;
  label: string;
  status?: string;
};

export type WorkflowTemplate = {
  key: string;
  name: string;
  description: string;
  available: boolean;
  trigger_provider?: string;
  action_provider?: string;
  definition?: WorkflowDefinition;
};

export type WorkflowRecord = {
  id: string | number | null;
  name: string;
  status: "draft" | "active" | "paused" | string;
  draft_revision: number;
  published_version?: number | null;
};

export type WorkflowEndpoints = {
  index?: string;
  create?: string;
  save?: string;
  load?: string;
  test_step?: string;
  testStep?: string;
  test_trigger?: string;
  test_action?: string;
  test_run?: string;
  testRun?: string;
  publish?: string;
  pause?: string;
  resume?: string;
  discard_held?: string;
  run?: string;
  connections?: string;
  history?: string;
  show?: string;
};

export type WorkflowStudioBootstrap = {
  mode: "create" | "edit";
  csrf_token: string;
  workflow: WorkflowRecord;
  definition: WorkflowDefinition;
  catalog: {
    components: CatalogComponent[];
  };
  templates: WorkflowTemplate[];
  connections: Record<string, WorkflowConnection[]>;
  test_state: Record<string, unknown>;
  endpoints: WorkflowEndpoints;
  initial_picker?: "home" | "apps" | "controls" | "utilities" | "templates";
};

export type StepLocation =
  | { scope: "trigger" }
  | { scope: "root"; index: number }
  | { scope: "branch"; parentStepId: string; branchId: string; index: number };

export type PickerCategory = "home" | "apps" | "controls" | "utilities" | "templates";
