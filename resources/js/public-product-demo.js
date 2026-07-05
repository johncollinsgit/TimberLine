const ROOT_SELECTOR = "[data-public-product-demo]";
const SCENARIO_SELECTOR = "[data-product-demo-scenario]";
const STAGE_PANEL_SELECTOR = "[data-demo-stage-panel]";
const START_SOLUTION_SELECTOR = "[data-demo-start-solution]";
const BACK_SELECTOR = "[data-demo-back-categories]";
const PANE_SELECTOR = "[data-product-demo-pane]";
const PANE_PANEL_SELECTOR = "[data-product-demo-pane-panel]";
const APP_BUBBLE_SELECTOR = ".fb-product-demo__app-bubble";
const STORY_SLIDE_SELECTOR = "[data-demo-story-slide]";
const SLIDE_DOT_SELECTOR = "[data-demo-slide-dot]";
const SLIDE_PREV_SELECTOR = "[data-demo-slide-prev]";
const SLIDE_NEXT_SELECTOR = "[data-demo-slide-next]";
const SHOW_SOLUTION_SELECTOR = "[data-demo-show-solution]";

const SOLVE_DELAY = 760;

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

function updateMobile(root, trigger) {
  const lines = `${trigger.dataset.demoMobile || ""}`
    .split("|")
    .map((line) => line.trim())
    .filter(Boolean);

  root.querySelectorAll("[data-product-demo-mobile-line]").forEach((element) => {
    const index = Number.parseInt(element.dataset.productDemoMobileLine || "0", 10);
    element.textContent = lines[index] || "";
    element.hidden = !lines[index];
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
  };

  Object.entries(fields).forEach(([key, value]) => {
    if (value) {
      updateText(root, `[data-product-demo-field="${key}"]`, value);
    }
  });

  updateStats(root, trigger);
  updateLinks(root, trigger);
  updateJobs(root, trigger);
  updateMobile(root, trigger);

  root.querySelectorAll(SCENARIO_SELECTOR).forEach((button) => {
    const active = button === trigger;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-pressed", active ? "true" : "false");
  });

  root.classList.add("is-changing");
  window.setTimeout(() => root.classList.remove("is-changing"), 280);
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

function showStage(root, stage) {
  root.dataset.demoStage = stage;

  root.querySelectorAll(STAGE_PANEL_SELECTOR).forEach((panel) => {
    panel.hidden = panel.dataset.demoStagePanel !== stage;
  });
}

function showProblem(root) {
  root.classList.remove("is-solving", "has-solved");
  root.dataset.demoMode = "problem";
  showStage(root, "problem");
}

function setStorySlide(root, index) {
  const slides = Array.from(root.querySelectorAll(STORY_SLIDE_SELECTOR));
  const dots = Array.from(root.querySelectorAll(SLIDE_DOT_SELECTOR));
  const prevButton = root.querySelector(SLIDE_PREV_SELECTOR);
  const nextButton = root.querySelector(SLIDE_NEXT_SELECTOR);
  const showSolutionButton = root.querySelector(SHOW_SOLUTION_SELECTOR);
  const maxIndex = Math.max(slides.length - 1, 0);
  const activeIndex = Math.max(0, Math.min(index, maxIndex));

  root.dataset.demoStorySlide = `${activeIndex}`;

  slides.forEach((slide, slideIndex) => {
    const active = slideIndex === activeIndex;
    slide.hidden = !active;
    slide.classList.toggle("is-active", active);
  });

  dots.forEach((dot, dotIndex) => {
    const active = dotIndex === activeIndex;
    dot.classList.toggle("is-active", active);
    dot.setAttribute("aria-current", active ? "step" : "false");
  });

  if (prevButton) {
    prevButton.disabled = activeIndex === 0;
  }

  if (nextButton) {
    nextButton.hidden = activeIndex === maxIndex;
  }

  if (showSolutionButton) {
    showSolutionButton.hidden = activeIndex !== maxIndex;
  }
}

function showExplainer(root) {
  root.classList.remove("is-solving");
  root.classList.add("has-solved");
  root.dataset.demoMode = "explainer";
  setStorySlide(root, 0);
  showStage(root, "explainer");
}

function setAppCollapseOffsets(root) {
  const stage = root.querySelector('[data-demo-stage-panel="problem"]');
  const centerMark = root.querySelector(".fb-product-demo__center-mark");

  if (!stage || !centerMark) {
    return;
  }

  const centerRect = centerMark.getBoundingClientRect();
  const centerX = centerRect.left + centerRect.width / 2;
  const centerY = centerRect.top + centerRect.height / 2;

  root.querySelectorAll(APP_BUBBLE_SELECTOR).forEach((bubble) => {
    const rect = bubble.getBoundingClientRect();
    const bubbleX = rect.left + rect.width / 2;
    const bubbleY = rect.top + rect.height / 2;

    bubble.style.setProperty("--collapse-x", `${centerX - bubbleX}px`);
    bubble.style.setProperty("--collapse-y", `${centerY - bubbleY}px`);
  });
}

function showExplainerAfterCollapse(root, reducedMotion) {
  root.dataset.demoMode = "problem";

  if (reducedMotion) {
    showExplainer(root);
    return;
  }

  setAppCollapseOffsets(root);
  root.classList.add("is-solving");
  window.setTimeout(() => {
    showExplainer(root);
  }, SOLVE_DELAY);
}

function showSolution(root) {
  root.classList.remove("is-solving");
  root.classList.add("has-solved");
  root.dataset.demoMode = "solution";
  showStage(root, "solution");
}

function mountRoot(root) {
  if (root.dataset.productDemoMounted === "true") {
    return;
  }

  root.dataset.productDemoMounted = "true";

  const scenarioButtons = Array.from(root.querySelectorAll(SCENARIO_SELECTOR));
  const startSolutionButton = root.querySelector(START_SOLUTION_SELECTOR);
  const backButton = root.querySelector(BACK_SELECTOR);
  const slidePrevButton = root.querySelector(SLIDE_PREV_SELECTOR);
  const slideNextButton = root.querySelector(SLIDE_NEXT_SELECTOR);
  const showSolutionButton = root.querySelector(SHOW_SOLUTION_SELECTOR);
  const reducedMotion = prefersReducedMotion();

  scenarioButtons.forEach((button) => {
    button.addEventListener("click", () => {
      applyScenario(root, button);
      applyPane(root, "home");
      showProblem(root);
    });
  });

  root.querySelectorAll(PANE_SELECTOR).forEach((button) => {
    button.addEventListener("click", () => {
      applyPane(root, button.dataset.productDemoPane || "home");
    });
  });

  startSolutionButton?.addEventListener("click", () => {
    showExplainerAfterCollapse(root, reducedMotion);
  });

  slidePrevButton?.addEventListener("click", () => {
    const current = Number.parseInt(root.dataset.demoStorySlide || "0", 10);
    setStorySlide(root, current - 1);
  });

  slideNextButton?.addEventListener("click", () => {
    const current = Number.parseInt(root.dataset.demoStorySlide || "0", 10);
    setStorySlide(root, current + 1);
  });

  showSolutionButton?.addEventListener("click", () => {
    showSolution(root);
  });

  root.querySelectorAll(SLIDE_DOT_SELECTOR).forEach((dot) => {
    dot.addEventListener("click", () => {
      setStorySlide(root, Number.parseInt(dot.dataset.demoSlideDot || "0", 10));
    });
  });

  backButton?.addEventListener("click", () => {
    root.classList.remove("is-solving", "has-solved");
    root.dataset.demoMode = "problem";
    showStage(root, "choose");
  });

  if (scenarioButtons[0]) {
    applyScenario(root, scenarioButtons[0]);
  }

  applyPane(root, "home");
  setStorySlide(root, 0);
  showStage(root, "choose");
}

export function mountPublicProductDemoNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicProductDemoNow();
