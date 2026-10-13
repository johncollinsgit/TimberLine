const form = document.querySelector('[data-consultation-form]');
if (form) {
    const steps = [...form.querySelectorAll('[data-brief-step]')];
    const next = form.querySelector('[data-step-next]');
    const back = form.querySelector('[data-step-back]');
    const submit = form.querySelector('[data-brief-submit]');
    const progress = form.querySelector('[data-form-progress]');
    const submitLabel = submit.innerHTML;
    const labels = ['Your business', 'What to improve', 'A little context'];
    let step = 0;
    const show = (index, focus = true) => {
        step = index;
        steps.forEach((section, i) => { section.hidden = i !== step; });
        next.hidden = step === steps.length - 1;
        back.hidden = step === 0;
        submit.hidden = step !== steps.length - 1;
        progress.hidden = false;
        form.querySelector('[data-step-label]').textContent = `${step + 1} of 3 · ${labels[step]}`;
        form.querySelector('[data-progress-fill]').style.width = `${((step + 1) / 3) * 100}%`;
        if (focus) {
            const legend = steps[step].querySelector('legend');
            legend.tabIndex = -1;
            legend.focus({ preventScroll: true });
            form.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
        }
    };
    const valid = index => {
        for (const control of steps[index].querySelectorAll('input, select, textarea')) {
            if (!control.checkValidity()) { control.reportValidity(); return false; }
        }
        if (index === 1) {
            const missing = !steps[1].querySelector('input[type="checkbox"]:checked');
            form.querySelector('[data-goal-error]').hidden = !missing;
            if (missing) { steps[1].querySelector('input[type="checkbox"]').focus(); return false; }
        }
        return true;
    };
    next.addEventListener('click', () => { if (valid(step)) show(step + 1); });
    back.addEventListener('click', () => show(step - 1));
    form.noValidate = true;
    form.addEventListener('submit', event => {
        if (step < steps.length - 1) {
            event.preventDefault();
            if (valid(step)) show(step + 1);
            return;
        }
        for (let i = 0; i < steps.length; i++) {
            show(i, false);
            if (!valid(i)) { event.preventDefault(); return; }
        }
        submit.disabled = true;
        submit.textContent = 'Saving your brief…';
    });
    window.addEventListener('pageshow', () => { submit.disabled = false; submit.innerHTML = submitLabel; });
    show(0, false);
}
