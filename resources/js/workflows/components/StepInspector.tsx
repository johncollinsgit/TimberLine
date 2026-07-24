import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  CheckIcon,
  ChevronRightIcon,
  CloseIcon,
  PlusIcon,
  TrashIcon,
  WorkflowIcon,
} from "../icons";
import { newId } from "../normalize";
import type {
  CatalogComponent,
  CatalogField,
  MappingOption,
  WorkflowBranch,
  WorkflowCondition,
  WorkflowConnection,
  WorkflowStep,
} from "../types";
import { ProviderMark } from "./ProviderMark";

type InspectorTab = "event" | "account" | "configure" | "test";

type StepInspectorProps = {
  step: WorkflowStep | null;
  component: CatalogComponent | null;
  connections: WorkflowConnection[];
  connectionsUrl?: string;
  testState: Record<string, unknown>;
  mappingOptions: MappingOption[];
  busyTest: boolean;
  onUpdate: (step: WorkflowStep) => void;
  onDelete: (stepId: string) => void;
  onChangeComponent: () => void;
  onTest: (step: WorkflowStep) => void;
  onClose: () => void;
};

const conditionOperators = [
  ["equals", "Exactly matches"],
  ["not_equals", "Does not match"],
  ["contains", "Contains"],
  ["not_contains", "Does not contain"],
  ["starts_with", "Starts with"],
  ["does_not_start_with", "Does not start with"],
  ["ends_with", "Ends with"],
  ["does_not_end_with", "Does not end with"],
  ["is_in", "Is in list"],
  ["is_not_in", "Is not in list"],
  ["number_equals", "Number equals"],
  ["greater_than", "Is greater than"],
  ["greater_than_or_equal", "Is at least"],
  ["less_than", "Is less than"],
  ["less_than_or_equal", "Is at most"],
  ["after", "Is after"],
  ["before", "Is before"],
  ["date_equals", "Is the same date"],
  ["is_true", "Is true"],
  ["is_false", "Is false"],
  ["exists", "Exists"],
  ["not_exists", "Does not exist"],
  ["is_empty", "Is empty"],
  ["is_not_empty", "Is not empty"],
  ["contains_any", "Contains any item"],
  ["contains_all", "Contains every item"],
];
const inspectorTabs: InspectorTab[] = ["event", "account", "configure", "test"];

function textValue(value: unknown): string {
  if (value === null || value === undefined) return "";
  if (typeof value === "string") return value;
  if (typeof value === "number" || typeof value === "boolean") return String(value);
  return "";
}

function summaryText(value: unknown, depth = 0): string {
  const scalar = textValue(value);
  if (scalar !== "") return scalar;
  if (value === null || value === undefined || depth > 2) return "";
  if (Array.isArray(value)) {
    return value
      .slice(0, 4)
      .map((item) => summaryText(item, depth + 1))
      .filter(Boolean)
      .join(" · ");
  }
  if (typeof value === "object") {
    return Object.entries(value as Record<string, unknown>)
      .slice(0, 6)
      .map(([key, item]) => {
        const copy = summaryText(item, depth + 1);
        if (!copy) return "";
        const label = key.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
        return `${label}: ${copy}`;
      })
      .filter(Boolean)
      .join(" · ");
  }
  return "";
}

function useNarrowInspector(): boolean {
  const [narrow, setNarrow] = useState(
    () => typeof window !== "undefined" && window.matchMedia("(max-width: 900px)").matches,
  );

  useEffect(() => {
    const media = window.matchMedia("(max-width: 900px)");
    const update = () => setNarrow(media.matches);
    update();
    media.addEventListener?.("change", update);
    return () => media.removeEventListener?.("change", update);
  }, []);

  return narrow;
}

function mappedValueParts(value: unknown): { mode: "literal" | "mapping"; value: string } {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    const mapped = value as Record<string, unknown>;
    if (mapped.type === "mapping") {
      return { mode: "mapping", value: textValue(mapped.path) };
    }
    if (mapped.type === "literal") {
      return { mode: "literal", value: textValue(mapped.value) };
    }
  }

  return { mode: "literal", value: textValue(value) };
}

function typedMappedValue(mode: "literal" | "mapping", value: string, numeric = false) {
  return mode === "mapping"
    ? { type: "mapping", path: value.trim() }
    : { type: "literal", value: numeric && value !== "" ? Number(value) : value };
}

