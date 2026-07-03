const ROOT_SELECTOR = "[data-public-product-demo]";
const SCENARIO_SELECTOR = "[data-product-demo-scenario]";
const MODE_SELECTOR = "[data-product-demo-mode]";
const STEP_SELECTOR = "[data-product-demo-step]";
const PROBLEM_ITEM_SELECTOR = "[data-product-demo-problem-item]";
const PANE_SELECTOR = "[data-product-demo-pane]";
const PANE_PANEL_SELECTOR = "[data-product-demo-pane-panel]";
const BUD_SEARCH_SELECTOR = "[data-product-demo-bud-search]";

const AUTOPLAY_DELAY = 11200;
const SOLUTION_DELAY = 5000;
const SOLVE_POP_DELAY = 660;
const STEP_DELAY = 760;

function prefersReducedMotion() {
  return window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches ?? false;
}

function updateText(root, selector, value) {
  root.querySelectorAll(selector).forEach((element) => {
    element.textContent = value;
  });
}

function parseStructuredList(value) {
  return `${value || ""}`
    .split("|")
    .map((item) => item.trim())
    .filter(Boolean)
    .map((item) => item.split("::").map((segment) => segment.trim()));
}

function deriveInitials(name) {
  const parts = `${name || ""}`
    .split(/\s+/)
    .map((part) => part.trim())
    .filter(Boolean);

  if (parts.length === 0) {
    return "--";
  }

  return parts
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join("")
    .toUpperCase();
}

function updateProblemItems(root, trigger) {
  const problems = (trigger.dataset.demoProblems || "")
    .split("|")
    .map((problem) => problem.trim())
    .filter(Boolean);

  root.querySelectorAll(PROBLEM_ITEM_SELECTOR).forEach((element) => {
    const index = Number.parseInt(element.dataset.productDemoProblemItem || "0", 10);
    const problem = problems[index];

    element.textContent = problem || "";
    element.hidden = !problem;
  });
}

function updateStepLabels(root, trigger) {
  const labels = {
    one: trigger.dataset.demoStepOne,
    two: trigger.dataset.demoStepTwo,
    three: trigger.dataset.demoStepThree,
    four: trigger.dataset.demoStepFour,
  };

  Object.entries(labels).forEach(([key, value]) => {
    if (value) {
      updateText(root, `[data-product-demo-step-label="${key}"]`, value);
    }
  });
}

function updateStats(root, trigger) {
  const stats = parseStructuredList(trigger.dataset.demoStats);

  root.querySelectorAll("[data-product-demo-stat]").forEach((element, index) => {
    const [label = "", value = "", meta = ""] = stats[index] || [];
    element.hidden = !label && !value && !meta;
    updateText(element, "[data-product-demo-stat-label]", label);
    updateText(element, "[data-product-demo-stat-value]", value);
    updateText(element, "[data-product-demo-stat-meta]", meta);
  });
}

function updateLinks(root, trigger) {
  const links = parseStructuredList(trigger.dataset.demoLinks);

  root.querySelectorAll("[data-product-demo-link]").forEach((element, index) => {
    const [title = "", meta = ""] = links[index] || [];
    element.hidden = !title && !meta;
    updateText(element, "[data-product-demo-link-title]", title);
    updateText(element, "[data-product-demo-link-meta]", meta);
  });
}

function updateJobs(root, trigger) {
  const jobs = parseStructuredList(trigger.dataset.demoJobs);

  root.querySelectorAll("[data-product-demo-job]").forEach((element, index) => {
    const [title = "", meta = ""] = jobs[index] || [];
    element.hidden = !title && !meta;
    updateText(element, "[data-product-demo-job-title]", title);
    updateText(element, "[data-product-demo-job-meta]", meta);
  });
}

function updateTeam(root, trigger) {
  const team = parseStructuredList(trigger.dataset.demoTeam);

  root.querySelectorAll("[data-product-demo-team]").forEach((element, index) => {
    const [name = "", role = "", status = ""] = team[index] || [];
    element.hidden = !name && !role && !status;
    updateText(element, "[data-product-demo-team-avatar]", deriveInitials(name));
    updateText(element, "[data-product-demo-team-name]", name);
    updateText(element, "[data-product-demo-team-role]", role);
    updateText(element, "[data-product-demo-team-status]", status);
  });
}

