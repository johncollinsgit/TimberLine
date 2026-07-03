const ROOT_SELECTOR = "[data-public-product-demo]";
const SCENARIO_SELECTOR = "[data-product-demo-scenario]";
const MODE_SELECTOR = "[data-product-demo-mode]";
const STEP_SELECTOR = "[data-product-demo-step]";
const PROBLEM_ITEM_SELECTOR = "[data-product-demo-problem-item]";

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
  const timers = new Set();
  const reducedMotion = prefersReducedMotion();
  let scenarioIndex = 0;
  let stopped = false;

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
    applyMode(root, "problem");
    setStep(root, 0);

    if (reducedMotion) {
      runSolutionSteps();
      return;
    }

    queueTimer(runSolutionSteps, SOLUTION_DELAY);
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

  root.addEventListener("pointerenter", () => {
    stopped = true;
  });

  showScenario(0, true);
  scheduleAutoplay();
}

export function mountPublicProductDemoNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProductDemoNow();