function conditionArray(value: unknown): WorkflowCondition[] {
  return Array.isArray(value)
    ? value.map((condition) => {
        const raw = condition && typeof condition === "object" ? condition as Record<string, unknown> : {};
        const mappedField = mappedValueParts(raw.field);
        return {
          id: textValue(raw.id) || newId("condition"),
          field: mappedField.mode === "mapping" ? mappedField.value : textValue(raw.field),
          operator: textValue(raw.operator) || "equals",
          value: raw.value,
        };
      })
    : [];
}

function tabLabel(tab: InspectorTab): string {
  return {
    event: "App & event",
    account: "Account",
    configure: "Configure",
    test: "Test",
  }[tab];
}

function MappingPathControl({
  id,
  value,
  options,
  label,
  required = false,
  onChange,
}: {
  id: string;
  value: string;
  options: MappingOption[];
  label: string;
  required?: boolean;
  onChange: (value: string) => void;
}) {
  const known = options.some((option) => option.path === value);
  const [custom, setCustom] = useState(value !== "" && !known);
  const groups = useMemo(() => {
    const result = new Map<string, MappingOption[]>();
    options.forEach((option) => {
      result.set(option.source, [...(result.get(option.source) ?? []), option]);
    });
    return Array.from(result.entries());
  }, [options]);

  useEffect(() => {
    if (known) setCustom(false);
  }, [known]);

  const showCustom = custom || (value !== "" && !known);
  return (
    <span className="eb-mapping-path-control">
      <select
        id={showCustom ? undefined : id}
        aria-label={label}
        value={showCustom ? "__custom" : value}
        required={required}
        onChange={(event) => {
          if (event.target.value === "__custom") {
            setCustom(true);
            onChange("");
          } else {
            setCustom(false);
            onChange(event.target.value);
          }
        }}
      >
        <option value="">Choose data…</option>
        {groups.map(([source, fields]) => (
          <optgroup key={source} label={source}>
            {fields.map((option) => (
              <option key={option.path} value={option.path}>{option.label}</option>
            ))}
          </optgroup>
        ))}
        <option value="__custom">Enter a custom path…</option>
      </select>
      {showCustom && (
        <input
          id={id}
          value={value}
          required={required}
          placeholder="trigger.output.field_name"
          aria-label={`${label} custom path`}
          onChange={(event) => onChange(
            event.target.value.replaceAll("{{", "").replaceAll("}}", "").trim(),
          )}
        />
      )}
    </span>
  );
}

