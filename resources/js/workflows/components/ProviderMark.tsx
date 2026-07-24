import React from "react";
import {
  DelayIcon,
  FilterIcon,
  PathIcon,
  TriggerIcon,
  UtilityIcon,
  WorkflowIcon,
} from "../icons";
import type { WorkflowStepKind } from "../types";

type ProviderMarkProps = {
  provider: string;
  label?: string;
  kind?: WorkflowStepKind;
  size?: "sm" | "md" | "lg";
};

function KnownProviderGlyph({ provider }: { provider: string }) {
  if (provider === "asana") {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="6.3" r="3.35" />
        <circle cx="6.7" cy="15.6" r="3.35" />
        <circle cx="17.3" cy="15.6" r="3.35" />
      </svg>
    );
  }

  if (provider === "google_calendar") {
    return <span className="eb-provider-mark__calendar">31</span>;
  }

  if (provider === "shopify") {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M6.7 8.5h10.6l1 11H5.7z" fill="none" stroke="currentColor" strokeWidth="1.8" />
        <path d="M8.9 9V7.2a3.1 3.1 0 0 1 6.2 0V9" fill="none" stroke="currentColor" strokeWidth="1.8" />
        <path d="M13.9 11.4c-.8-.4-2.4-.6-2.4.5 0 1.6 3.2 1.2 3.2 3.6 0 2-1.7 3-4.5 2.1" fill="none" stroke="currentColor" strokeWidth="1.6" />
      </svg>
    );
  }

  if (provider === "square") {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <rect x="3.8" y="3.8" width="16.4" height="16.4" rx="3" fill="none" stroke="currentColor" strokeWidth="2" />
        <rect x="8.2" y="8.2" width="7.6" height="7.6" rx="1.2" fill="currentColor" />
      </svg>
    );
  }

  if (provider === "everbranch" || provider === "core") {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M18.8 3.5C11.4 4 6.7 7.4 5.4 13.7c3.4.5 6.2-.4 8.2-2.7-1.6 2.7-4.2 4.8-7.8 6.4" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        <path d="M5.7 17.4c-.5 1-.8 2-.9 3.1" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
      </svg>
    );
  }

  return null;
}

function KindGlyph({ kind }: { kind?: WorkflowStepKind }) {
  if (kind === "filter") return <FilterIcon size={18} />;
  if (kind === "delay") return <DelayIcon size={18} />;
  if (kind === "paths") return <PathIcon size={18} />;
  if (kind === "trigger") return <TriggerIcon size={18} />;
  if (kind === "utility") return <UtilityIcon size={18} />;
  return <WorkflowIcon size={18} />;
}

export function ProviderMark({ provider, label, kind, size = "md" }: ProviderMarkProps) {
  const knownGlyph = <KnownProviderGlyph provider={provider} />;
  const isCoreControl = provider === "core"
    && kind !== undefined
    && ["filter", "delay", "paths", "utility"].includes(kind);
  const initials = (label ?? provider)
    .split(/[\s_-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join("");

  return (
    <span
      className={`eb-provider-mark eb-provider-mark--${size} eb-provider-mark--${provider.replaceAll("_", "-")}`}
      aria-hidden="true"
    >
      {provider === "everbranch" || provider === "core"
        ? (isCoreControl ? <KindGlyph kind={kind} /> : knownGlyph)
        : (knownGlyph || (kind ? <KindGlyph kind={kind} /> : <span>{initials}</span>))}
    </span>
  );
}
