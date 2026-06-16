function openDialog(dialog) {
  if (!dialog) return;
  if (typeof dialog.showModal === "function" && !dialog.open) {
    dialog.showModal();
    return;
  }
  if (dialog.open) return;

  dialog.setAttribute("open", "");
  dialog.dataset.dialogFallbackOpen = "1";
  dialog.style.position = "fixed";
  dialog.style.inset = "50% auto auto 50%";
  dialog.style.transform = "translate(-50%, -50%)";
  dialog.style.zIndex = "60";
}

function closeDialog(dialog) {
  if (!dialog) return;
  if (typeof dialog.close === "function" && dialog.open && dialog.dataset.dialogFallbackOpen !== "1") {
    dialog.close();
    return;
  }

  if (dialog.open) {
    dialog.removeAttribute("open");
    delete dialog.dataset.dialogFallbackOpen;
    dialog.removeAttribute("style");
  }
}

export function mountOnboardingGateNow() {
  const root = document.querySelector("[data-onboarding-gate-root]");
  if (!root || root.__mfOnboardingGateMounted) return;
  root.__mfOnboardingGateMounted = true;

  const dialog = root.querySelector("[data-onboarding-modal]");
  const openTriggers = Array.from(root.querySelectorAll("[data-open-onboarding-modal]"));
  const closeTriggers = Array.from(root.querySelectorAll("[data-close-onboarding-modal]"));
  const shouldAutoOpen = root.dataset.onboardingModalOpen === "1";

  const open = () => openDialog(dialog);
  const close = () => closeDialog(dialog);

  openTriggers.forEach((trigger) => {
    trigger.addEventListener("click", open);
  });

  closeTriggers.forEach((trigger) => {
    trigger.addEventListener("click", close);
  });

  if (dialog) {
    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) {
        close();
      }
    });
  }

  if (shouldAutoOpen) {
    window.requestAnimationFrame(() => {
      open();
    });
  }
}