function FieldControl({
  field,
  value,
  mappingOptions = [],
  idPrefix = "config",
  onChange,
}: {
  field: CatalogField;
  value: unknown;
  mappingOptions?: MappingOption[];
  idPrefix?: string;
  onChange: (value: unknown) => void;
}) {
  const id = `eb-${idPrefix}-${field.key}`;
  if (field.type === "boolean") {
    return (
      <label className="eb-toggle-field" htmlFor={id}>
        <span>
          <strong>{field.label}</strong>
          {field.help && <small>{field.help}</small>}
        </span>
        <input
          id={id}
          type="checkbox"
          checked={Boolean(value)}
          onChange={(event) => onChange(event.target.checked)}
        />
        <span className="eb-toggle-field__track" aria-hidden="true" />
      </label>
    );
  }

  if (field.type === "mapping") {
    const mapped = mappedValueParts(value);
    return (
      <fieldset className="eb-field eb-mapped-field">
        <legend>
          {field.label}
          {field.required && <em aria-hidden="true">*</em>}
        </legend>
        <div>
          <select
            aria-label={`${field.label} value source`}
            value={mapped.mode}
            onChange={(event) => onChange(typedMappedValue(
              event.target.value === "mapping" ? "mapping" : "literal",
              "",
            ))}
          >
            <option value="literal">Fixed</option>
            <option value="mapping">Mapped</option>
          </select>
          {mapped.mode === "mapping" ? (
            <MappingPathControl
              id={id}
              value={mapped.value}
              options={mappingOptions}
              label={`${field.label} mapped field`}
              required={field.required}
              onChange={(nextValue) => onChange(typedMappedValue("mapping", nextValue))}
            />
          ) : (
            <input
              id={id}
              value={mapped.value}
              required={field.required}
              placeholder={field.placeholder}
              onChange={(event) => onChange(typedMappedValue("literal", event.target.value))}
            />
          )}
        </div>
        {field.help && <small>{field.help}</small>}
      </fieldset>
    );
  }

  return (
    <label className="eb-field" htmlFor={id}>
      <span>
        {field.label}
        {field.required && <em aria-hidden="true">*</em>}
      </span>
      {field.type === "select" ? (
        <select
          id={id}
          value={textValue(value)}
          required={field.required}
          onChange={(event) => onChange(event.target.value)}
        >
          <option value="">Choose {field.label.toLowerCase()}</option>
          {(field.options ?? []).map((option) => (
            <option value={option.value} key={option.value}>{option.label}</option>
          ))}
        </select>
      ) : field.type === "textarea" ? (
        <textarea
          id={id}
          value={textValue(value)}
          required={field.required}
          placeholder={field.placeholder}
          rows={4}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : (
        <input
          id={id}
          type={field.type === "number" ? "number" : (field.type === "date" ? "date" : (field.type === "datetime" ? "datetime-local" : "text"))}
          value={textValue(value)}
          required={field.required}
          placeholder={field.placeholder}
          min={field.min}
          max={field.max}
          onChange={(event) => onChange(field.type === "number" ? Number(event.target.value) : event.target.value)}
        />
      )}
      {field.help && <small>{field.help}</small>}
    </label>
  );
}

function ObjectFieldControl({
  field,
  value,
  onChange,
}: {
  field: CatalogField;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  const serialized = value && typeof value === "object" && !Array.isArray(value)
    ? JSON.stringify(value, null, 2)
    : "";
  const [draft, setDraft] = useState(serialized);
  const [invalid, setInvalid] = useState(false);

  useEffect(() => {
    setDraft(serialized);
    setInvalid(false);
  }, [serialized]);

  return (
    <label className={`eb-field eb-json-field ${invalid ? "is-invalid" : ""}`}>
      <span>
        {field.label}
        {field.required && <em aria-hidden="true">*</em>}
      </span>
      <textarea
        value={draft}
        rows={5}
        spellCheck={false}
        placeholder='{ "key": "value" }'
        aria-invalid={invalid}
        onChange={(event) => {
          const next = event.target.value;
          setDraft(next);
          if (next.trim() === "") {
            setInvalid(false);
            onChange({});
            return;
          }
          try {
            const parsed = JSON.parse(next);
            const valid = parsed && typeof parsed === "object" && !Array.isArray(parsed);
            setInvalid(!valid);
            if (valid) onChange(parsed as Record<string, unknown>);
          } catch {
            setInvalid(true);
          }
        }}
      />
      <small>{invalid ? "Enter a valid JSON object." : (field.help || "Optional advanced settings in JSON format.")}</small>
    </label>
  );
}

function InputMappings({
  fields,
  value,
  mappingOptions,
  onChange,
}: {
  fields: CatalogField[];
  value: unknown;
  mappingOptions: MappingOption[];
  onChange: (value: Record<string, unknown>) => void;
}) {
  const inputs = value && typeof value === "object" && !Array.isArray(value)
    ? value as Record<string, unknown>
    : {};

  return (
    <fieldset className="eb-input-mappings">
      <legend>Action fields</legend>
      <p>Use a fixed value or choose data produced by the trigger or an earlier step.</p>
      {fields.map((field) => (
        <FieldControl
          key={field.key}
          field={{ ...field, type: "mapping" }}
          value={inputs[field.key]}
          mappingOptions={mappingOptions}
          idPrefix="input"
          onChange={(nextValue) => onChange({ ...inputs, [field.key]: nextValue })}
        />
      ))}
    </fieldset>
  );
}

function ConditionBuilder({
  conditions,
  logic,
  mappingOptions,
  onChange,
}: {
  conditions: WorkflowCondition[];
  logic: "and" | "or";
  mappingOptions: MappingOption[];
  onChange: (conditions: WorkflowCondition[], logic: "and" | "or") => void;
}) {
  const updateCondition = (id: string, patch: Partial<WorkflowCondition>) => {
    onChange(conditions.map((condition) => condition.id === id ? { ...condition, ...patch } : condition), logic);
  };

  return (
    <div className="eb-condition-builder">
      <div className="eb-condition-builder__logic">
        <span>Continue when</span>
        <select
          aria-label="Condition logic"
          value={logic}
          onChange={(event) => onChange(conditions, event.target.value === "or" ? "or" : "and")}
        >
          <option value="and">all conditions match</option>
          <option value="or">any condition matches</option>
        </select>
      </div>

      {conditions.map((condition, index) => (
        <div className="eb-condition-row" key={condition.id}>
          <span className="eb-condition-row__number">{index + 1}</span>
          <label>
            <span className="sr-only">Field or mapped value</span>
            <MappingPathControl
              id={`eb-condition-${condition.id}-field`}
              value={condition.field}
              options={mappingOptions}
              label={`Condition ${index + 1} field`}
              required
              onChange={(field) => updateCondition(condition.id, { field })}
            />
          </label>
          <label>
            <span className="sr-only">Operator</span>
            <select
              value={condition.operator}
              onChange={(event) => updateCondition(condition.id, { operator: event.target.value })}
            >
              {conditionOperators.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          {!["exists", "not_exists", "is_true", "is_false", "is_empty", "is_not_empty"].includes(condition.operator) && (
            <label>
              <span className="sr-only">Comparison value</span>
              <span className="eb-condition-value">
                <select
                  aria-label="Comparison value source"
                  value={mappedValueParts(condition.value).mode}
                  onChange={(event) => updateCondition(condition.id, {
                    value: typedMappedValue(
                      event.target.value === "mapping" ? "mapping" : "literal",
                      "",
                    ),
                  })}
                >
                  <option value="literal">Fixed</option>
                  <option value="mapping">Mapped</option>
                </select>
                {mappedValueParts(condition.value).mode === "mapping" ? (
                  <MappingPathControl
                    id={`eb-condition-${condition.id}-value`}
                    value={mappedValueParts(condition.value).value}
                    options={mappingOptions}
                    label={`Condition ${index + 1} comparison field`}
                    required
                    onChange={(nextValue) => updateCondition(condition.id, {
                      value: typedMappedValue("mapping", nextValue),
                    })}
                  />
                ) : (
                  <input
                    value={mappedValueParts(condition.value).value}
                    placeholder="Value"
                    onChange={(event) => updateCondition(condition.id, {
                      value: typedMappedValue("literal", event.target.value),
                    })}
                  />
                )}
              </span>
            </label>
          )}
          <button
            type="button"
            className="eb-condition-row__remove"
            aria-label={`Remove condition ${index + 1}`}
            disabled={conditions.length === 1}
            onClick={() => onChange(conditions.filter((item) => item.id !== condition.id), logic)}
          >
            <TrashIcon size={16} />
          </button>
        </div>
      ))}

      <button
        type="button"
        className="eb-inline-add"
        onClick={() => onChange([
          ...conditions,
          { id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } },
        ], logic)}
      >
        <PlusIcon size={15} />
        Add condition
      </button>
    </div>
  );
}

function FilterConfiguration({
  step,
  mappingOptions,
  onUpdate,
}: {
  step: WorkflowStep;
  mappingOptions: MappingOption[];
  onUpdate: (step: WorkflowStep) => void;
}) {
  const conditions = conditionArray(step.config.conditions);
  const safeConditions = conditions.length > 0
    ? conditions
    : [{ id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } }];
  const logic = step.config.logic === "or" ? "or" : "and";

  return (
    <ConditionBuilder
      conditions={safeConditions}
      logic={logic}
      mappingOptions={mappingOptions}
      onChange={(nextConditions, nextLogic) => onUpdate({
        ...step,
        config: { ...step.config, conditions: nextConditions, logic: nextLogic },
      })}
    />
  );
}

function DelayConfiguration({
  step,
  mappingOptions,
  onUpdate,
}: {
  step: WorkflowStep;
  mappingOptions: MappingOption[];
  onUpdate: (step: WorkflowStep) => void;
}) {
  const delayUntil = step.component_key.includes("until") || step.config.mode === "until";
  const configKey = delayUntil ? "datetime" : "duration";
  const mapped = mappedValueParts(step.config[configKey]);
  const setConfig = (key: string, value: unknown) => onUpdate({
    ...step,
    config: { ...step.config, [key]: value },
  });

  return (
    <div className="eb-inspector-fields">
      <label className="eb-field">
        <span>Value source</span>
        <select
          value={mapped.mode}
          onChange={(event) => setConfig(
            configKey,
            typedMappedValue(event.target.value === "mapping" ? "mapping" : "literal", ""),
          )}
        >
          <option value="literal">Fixed value</option>
          <option value="mapping">Use data from an earlier step</option>
        </select>
      </label>
      {delayUntil ? (
        <>
          <label className="eb-field">
            <span>Wait until</span>
            {mapped.mode === "mapping" ? (
              <MappingPathControl
                id={`eb-delay-${step.id}-datetime`}
                value={mapped.value}
                options={mappingOptions}
                label="Delay until mapped field"
                required
                onChange={(nextValue) => setConfig("datetime", typedMappedValue("mapping", nextValue))}
              />
            ) : (
              <input
                type="datetime-local"
                value={mapped.value}
                onChange={(event) => setConfig("datetime", typedMappedValue("literal", event.target.value))}
              />
            )}
          </label>
          <label className="eb-field">
            <span>If the date is already in the past</span>
            <select
              value={textValue(step.config.past_date_behavior) || "continue_if_within_1_day"}
              onChange={(event) => setConfig("past_date_behavior", event.target.value)}
            >
              <option value="continue_if_within_15_minutes">Continue if within 15 minutes</option>
              <option value="continue_if_within_1_hour">Continue if within 1 hour</option>
              <option value="continue_if_within_1_day">Continue if within 1 day</option>
              <option value="continue">Always continue</option>
            </select>
            <small>Dates older than the selected window stop the run item with a clear error.</small>
          </label>
        </>
      ) : (
        <div className="eb-field-row">
          <label className="eb-field">
            <span>Wait for</span>
            {mapped.mode === "mapping" ? (
              <MappingPathControl
                id={`eb-delay-${step.id}-duration`}
                value={mapped.value}
                options={mappingOptions}
                label="Delay duration mapped field"
                required
                onChange={(nextValue) => setConfig("duration", typedMappedValue("mapping", nextValue))}
              />
            ) : (
              <input
                type="number"
                min={1}
                value={mapped.value}
                placeholder="1"
                onChange={(event) => setConfig(
                  "duration",
                  typedMappedValue("literal", event.target.value, true),
                )}
              />
            )}
          </label>
          <label className="eb-field">
            <span>Unit</span>
            <select value={textValue(step.config.unit) || "hours"} onChange={(event) => setConfig("unit", event.target.value)}>
              <option value="minutes">Minutes</option>
              <option value="hours">Hours</option>
              <option value="days">Days</option>
            </select>
          </label>
        </div>
      )}
      <p className="eb-inspector-note">Delays must resolve between one minute and 30 days when the workflow runs.</p>
    </div>
  );
}

function PathsConfiguration({
  step,
  mappingOptions,
  onUpdate,
}: {
  step: WorkflowStep;
  mappingOptions: MappingOption[];
  onUpdate: (step: WorkflowStep) => void;
}) {
  const branches = step.branches ?? [];
  const updateBranch = (branchId: string, updater: (branch: WorkflowBranch) => WorkflowBranch) => {
    onUpdate({
      ...step,
      branches: branches.map((branch) => branch.id === branchId ? updater(branch) : branch),
    });
  };

  const addBranch = () => {
    const fallbackIndex = branches.findIndex((branch) => branch.type === "fallback");
    const nextBranch: WorkflowBranch = {
      id: newId("branch"),
      name: `Path ${String.fromCharCode(65 + branches.filter((branch) => branch.type !== "fallback").length)}`,
      type: "custom",
      logic: "and",
      conditions: [{ id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } }],
      steps: [],
    };
    const next = [...branches];
    next.splice(fallbackIndex >= 0 ? fallbackIndex : next.length, 0, nextBranch);
    onUpdate({ ...step, branches: next });
  };

  return (
    <div className="eb-path-config">
      <div className="eb-path-config__summary">
        <strong>Branch rules</strong>
        <span>Every matching path runs from left to right. Fallback runs only when no other path matches.</span>
      </div>
      {branches.map((branch, index) => (
        <details className="eb-path-config__branch" key={branch.id} open={index === 0}>
          <summary>
            <span className="eb-path-config__letter">{branch.type === "fallback" ? "F" : String.fromCharCode(65 + index)}</span>
            <span>
              <strong>{branch.name}</strong>
              <small>
                {branch.type === "fallback"
                  ? "Runs when nothing else matches"
                  : branch.type === "always"
                    ? "Always runs"
                    : `${branch.conditions?.length ?? 0} condition${branch.conditions?.length === 1 ? "" : "s"}`}
              </small>
            </span>
            <ChevronRightIcon size={16} />
          </summary>
          <div className="eb-path-config__body">
            <label className="eb-field">
              <span>Path name</span>
              <input value={branch.name} onChange={(event) => updateBranch(branch.id, (current) => ({ ...current, name: event.target.value }))} />
            </label>
            {branch.type !== "fallback" && (
              <label className="eb-field">
                <span>Path behavior</span>
                <select
                  aria-label={`${branch.name} behavior`}
                  value={branch.type}
                  onChange={(event) => updateBranch(branch.id, (current) => {
                    const type: WorkflowBranch["type"] = event.target.value === "always" ? "always" : "custom";
                    return {
                      ...current,
                      type,
                      conditions: type === "always"
                        ? []
                        : (current.conditions?.length
                            ? current.conditions
                            : [{ id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } }]),
                    };
                  })}
                >
                  <option value="custom">Run when rules match</option>
                  <option value="always">Always run</option>
                </select>
                <small>An always path runs on every item and prevents fallback from running.</small>
              </label>
            )}
            {branch.type === "custom" && (
              <ConditionBuilder
                conditions={(branch.conditions?.length ?? 0) > 0 ? branch.conditions! : [{ id: newId("condition"), field: "", operator: "equals", value: { type: "literal", value: "" } }]}
                logic={branch.logic === "or" ? "or" : "and"}
                mappingOptions={mappingOptions}
                onChange={(conditions, logic) => updateBranch(branch.id, (current) => ({ ...current, conditions, logic }))}
              />
            )}
            {branches.length > 2 && branch.type !== "fallback" && (
              <button
                type="button"
                className="eb-text-danger"
                onClick={() => onUpdate({ ...step, branches: branches.filter((item) => item.id !== branch.id) })}
              >
                <TrashIcon size={15} />
                Remove path
              </button>
            )}
          </div>
        </details>
      ))}
      <button type="button" className="eb-inline-add" disabled={branches.length >= 10} onClick={addBranch}>
        <PlusIcon size={15} />
        Add path
      </button>
    </div>
  );
}