function updateChart(root, trigger) {
  const points = parseStructuredList(trigger.dataset.demoChart);
  const values = points
    .map(([, value = "0"]) => Number.parseFloat(value.replace(/[^0-9.]/g, "")) || 0);
  const maxValue = Math.max(...values, 1);

  root.querySelectorAll("[data-product-demo-chart]").forEach((element, index) => {
    const [label = "", value = "0"] = points[index] || [];
    const numericValue = Number.parseFloat(`${value}`.replace(/[^0-9.]/g, "")) || 0;
    const fill = element.querySelector("[data-product-demo-chart-fill]");

    element.hidden = !label && !value;
    updateText(element, "[data-product-demo-chart-label]", label);
    updateText(element, "[data-product-demo-chart-value]", value);

    if (fill) {
      fill.style.height = `${Math.max((numericValue / maxValue) * 100, 12)}%`;
    }
  });
}

function applyScenario(root, trigger) {
  const fields = {
    customer: trigger.dataset.demoCustomer,
    type: trigger.dataset.demoType,
    primary: trigger.dataset.demoPrimary,
    note: trigger.dataset.demoNote,
    task: trigger.dataset.demoTask,
    owner: trigger.dataset.demoOwner,
    followup: trigger.dataset.demoFollowup,
  };

  Object.entries(fields).forEach(([key, value]) => {
    if (value) {
      updateText(root, `[data-product-demo-field="${key}"]`, value);
    }
  });

  updateText(root, '[data-product-demo-feed="one"]', trigger.dataset.demoFeedOne || "");
  updateText(root, '[data-product-demo-feed="two"]', trigger.dataset.demoFeedTwo || "");
  updateText(root, '[data-product-demo-feed="three"]', trigger.dataset.demoFeedThree || "");
  updateProblemItems(root, trigger);
  updateStepLabels(root, trigger);
  updateStats(root, trigger);
  updateLinks(root, trigger);
  updateJobs(root, trigger);
  updateTeam(root, trigger);
  updateChart(root, trigger);

  root.querySelectorAll(SCENARIO_SELECTOR).forEach((button) => {
    const active = button === trigger;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-selected", active ? "true" : "false");
  });

  root.classList.add("is-changing");
  window.setTimeout(() => root.classList.remove("is-changing"), 280);
}

function updateModeButtons(root, mode) {
  root.querySelectorAll(MODE_SELECTOR).forEach((button) => {
    const active = button.dataset.productDemoMode === mode;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-selected", active ? "true" : "false");
  });
}

function applyPane(root, pane) {
  root.dataset.demoPane = pane;

  root.querySelectorAll(PANE_SELECTOR).forEach((button) => {
    const active = button.dataset.productDemoPane === pane;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-pressed", active ? "true" : "false");
  });

  root.querySelectorAll(PANE_PANEL_SELECTOR).forEach((panel) => {
    const visibility = `${panel.dataset.productDemoPanePanel || ""}`
      .split(/\s+/)
      .map((item) => item.trim())
      .filter(Boolean);

    panel.hidden = visibility.length > 0 && !visibility.includes(pane);
  });
}

function applyMode(root, mode) {
  root.classList.remove("is-solving");
  root.dataset.demoMode = mode;
  updateModeButtons(root, mode);
}

function setStep(root, activeStep) {
  root.querySelectorAll(STEP_SELECTOR).forEach((step) => {
    const index = Number.parseInt(step.dataset.productDemoStep || "0", 10);
    step.classList.toggle("is-active", index === activeStep);
    step.classList.toggle("is-complete", index < activeStep || activeStep === 3);
  });
}

function clearTimers(timers) {
  timers.forEach((timer) => window.clearTimeout(timer));
  timers.clear();
}

