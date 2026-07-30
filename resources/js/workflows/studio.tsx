import React from "react";
import { createRoot, type Root } from "react-dom/client";
import { StudioApp } from "./StudioApp";
import { normalizeBootstrap } from "./normalize";

const mountedRoots = new WeakMap<Element, Root>();

function readBootstrap(element: HTMLElement): unknown {
  const value = element.dataset.workflowBootstrap;
  if (!value) return {};

  try {
    return JSON.parse(value);
  } catch (error) {
    console.error("Workflow Studio bootstrap JSON could not be parsed.", error);
    return {};
  }
}

export function mountWorkflowStudioNow() {
  document.querySelectorAll<HTMLElement>("[data-workflow-studio-root]").forEach((element) => {
    if (mountedRoots.has(element)) return;
    const root = createRoot(element);
    mountedRoots.set(element, root);
    root.render(<StudioApp bootstrap={normalizeBootstrap(readBootstrap(element))} />);
  });
}

mountWorkflowStudioNow();
document.addEventListener("DOMContentLoaded", mountWorkflowStudioNow);
document.addEventListener("livewire:navigated", mountWorkflowStudioNow);