export function StepInspector({
  step,
  component,
  connections,
  connectionsUrl,
  testState,
  mappingOptions = [],
  busyTest,
  onUpdate,
  onDelete,
  onChangeComponent,
  onTest,
  onClose,
}: StepInspectorProps) {
  const [tab, setTab] = useState<InspectorTab>("event");
  const narrow = useNarrowInspector();
  const inspectorRef = useRef<HTMLElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  const onCloseRef = useRef(onClose);
  const tabRefs = useRef<Record<InspectorTab, HTMLButtonElement | null>>({
    event: null,
    account: null,
    configure: null,
    test: null,
  });

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    setTab("event");
  }, [step?.id]);

  useEffect(() => {
    if (!step || !narrow) return undefined;
    const active = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (!inspectorRef.current?.contains(active)) previousFocusRef.current = active;
    const focusTimer = window.setTimeout(() => closeButtonRef.current?.focus(), 20);

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab" || !inspectorRef.current) return;
      const focusable = Array.from(inspectorRef.current.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href]:not([aria-disabled="true"]), [tabindex]:not([tabindex="-1"])',
      )).filter((element) => element.offsetParent !== null);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener("keydown", handleKeyDown);
    document.body.classList.add("eb-workflow-inspector-open");
    return () => {
      window.clearTimeout(focusTimer);
      document.removeEventListener("keydown", handleKeyDown);
      document.body.classList.remove("eb-workflow-inspector-open");
      window.setTimeout(() => {
        if (previousFocusRef.current?.isConnected) previousFocusRef.current.focus();
      }, 0);
    };
  }, [narrow, step?.id]);

  const state = useMemo(() => {
    if (!step) return {};
    const direct = testState[step.id];
    if (direct && typeof direct === "object") return direct as Record<string, unknown>;
    const legacy = step.kind === "trigger" ? testState.trigger : (step.kind === "action" ? testState.action : null);
    return legacy && typeof legacy === "object" ? legacy as Record<string, unknown> : {};
  }, [step, testState]);

  if (!step) {
    if (narrow) return null;
    return (
      <aside className="eb-step-inspector eb-step-inspector--empty" aria-label="Step setup">
        <span className="eb-step-inspector__empty-icon"><WorkflowIcon /></span>
        <h2>Select a step to set it up</h2>
        <p>Choose a card on the canvas to connect an account, map data, and run a test.</p>
      </aside>
    );
  }

  const provider = component?.provider ?? step.component_key.split(".")[0] ?? "everbranch";
  const providerLabel = component?.provider_label ?? provider.replaceAll("_", " ");
  const componentLabel = component?.label ?? step.component_key.replaceAll(/[._]/g, " ");
  const isNative = ["everbranch", "core"].includes(provider);
  const tested = state.ok === true || state.status === "passed" || state.status === "success";
  const failed = state.ok === false || state.status === "failed";
  const inspectorId = `eb-step-inspector-${step.id}`;

  const updateConfig = (key: string, value: unknown) => {
    onUpdate({ ...step, config: { ...step.config, [key]: value } });
  };
  const moveTabFocus = (event: React.KeyboardEvent<HTMLButtonElement>, index: number) => {
    if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
    const nextIndex = event.key === "Home"
      ? 0
      : event.key === "End"
        ? inspectorTabs.length - 1
        : event.key === "ArrowRight"
          ? (index + 1) % inspectorTabs.length
          : (index - 1 + inspectorTabs.length) % inspectorTabs.length;
    const nextTab = inspectorTabs[nextIndex];
    event.preventDefault();
    setTab(nextTab);
    tabRefs.current[nextTab]?.focus();
  };

  return (
    <aside
      ref={inspectorRef}
      className="eb-step-inspector"
      role={narrow ? "dialog" : undefined}
      aria-modal={narrow ? true : undefined}
      aria-labelledby={`${inspectorId}-title`}
    >
      <header className="eb-step-inspector__header">
        <div>
          <span>{step.kind === "trigger" ? "Trigger setup" : "Step setup"}</span>
          <h2 id={`${inspectorId}-title`}>{componentLabel}</h2>
        </div>
        <button ref={closeButtonRef} type="button" className="eb-icon-button" aria-label="Close step setup" onClick={onClose}>
          <CloseIcon />
        </button>
      </header>

      <div className="eb-step-inspector__tabs" role="tablist" aria-label="Step setup sections">
        {inspectorTabs.map((item, index) => (
          <button
            type="button"
            key={item}
            ref={(element) => { tabRefs.current[item] = element; }}
            id={`${inspectorId}-tab-${item}`}
            className={tab === item ? "is-active" : ""}
            aria-selected={tab === item}
            aria-controls={`${inspectorId}-panel-${item}`}
            tabIndex={tab === item ? 0 : -1}
            role="tab"
            onClick={() => setTab(item)}
            onKeyDown={(event) => moveTabFocus(event, index)}
          >
            <span>{tabLabel(item)}</span>
            {item === "test" && tested && <CheckIcon size={13} />}
          </button>
        ))}
      </div>

      <div className="eb-step-inspector__body">
        <section
          className="eb-inspector-section"
          role="tabpanel"
          id={`${inspectorId}-panel-event`}
          aria-labelledby={`${inspectorId}-tab-event`}
          tabIndex={0}
          hidden={tab !== "event"}
        >
            <p className="eb-inspector-section__eyebrow">App</p>
            <div className="eb-selected-app">
              <ProviderMark provider={provider} label={providerLabel} kind={step.kind} size="lg" />
              <span>
                <strong>{providerLabel}</strong>
                <small>Available and enabled</small>
              </span>
            </div>
            <p className="eb-inspector-section__eyebrow">Event</p>
            <button type="button" className="eb-event-selection" onClick={onChangeComponent}>
              <span>
                <strong>{componentLabel}</strong>
                <small>{component?.description}</small>
              </span>
              <span>Change</span>
            </button>
        </section>

        <section
          className="eb-inspector-section"
          role="tabpanel"
          id={`${inspectorId}-panel-account`}
          aria-labelledby={`${inspectorId}-tab-account`}
          tabIndex={0}
          hidden={tab !== "account"}
        >
            {isNative || component?.connection_required === false ? (
              <div className="eb-account-ready">
                <span><CheckIcon /></span>
                <div>
                  <strong>This workspace</strong>
                  <p>Everbranch uses the current workspace and keeps every operation tenant-scoped.</p>
                </div>
              </div>
            ) : (
              <>
                <label className="eb-field">
                  <span>{providerLabel} account</span>
                  <select
                    value={textValue(step.connection_id)}
                    onChange={(event) => onUpdate({ ...step, connection_id: event.target.value })}
                  >
                    <option value="">Choose an account</option>
                    {connections.filter((connection) => connection.status !== "error").map((connection) => (
                      <option key={connection.id} value={connection.id}>{connection.label}</option>
                    ))}
                  </select>
                  <small>Connections are shared safely across workflows in this workspace.</small>
                </label>
                {connections.length === 0 && (
                  <div className="eb-connection-empty">
                    <strong>No connected {providerLabel} account</strong>
                    <p>Connect and test the account before this step can publish.</p>
                    {connectionsUrl && <a href={connectionsUrl}>Open Connections</a>}
                  </div>
                )}
              </>
            )}
        </section>

        <section
          className="eb-inspector-section"
          role="tabpanel"
          id={`${inspectorId}-panel-configure`}
          aria-labelledby={`${inspectorId}-tab-configure`}
          tabIndex={0}
          hidden={tab !== "configure"}
        >
            {step.kind === "filter" ? (
              <FilterConfiguration step={step} mappingOptions={mappingOptions} onUpdate={onUpdate} />
            ) : step.kind === "delay" ? (
              <DelayConfiguration step={step} mappingOptions={mappingOptions} onUpdate={onUpdate} />
            ) : step.kind === "paths" ? (
              <PathsConfiguration step={step} mappingOptions={mappingOptions} onUpdate={onUpdate} />
            ) : (component?.config_fields.length ?? 0) > 0 ? (
              <div className="eb-inspector-fields">
                {component!.config_fields.map((field) => {
                  if (field.type === "object" && field.key === "inputs" && component!.input_fields.length > 0) {
                    return (
                      <InputMappings
                        key={field.key}
                        fields={component!.input_fields}
                        value={step.config[field.key]}
                        mappingOptions={mappingOptions}
                        onChange={(value) => updateConfig(field.key, value)}
                      />
                    );
                  }
                  if (field.type === "object") {
                    return (
                      <ObjectFieldControl
                        key={field.key}
                        field={field}
                        value={step.config[field.key]}
                        onChange={(value) => updateConfig(field.key, value)}
                      />
                    );
                  }
                  return (
                    <FieldControl
                      key={field.key}
                      field={field}
                      value={step.config[field.key]}
                      mappingOptions={mappingOptions}
                      onChange={(value) => updateConfig(field.key, value)}
                    />
                  );
                })}
              </div>
            ) : (
              <div className="eb-config-ready">
                <span><CheckIcon /></span>
                <div>
                  <strong>No additional fields</strong>
                  <p>This step is ready once its account is selected.</p>
                </div>
              </div>
            )}
        </section>

        <section
          className="eb-inspector-section"
          role="tabpanel"
          id={`${inspectorId}-panel-test`}
          aria-labelledby={`${inspectorId}-tab-test`}
          tabIndex={0}
          hidden={tab !== "test"}
        >
            <div className={`eb-test-state ${tested ? "is-passed" : (failed ? "is-failed" : "")}`}>
              <span>{tested ? <CheckIcon /> : <ProviderMark provider={provider} kind={step.kind} size="sm" />}</span>
              <div>
                <strong>{tested ? "Test passed" : (failed ? "Test needs attention" : "Test this step")}</strong>
                <p>{summaryText(state.summary) || summaryText(state.message) || "Everbranch will use safe sample data and record the result against this draft."}</p>
              </div>
            </div>
            <button
              type="button"
              className="eb-primary-button eb-primary-button--full"
              disabled={busyTest}
              onClick={() => onTest(step)}
            >
              {busyTest ? <span className="eb-button-spinner" aria-hidden="true" /> : null}
              {busyTest ? "Testing…" : (tested ? "Test again" : "Test step")}
            </button>
            <p className="eb-inspector-note">Editing this step after a test makes its prior result stale.</p>
        </section>
      </div>

      <footer className="eb-step-inspector__footer">
        {step.kind !== "trigger" ? (
          <button type="button" className="eb-text-danger" onClick={() => onDelete(step.id)}>
            <TrashIcon size={16} />
            Delete step
          </button>
        ) : <span />}
        <span>Step ID · {step.id.slice(-8)}</span>
      </footer>
    </aside>
  );
}