function mountRoot(root) {
  if (root.dataset.productDemoMounted === "true") {
    return;
  }

  root.dataset.productDemoMounted = "true";

  const scenarioButtons = Array.from(root.querySelectorAll(SCENARIO_SELECTOR));
  const modeButtons = Array.from(root.querySelectorAll(MODE_SELECTOR));
  const paneButtons = Array.from(root.querySelectorAll(PANE_SELECTOR));
  const budSearchButton = root.querySelector(BUD_SEARCH_SELECTOR);
  const timers = new Set();
  const reducedMotion = prefersReducedMotion();
  let scenarioIndex = 0;
  let stopped = false;
  let initialProblemIntroAvailable = true;

  const queueTimer = (callback, delay) => {
    const timer = window.setTimeout(() => {
      timers.delete(timer);
      callback();
    }, delay);
    timers.add(timer);
  };

  const runSolutionSteps = () => {
    if (root.dataset.demoMode === "solution") {
      setStep(root, 3);
      return;
    }

    if (reducedMotion) {
      applyMode(root, "solution");
      setStep(root, 3);
      return;
    }

    updateModeButtons(root, "solution");
    root.classList.add("is-solving");

    queueTimer(() => {
      applyMode(root, "solution");

      [0, 1, 2, 3].forEach((step) => {
        queueTimer(() => setStep(root, step), step * STEP_DELAY);
      });
    }, SOLVE_POP_DELAY);
  };

  const showScenario = (index, autoplay = false) => {
    const nextButton = scenarioButtons[index];
    if (!nextButton) {
      return;
    }

    scenarioIndex = index;
    clearTimers(timers);
    applyScenario(root, nextButton);
    applyPane(root, root.dataset.demoPane || "home");
    const shouldUseProblemIntro = initialProblemIntroAvailable && index === 0;

    if (shouldUseProblemIntro) {
      initialProblemIntroAvailable = false;
      applyMode(root, "problem");
      setStep(root, 0);
    } else {
      applyMode(root, "solution");
      setStep(root, 3);
    }

    if (reducedMotion) {
      applyMode(root, "solution");
      setStep(root, 3);
      return;
    }

    if (shouldUseProblemIntro) {
      queueTimer(runSolutionSteps, SOLUTION_DELAY);
    }
  };

  const scheduleAutoplay = () => {
    if (reducedMotion || stopped) {
      return;
    }

    queueTimer(() => {
      showScenario((scenarioIndex + 1) % scenarioButtons.length, true);
      scheduleAutoplay();
    }, AUTOPLAY_DELAY);
  };

  scenarioButtons.forEach((button, index) => {
    button.addEventListener("click", () => {
      stopped = true;
      showScenario(index);
    });
  });

  modeButtons.forEach((button) => {
    button.addEventListener("click", () => {
      stopped = true;
      clearTimers(timers);
      if (button.dataset.productDemoMode === "solution") {
        runSolutionSteps();
      } else {
        applyMode(root, "problem");
        setStep(root, 0);
      }
    });
  });

  paneButtons.forEach((button) => {
    button.addEventListener("click", () => {
      stopped = true;
      applyPane(root, button.dataset.productDemoPane || "home");
    });
  });

  if (budSearchButton) {
    budSearchButton.addEventListener("click", () => {
      stopped = true;

      const scenario = scenarioButtons[scenarioIndex];
      const pane = root.dataset.demoPane || "home";
      const customer = scenario?.dataset.demoCustomer || "this business";
      const type = scenario?.dataset.demoType || "small business";
      const paneLabel = pane.charAt(0).toUpperCase() + pane.slice(1);

      document.dispatchEvent(new CustomEvent("everbranch:bud-open", {
        detail: {
          prompt: `How could Everbranch help with ${paneLabel.toLowerCase()} for ${customer} (${type})?`,
          source: "product_demo_search",
          context: {
            scenario: scenario?.dataset.productDemoScenario || "retail",
            customer,
            type,
            pane,
          },
        },
      }));
    });
  }

  root.addEventListener("pointerenter", () => {
    stopped = true;
  });

  applyPane(root, "home");
  showScenario(0, true);
  scheduleAutoplay();
}

export function mountPublicProductDemoNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProductDemoNow();
