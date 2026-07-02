const ROOT_SELECTOR = "[data-public-product-demo]";
const SCENARIO_SELECTOR = "[data-product-demo-scenario]";
const MODE_SELECTOR = "[data-product-demo-mode]";
const STEP_SELECTOR = "[data-product-demo-step]";
const JUMP_SELECTOR = "[data-product-demo-jump]";

const AUTOPLAY_DELAY = 12000;
const SOLUTION_DELAY = 2600;
const STEP_DELAY = 1050;

function prefersReducedMotion() {
  return window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches ?? false;
}

function updateText(root, selector, value) {
  if (!value) {
    return;
  }

  root.querySelectorAll(selector).forEach((element) => {
    element.textContent = value;
  });
}

function applyScenario(root, trigger) {
  const fields = {
    context: trigger.dataset.demoContext,
    customer: trigger.dataset.demoCustomer,
    label: trigger.dataset.demoLabel,
    money: trigger.dataset.demoMoney,
    note: trigger.dataset.demoNote,
    owner: trigger.dataset.demoOwner,
    primary: trigger.dataset.demoPrimary,
    problem: trigger.dataset.demoProblem,
    profit: trigger.dataset.demoProfit,
    solution: trigger.dataset.demoSolution,
    task: trigger.dataset.demoTask,
    title: trigger.dataset.demoTitle,
  };

  Object.entries(fields).forEach(([key, value]) => {
    updateText(root, `[data-product-demo-field="${key}"]`, value);
  });

  updateText(root, '[data-product-demo-feed="one"]', trigger.dataset.demoFeedOne);
  updateText(root, '[data-product-demo-feed="two"]', trigger.dataset.demoFeedTwo);
  updateText(root, '[data-product-demo-feed="three"]', trigger.dataset.demoFeedThree);

  root.dataset.demoScenario = trigger.dataset.productDemoScenario || "";

  root.querySelectorAll(SCENARIO_SELECTOR).forEach((button) => {
    const active = button === trigger;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-selected", active ? "true" : "false");
  });

  root.classList.add("is-changing");
  window.setTimeout(() => root.classList.remove("is-changing"), 420);
}

function applyMode(root, mode) {
  root.dataset.demoMode = mode;

  root.querySelectorAll(MODE_SELECTOR).forEach((button) => {
    const active = button.dataset.productDemoMode === mode;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-selected", active ? "true" : "false");
  });

  root.classList.toggle("is-solving", mode === "solution");
}

function setStep(root, activeStep) {
  root.dataset.activeStep = String(activeStep);

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
  let autoplayStopped = false;

  const queueTimer = (callback, delay) => {
    const timer = window.setTimeout(() => {
      timers.delete(timer);
      callback();
    }, delay);
    timers.add(timer);
  };

  const runSolutionSteps = () => {
    applyMode(root, "solution");

    if (reducedMotion) {
      setStep(root, 3);
      return;
    }

    [0, 1, 2, 3].forEach((step) => {
      queueTimer(() => setStep(root, step), step * STEP_DELAY);
    });
  };

  const showScenario = (index, shouldAutoplay = false) => {
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

    queueTimer(runSolutionSteps, shouldAutoplay ? SOLUTION_DELAY : 900);
  };

  const scheduleAutoplay = () => {
    if (reducedMotion || autoplayStopped || scenarioButtons.length < 2) {
      return;
    }

    queueTimer(() => {
      showScenario((scenarioIndex + 1) % scenarioButtons.length, true);
      scheduleAutoplay();
    }, AUTOPLAY_DELAY);
  };

  scenarioButtons.forEach((button, index) => {
    button.addEventListener("click", () => {
      autoplayStopped = true;
      showScenario(index);
    });
  });

  modeButtons.forEach((button) => {
    button.addEventListener("click", () => {
      autoplayStopped = true;
      clearTimers(timers);
      const mode = button.dataset.productDemoMode || "solution";
      applyMode(root, mode);
      setStep(root, mode === "solution" ? 3 : 0);
    });
  });

  root.addEventListener("pointerenter", () => {
    autoplayStopped = true;
  });

  showScenario(0, true);
  scheduleAutoplay();
}

function mountJumps() {
  document.querySelectorAll(JUMP_SELECTOR).forEach((link) => {
    if (link.dataset.productDemoJumpMounted === "true") {
      return;
    }

    link.dataset.productDemoJumpMounted = "true";
    link.addEventListener("click", (event) => {
      const target = document.querySelector("#product-theater");
      const demoTab = document.querySelector('[data-public-tab-trigger="demo"]');

      if (demoTab instanceof HTMLElement) {
        event.preventDefault();
        demoTab.click();
      }

      if (target instanceof HTMLElement) {
        event.preventDefault();
        window.setTimeout(() => {
          target.scrollIntoView({ behavior: prefersReducedMotion() ? "auto" : "smooth", block: "start" });
        }, 80);
      }
    });
  });
}

export function mountPublicProductDemoNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
  mountJumps();
}

mountPublicProductDemoNow();
