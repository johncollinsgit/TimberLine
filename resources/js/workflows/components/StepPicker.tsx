import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  CloseIcon,
  FilterIcon,
  HomeIcon,
  LayersIcon,
  SearchIcon,
  UtilityIcon,
  WorkflowIcon,
} from "../icons";
import type {
  CatalogComponent,
  PickerCategory,
  WorkflowTemplate,
} from "../types";
import { ProviderMark } from "./ProviderMark";

type StepPickerProps = {
  open: boolean;
  category: PickerCategory;
  components: CatalogComponent[];
  templates: WorkflowTemplate[];
  triggerOnly: boolean;
  allowPaths: boolean;
  allowTemplates: boolean;
  onCategoryChange: (category: PickerCategory) => void;
  onChooseComponent: (component: CatalogComponent) => void;
  onChooseTemplate: (template: WorkflowTemplate) => void;
  onClose: () => void;
};

const categories: Array<{
  key: PickerCategory;
  label: string;
  icon: React.ReactNode;
}> = [
  { key: "home", label: "Home", icon: <HomeIcon /> },
  { key: "apps", label: "Apps", icon: <WorkflowIcon /> },
  { key: "controls", label: "Flow controls", icon: <FilterIcon /> },
  { key: "utilities", label: "Utilities", icon: <UtilityIcon /> },
  { key: "templates", label: "Templates", icon: <LayersIcon /> },
];

function categoryMatches(component: CatalogComponent, category: PickerCategory): boolean {
  if (category === "home") return true;
  if (category === "apps") return component.kind === "trigger" || component.kind === "action";
  if (category === "controls") return ["filter", "delay", "paths"].includes(component.kind);
  if (category === "utilities") return component.kind === "utility";
  return false;
}

function kindLabel(component: CatalogComponent): string {
  if (component.kind === "paths") return "Flow control";
  return component.kind.charAt(0).toUpperCase() + component.kind.slice(1);
}

