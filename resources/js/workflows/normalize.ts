import type {
  CatalogComponent,
  CatalogField,
  WorkflowBranch,
  WorkflowCondition,
  WorkflowDefinition,
  WorkflowStep,
  WorkflowStepKind,
  WorkflowStudioBootstrap,
  WorkflowTemplate,
} from "./types";

const supportedKinds: WorkflowStepKind[] = ["trigger", "action", "filter", "delay", "paths", "utility"];
const crockfordBase32 = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";

export function newId(_prefix = "step"): string {
  let timestamp = Date.now();
  let timePart = "";
  for (let index = 0; index < 10; index += 1) {
    timePart = crockfordBase32[timestamp % 32] + timePart;
    timestamp = Math.floor(timestamp / 32);
  }

  const randomBytes = new Uint8Array(16);
  if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
    crypto.getRandomValues(randomBytes);
  } else {
    for (let index = 0; index < randomBytes.length; index += 1) {
      randomBytes[index] = Math.floor(Math.random() * 256);
    }
  }

  return timePart + Array.from(randomBytes, (value) => crockfordBase32[value & 31]).join("");
}

function stringValue(value: unknown, fallback = ""): string {
  return typeof value === "string" && value.trim() !== "" ? value : fallback;
}

function booleanValue(value: unknown, fallback = false): boolean {
  if (typeof value === "boolean") {
    return value;
  }
  if (value === "live" || value === "available" || value === 1 || value === "1") {
    return true;
  }
  if (value === "disabled" || value === "unavailable" || value === 0 || value === "0") {
    return false;
  }
  return fallback;
}

function recordValue(value: unknown): Record<string, unknown> {
  return value && typeof value === "object" && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}

function arrayValue(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

function normalizeKind(value: unknown, key: string): WorkflowStepKind {
  const candidate = stringValue(value).toLowerCase() as WorkflowStepKind;
  if (supportedKinds.includes(candidate)) {
    return candidate;
  }
  if (key.includes("filter")) return "filter";
  if (key.includes("delay")) return "delay";
  if (key.includes("path")) return "paths";
  return "action";
}

function normalizeField(rawValue: unknown): CatalogField | null {
  const raw = recordValue(rawValue);
  const key = stringValue(raw.key ?? raw.name);
  if (!key) {
    return null;
  }

  const rawType = stringValue(raw.type, "text");
  const typeValue = ({
    integer: "number",
    mapped_value: "mapping",
    string: "text",
    condition_list: "text",
    path_list: "text",
  }[rawType] ?? rawType) as CatalogField["type"];
  const options = arrayValue(raw.options)
    .map((option) => {
      if (typeof option === "string") {
        return { value: option, label: option };
      }
      const optionRecord = recordValue(option);
      const value = stringValue(optionRecord.value ?? optionRecord.id);
      return value ? { value, label: stringValue(optionRecord.label ?? optionRecord.name, value) } : null;
    })
    .filter((option): option is { value: string; label: string } => Boolean(option));

  return {
    key,
    label: stringValue(raw.label, key.replaceAll("_", " ")),
    type: ["text", "textarea", "number", "select", "boolean", "mapping", "date", "datetime", "object", "array"].includes(typeValue ?? "")
      ? typeValue
      : "text",
    required: booleanValue(raw.required),
    help: stringValue(raw.help ?? raw.description),
    placeholder: stringValue(raw.placeholder),
    min: typeof raw.min === "number" ? raw.min : undefined,
    max: typeof raw.max === "number" ? raw.max : undefined,
    options,
    default: raw.default,
  };
}

export function normalizeComponent(rawValue: unknown, fallbackKey = ""): CatalogComponent | null {
  const raw = recordValue(rawValue);
  const key = stringValue(raw.component_key ?? raw.key ?? raw.id, fallbackKey);
  if (!key) {
    return null;
  }

  const kind = normalizeKind(raw.kind ?? raw.type, key);
  const provider = stringValue(raw.provider ?? raw.app_key ?? raw.app, kind === "filter" || kind === "delay" || kind === "paths" ? "everbranch" : "everbranch");
  const schema = recordValue(raw.config_schema ?? raw.schema);
  // The bootstrap layer may enrich config_fields with tenant-specific choices
  // (projects, calendars, and similar resources). Prefer those resolved fields
  // over the registry's generic schema so the inspector shows real options.
  const rawFields = arrayValue(raw.config_fields ?? schema.fields ?? raw.fields);
  const fields = rawFields
    .map(normalizeField)
    .filter((field): field is CatalogField => Boolean(field));
  const inputFields = arrayValue(recordValue(raw.input_schema).fields ?? raw.input_fields)
    .map(normalizeField)
    .filter((field): field is CatalogField => Boolean(field));
  const outputFields = arrayValue(recordValue(raw.output_schema).fields ?? raw.output_fields)
    .map(normalizeField)
    .filter((field): field is CatalogField => Boolean(field));
  const availability = raw.available ?? raw.enabled ?? raw.state;

  return {
    key,
    label: stringValue(raw.label ?? raw.name ?? raw.event_label, key.replaceAll(/[._]/g, " ")),
    description: stringValue(raw.description ?? raw.help, kind === "trigger" ? "Starts the workflow when this event happens." : "Runs this step with data from earlier steps."),
    kind,
    provider,
    provider_label: stringValue(raw.provider_label ?? raw.app_label ?? raw.app_name, provider.replaceAll("_", " ")),
    available: availability === undefined ? true : booleanValue(availability, false),
    config_fields: fields,
    input_fields: inputFields,
    output_fields: outputFields,
    connection_required: booleanValue(raw.connection_required ?? raw.requires_connection, !["everbranch", "core"].includes(provider)),
    test_policy: stringValue(raw.test_policy),
    category: stringValue(raw.category),
  };
}

function componentsFromCatalog(rawCatalog: unknown): CatalogComponent[] {
  const catalog = recordValue(rawCatalog);
  const collected: CatalogComponent[] = [];

  const add = (value: unknown, fallbackKey = "") => {
    const component = normalizeComponent(value, fallbackKey);
    if (component && component.available && !collected.some((item) => item.key === component.key)) {
      collected.push(component);
    }
  };

  if (Array.isArray(catalog.components)) {
    catalog.components.forEach((component) => add(component));
  } else {
    Object.entries(recordValue(catalog.components)).forEach(([key, component]) => add(component, key));
  }

  (["triggers", "actions", "controls", "utilities"] as const).forEach((group) => {
    const values = catalog[group];
    if (Array.isArray(values)) {
      values.forEach((component) => add(component));
    } else {
      Object.entries(recordValue(values)).forEach(([key, component]) => add(component, key));
    }
  });

  return collected;
}

function fallbackComponents(raw: Record<string, unknown>): CatalogComponent[] {
  const providers = recordValue(raw.providers);
  const templates = recordValue(raw.templates);
  const components: CatalogComponent[] = [];

  Object.entries(templates).forEach(([templateKey, templateValue]) => {
    const template = recordValue(templateValue);
    if (!booleanValue(template.launchable, false)) {
      return;
    }
    const triggerProvider = stringValue(template.trigger_provider);
    const actionProvider = stringValue(template.action_provider);
    const triggerMeta = recordValue(providers[triggerProvider]);
    const actionMeta = recordValue(providers[actionProvider]);

    if (triggerProvider && !components.some((item) => item.provider === triggerProvider && item.kind === "trigger")) {
      components.push({
        key: stringValue(template.trigger_component_key, `${triggerProvider}.trigger`),
        label: stringValue(template.trigger_event, "New or updated item"),
        description: stringValue(template.description, "Starts the workflow when this event happens."),
        kind: "trigger",
        provider: triggerProvider,
        provider_label: stringValue(triggerMeta.label, triggerProvider.replaceAll("_", " ")),
        available: true,
        config_fields: triggerProvider === "asana"
          ? [{ key: "project_gid", label: "Project", type: "select", required: true, options: arrayValue(recordValue(raw.asana_connection).projects).map((value) => {
              const project = recordValue(value);
              return { value: stringValue(project.gid), label: stringValue(project.name, "Asana project") };
            }) }]
          : [],
        input_fields: [],
        output_fields: [],
        connection_required: true,
        category: templateKey,
      });
    }

    if (actionProvider && !components.some((item) => item.provider === actionProvider && item.kind === "action")) {
      components.push({
        key: stringValue(template.action_component_key, `${actionProvider}.action`),
        label: stringValue(template.action_event, "Create or update item"),
        description: "Runs this action with data from earlier steps.",
        kind: "action",
        provider: actionProvider,
        provider_label: stringValue(actionMeta.label, actionProvider.replaceAll("_", " ")),
        available: true,
        config_fields: actionProvider === "google_calendar"
          ? [{ key: "calendar_id", label: "Calendar", type: "select", required: true, options: arrayValue(recordValue(raw.google_connection).calendars).map((value) => {
              const calendar = recordValue(value);
              return { value: stringValue(calendar.id), label: stringValue(calendar.summary, "Calendar") };
            }) }]
          : [],
        input_fields: [],
        output_fields: [],
        connection_required: true,
        category: templateKey,
      });
    }
  });

  return components;
}

function normalizeCondition(rawValue: unknown): WorkflowCondition {
  const raw = recordValue(rawValue);
  const rawField = recordValue(raw.field);
  return {
    id: stringValue(raw.id, newId("condition")),
    field: stringValue(raw.field, rawField.type === "mapping" ? stringValue(rawField.path) : ""),
    operator: stringValue(raw.operator, "equals"),
    value: raw.value,
  };
}

function normalizeBranch(rawValue: unknown, depth: number): WorkflowBranch {
  const raw = recordValue(rawValue);
  const typeValue = stringValue(raw.rule_type ?? raw.type, "custom");
  const condition = recordValue(raw.condition);
  return {
    id: stringValue(raw.id, newId("branch")),
    name: stringValue(raw.name, typeValue === "fallback" ? "Fallback" : "Path"),
    type: ["custom", "always", "fallback"].includes(typeValue) ? typeValue as WorkflowBranch["type"] : "custom",
    logic: (condition.logic ?? raw.logic) === "or" ? "or" : "and",
    conditions: arrayValue(condition.conditions ?? raw.conditions).map(normalizeCondition),
    steps: arrayValue(raw.steps).map((step) => normalizeStep(step, depth + 1)),
  };
}

export function normalizeStep(rawValue: unknown, depth = 0): WorkflowStep {
  const raw = recordValue(rawValue);
  const componentKey = stringValue(raw.component_key ?? raw.component ?? raw.key, stringValue(raw.provider, "everbranch") + "." + stringValue(raw.event, "action"));
  const kind = normalizeKind(raw.kind ?? raw.type, componentKey);

  return {
    id: stringValue(raw.id, newId(kind)),
    kind,
    component_key: componentKey,
    connection_id: raw.connection_id as string | number | null | undefined,
    config: recordValue(raw.config),
    branches: kind === "paths" && depth < 3
      ? arrayValue(raw.branches ?? recordValue(raw.config).branches).map((branch) => normalizeBranch(branch, depth))
      : undefined,
  };
}

export function emptyDefinition(): WorkflowDefinition {
  return {
    schema_version: 2,
    trigger: null,
    steps: [],
    settings: {
      poll_interval_minutes: 10,
      max_items_per_poll: 100,
    },
  };
}

export function normalizeDefinition(rawValue: unknown): WorkflowDefinition {
  const raw = recordValue(rawValue);
  const definition = emptyDefinition();

  if (Number(raw.schema_version) === 2 || Array.isArray(raw.steps)) {
    definition.trigger = raw.trigger ? normalizeStep({ ...recordValue(raw.trigger), kind: "trigger" }) : null;
    definition.steps = arrayValue(raw.steps).map((step) => normalizeStep(step));
    const settings = recordValue(raw.settings);
    definition.settings = {
      poll_interval_minutes: Math.max(1, Number(settings.poll_interval_minutes ?? 10)),
      max_items_per_poll: Math.max(1, Number(settings.max_items_per_poll ?? 100)),
    };
    return definition;
  }

  if (raw.trigger) {
    const legacyTrigger = recordValue(raw.trigger);
    definition.trigger = normalizeStep({
      id: newId("trigger"),
      kind: "trigger",
      component_key: stringValue(legacyTrigger.component_key, `${stringValue(legacyTrigger.provider, "asana")}.trigger`),
      connection_id: legacyTrigger.connection_id,
      config: legacyTrigger,
    });
  }

  if (raw.action) {
    const legacyAction = recordValue(raw.action);
    definition.steps.push(normalizeStep({
      id: newId("action"),
      kind: "action",
      component_key: stringValue(legacyAction.component_key, `${stringValue(legacyAction.provider, "google_calendar")}.action`),
      connection_id: legacyAction.connection_id,
      config: legacyAction,
    }));
  }

  return definition;
}

function normalizeTemplates(rawTemplates: unknown): WorkflowTemplate[] {
  const collected: WorkflowTemplate[] = [];
  const entries = Array.isArray(rawTemplates)
    ? rawTemplates.map((item, index) => [String(index), item] as const)
    : Object.entries(recordValue(rawTemplates));

  entries.forEach(([fallbackKey, value]) => {
    const raw = recordValue(value);
    const key = stringValue(raw.key, fallbackKey);
    const available = raw.available === undefined
      ? booleanValue(raw.launchable, true)
      : booleanValue(raw.available, false);
    if (!available) {
      return;
    }
    collected.push({
      key,
      name: stringValue(raw.name, key.replaceAll("_", " ")),
      description: stringValue(raw.description),
      available,
      trigger_provider: stringValue(raw.trigger_provider),
      action_provider: stringValue(raw.action_provider),
      definition: raw.definition ? normalizeDefinition(raw.definition) : undefined,
    });
  });

  return collected;
}

function normalizeConnections(rawConnections: unknown): WorkflowStudioBootstrap["connections"] {
  const result: WorkflowStudioBootstrap["connections"] = {};
  const raw = recordValue(rawConnections);

  Object.entries(raw).forEach(([provider, value]) => {
    const items = Array.isArray(value) ? value : [value];
    result[provider] = items
      .map((item) => {
        const connection = recordValue(item);
        const id = connection.id as string | number | undefined;
        if (id === undefined || id === null || id === "") {
          return null;
        }
        return {
          id,
          provider,
          label: stringValue(connection.label ?? connection.external_account_label ?? connection.account_label, `${provider.replaceAll("_", " ")} account`),
          status: stringValue(connection.status, "connected"),
        };
      })
      .filter((item): item is NonNullable<typeof item> => Boolean(item));
  });

  return result;
}

export function normalizeBootstrap(rawValue: unknown): WorkflowStudioBootstrap {
  const raw = recordValue(rawValue);
  const workflowRaw = recordValue(raw.workflow);
  let components = componentsFromCatalog(raw.catalog ?? raw.component_catalog);
  if (components.length === 0) {
    components = fallbackComponents(raw);
  }

  return {
    mode: raw.mode === "edit" || workflowRaw.id ? "edit" : "create",
    csrf_token: stringValue(raw.csrf_token ?? raw.csrfToken),
    workflow: {
      id: (workflowRaw.id as string | number | null | undefined) ?? null,
      name: stringValue(workflowRaw.name, "Untitled workflow"),
      status: stringValue(workflowRaw.status, "draft"),
      draft_revision: Number(workflowRaw.draft_revision ?? raw.draft_revision ?? 0),
      published_version: Number(workflowRaw.published_version ?? workflowRaw.publishedVersion ?? 0) || null,
    },
    definition: normalizeDefinition(raw.definition ?? workflowRaw.draft_definition),
    catalog: { components },
    templates: normalizeTemplates(raw.templates),
    connections: normalizeConnections(raw.connections),
    test_state: recordValue(raw.test_state ?? workflowRaw.test_state),
    endpoints: recordValue(raw.endpoints),
    initial_picker: ["home", "apps", "controls", "utilities", "templates"].includes(stringValue(raw.initial_picker))
      ? stringValue(raw.initial_picker) as WorkflowStudioBootstrap["initial_picker"]
      : "home",
  };
}

export function componentForStep(
  components: CatalogComponent[],
  step: WorkflowStep | null | undefined,
): CatalogComponent | null {
  if (!step) {
    return null;
  }

  const exact = components.find((component) => component.key === step.component_key);
  if (exact) {
    return exact;
  }

  const provider = step.component_key.split(".")[0];
  return components.find((component) => component.provider === provider && component.kind === step.kind) ?? null;
}

export function defaultStep(component: CatalogComponent): WorkflowStep {
  const config = component.config_fields.reduce<Record<string, unknown>>((values, field) => {
    if (field.default !== undefined) {
      values[field.key] = field.type === "mapping"
        ? { type: "literal", value: field.default }
        : field.default;
    } else if (field.type === "mapping") {
      values[field.key] = { type: "literal", value: "" };
    } else if (field.type === "boolean") {
      values[field.key] = false;
    }
    return values;
  }, {});

  if (component.kind === "filter") {
    config.logic = "and";
    config.conditions = [{ id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } }];
  }

  if (component.kind === "delay") {
    if (component.key.includes("until")) {
      config.datetime = { type: "literal", value: "" };
      config.past_date_behavior = "continue_if_within_1_day";
    } else {
      config.duration = { type: "literal", value: 1 };
      config.unit = "hours";
    }
  }

  const step: WorkflowStep = {
    id: newId(component.kind),
    kind: component.kind,
    component_key: component.key,
    connection_id: null,
    config,
  };

  if (component.kind === "paths") {
    step.branches = [
      {
        id: newId("branch"),
        name: "Path A",
        type: "custom",
        logic: "and",
        conditions: [{ id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } }],
        steps: [],
      },
      {
        id: newId("branch"),
        name: "Fallback",
        type: "fallback",
        logic: "and",
        conditions: [],
        steps: [],
      },
    ];
    config.branches = step.branches;
  }

  return step;
}