export function StepPicker({
  open,
  category,
  components,
  templates,
  triggerOnly,
  allowPaths,
  allowTemplates,
  onCategoryChange,
  onChooseComponent,
  onChooseTemplate,
  onClose,
}: StepPickerProps) {
  const [query, setQuery] = useState("");
  const dialogRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    if (!open) return undefined;
    previousFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const focusTimer = window.setTimeout(() => searchRef.current?.focus(), 20);

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }

      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "f") {
        event.preventDefault();
        searchRef.current?.focus();
        searchRef.current?.select();
        return;
      }

      if (event.key === "Tab" && dialogRef.current) {
        const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(
          'button:not([disabled]), input:not([disabled]), [href]:not([aria-disabled="true"]), [tabindex]:not([tabindex="-1"])',
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
      }
    };

    document.addEventListener("keydown", handleKeyDown);
    document.body.classList.add("eb-workflow-picker-open");

    return () => {
      window.clearTimeout(focusTimer);
      document.removeEventListener("keydown", handleKeyDown);
      document.body.classList.remove("eb-workflow-picker-open");
      window.setTimeout(() => previousFocusRef.current?.focus(), 0);
    };
  }, [open]);

  useEffect(() => {
    if (open) setQuery("");
  }, [open, category]);

  const visibleComponents = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();
    return components.filter((component) => {
      if (!component.available) return false;
      if (triggerOnly && component.kind !== "trigger") return false;
      if (!triggerOnly && component.kind === "trigger") return false;
      if (!allowPaths && component.kind === "paths") return false;
      if (!normalizedQuery && !categoryMatches(component, category)) return false;
      if (!normalizedQuery) return true;
      return [
        component.label,
        component.description,
        component.provider,
        component.provider_label,
        component.kind,
      ].join(" ").toLowerCase().includes(normalizedQuery);
    });
  }, [allowPaths, category, components, query, triggerOnly]);

  const visibleTemplates = useMemo(() => {
    if (!allowTemplates) return [];
    const normalizedQuery = query.trim().toLowerCase();
    if (category !== "templates" && !normalizedQuery) return [];
    return templates.filter((template) => template.available && (
      !normalizedQuery
      || `${template.name} ${template.description}`.toLowerCase().includes(normalizedQuery)
    ));
  }, [allowTemplates, category, query, templates]);

  const availableCategories = useMemo(
    () => categories.filter((item) => {
      if (item.key === "templates") return allowTemplates;
      if (triggerOnly && (item.key === "controls" || item.key === "utilities")) return false;
      return true;
    }),
    [allowTemplates, triggerOnly],
  );

  const focusResult = (event: React.KeyboardEvent<HTMLElement>) => {
    if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key) || !dialogRef.current) return;
    if (event.currentTarget === searchRef.current && !["ArrowDown", "ArrowUp"].includes(event.key)) return;
    const results = Array.from(dialogRef.current.querySelectorAll<HTMLButtonElement>("[data-picker-result]"));
    if (results.length === 0) return;
    const current = results.indexOf(document.activeElement as HTMLButtonElement);
    const next = event.key === "Home"
      ? 0
      : event.key === "End"
        ? results.length - 1
        : current < 0
          ? (event.key === "ArrowDown" ? 0 : results.length - 1)
          : event.key === "ArrowDown"
            ? (current + 1) % results.length
            : (current - 1 + results.length) % results.length;
    event.preventDefault();
    results[next].focus();
  };

  if (!open) return null;

  const title = triggerOnly ? "Choose the trigger" : "Add a step";
  const copy = triggerOnly
    ? "Select the event that should start this workflow."
    : "Choose a working app action or add logic to the flow.";

  return (
    <div className="eb-step-picker-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <div
        ref={dialogRef}
        className="eb-step-picker"
        role="dialog"
        aria-modal="true"
        aria-labelledby="eb-step-picker-title"
        aria-describedby="eb-step-picker-description"
      >
        <aside className="eb-step-picker__rail">
          <div className="eb-step-picker__rail-title">
            <span className="eb-step-picker__brand-mark"><WorkflowIcon size={17} /></span>
            <strong>Workflow Studio</strong>
          </div>
          <nav aria-label="Step library">
            {availableCategories.map((item) => (
              <button
                key={item.key}
                type="button"
                className={category === item.key ? "is-active" : ""}
                aria-current={category === item.key ? "page" : undefined}
                onClick={() => onCategoryChange(item.key)}
              >
                {item.icon}
                <span>{item.label}</span>
              </button>
            ))}
          </nav>
          <div className="eb-step-picker__rail-note">
            <span className="eb-status-dot eb-status-dot--ready" />
            Only working steps are shown
          </div>
        </aside>

        <section className="eb-step-picker__main">
          <header className="eb-step-picker__header">
            <div>
              <h2 id="eb-step-picker-title">{title}</h2>
              <p id="eb-step-picker-description">{copy}</p>
            </div>
            <button type="button" className="eb-icon-button" aria-label="Close step picker" onClick={onClose}>
              <CloseIcon />
            </button>
          </header>

          <label className="eb-step-picker__search">
            <SearchIcon />
            <span className="sr-only">Search available workflow steps</span>
            <input
              ref={searchRef}
              type="search"
              value={query}
              placeholder={triggerOnly ? "Search triggers and apps" : "Search apps, actions, and controls"}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={focusResult}
            />
            <kbd>⌘/Ctrl F</kbd>
          </label>

          <div className="eb-step-picker__results" onKeyDown={focusResult}>
            {category === "home" && query === "" && (
              <div className="eb-picker-section-heading">
                <div>
                  <h3>{triggerOnly ? "Available triggers" : "Recommended next steps"}</h3>
                  <p>{triggerOnly ? "Start with a source event." : "Build with apps and flow controls."}</p>
                </div>
                <span>{visibleComponents.length} working</span>
              </div>
            )}

            {category === "apps" && query === "" && (
              <div className="eb-picker-section-heading">
                <div>
                  <h3>{triggerOnly ? "Trigger apps" : "App actions"}</h3>
                  <p>Connected accounts are selected after you add the step.</p>
                </div>
              </div>
            )}

            {category === "controls" && query === "" && (
              <div className="eb-picker-section-heading">
                <div>
                  <h3>Flow controls</h3>
                  <p>Filter, wait, or send work down separate paths.</p>
                </div>
              </div>
            )}

            {category === "utilities" && query === "" && (
              <div className="eb-picker-section-heading">
                <div>
                  <h3>Utilities</h3>
                  <p>Only production-ready utilities appear here.</p>
                </div>
              </div>
            )}

            {(visibleComponents.length > 0) && (
              <ul className="eb-picker-grid">
                {visibleComponents.map((component) => (
                  <li key={component.key}>
                    <button
                      type="button"
                      className="eb-picker-result"
                      data-picker-result
                      onClick={() => onChooseComponent(component)}
                    >
                      <ProviderMark
                        provider={component.provider}
                        label={component.provider_label}
                        kind={component.kind}
                        size="lg"
                      />
                      <span className="eb-picker-result__copy">
                        <span className="eb-picker-result__meta">
                          <strong>{component.provider_label}</strong>
                          <small>{kindLabel(component)}</small>
                        </span>
                        <span className="eb-picker-result__title">{component.label}</span>
                        <span className="eb-picker-result__description">{component.description}</span>
                      </span>
                      <span className="eb-picker-result__arrow" aria-hidden="true">›</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}

            {allowTemplates && (category === "templates" || (query !== "" && visibleTemplates.length > 0)) && (
              <>
                <div className="eb-picker-section-heading">
                  <div>
                    <h3>Executable templates</h3>
                    <p>Start with a complete workflow and edit every step.</p>
                  </div>
                  <span>{visibleTemplates.length} available</span>
                </div>
                <ul className="eb-picker-template-list">
                  {visibleTemplates.map((template) => (
                    <li key={template.key}>
                      <button
                        type="button"
                        className="eb-template-result"
                        data-picker-result
                        onClick={() => onChooseTemplate(template)}
                      >
                        <span className="eb-template-result__marks" aria-hidden="true">
                          <ProviderMark provider={template.trigger_provider || "everbranch"} size="sm" />
                          <ProviderMark provider={template.action_provider || "everbranch"} size="sm" />
                        </span>
                        <span>
                          <strong>{template.name}</strong>
                          <small>{template.description}</small>
                        </span>
                        <span aria-hidden="true">›</span>
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}

            {visibleComponents.length === 0 && visibleTemplates.length === 0 && (
              <div className="eb-picker-empty">
                <span><SearchIcon size={22} /></span>
                <h3>No working steps found</h3>
                <p>
                  {query
                    ? "Try another search or choose a different category."
                    : "There are no enabled components in this category yet."}
                </p>
              </div>
            )}
          </div>
        </section>
      </div>
    </div>
  );
}
