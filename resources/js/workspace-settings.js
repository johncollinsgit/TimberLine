import '../css/workspace-settings.css';

function connectSettings() {
    document.querySelector('[data-ws-errors]')?.focus();
    if (!document.querySelector('[data-ws-errors]')) document.querySelector('[data-ws-results]')?.focus();
    const category = document.querySelector('[data-ws-specialties]');
    if (category && !category.dataset.connected) {
        category.dataset.connected = 'true';
        const update = () => {
            const list = document.getElementById('business-specialties');
            const choices = JSON.parse(category.dataset.wsSpecialties)[category.value] || [];
            list?.replaceChildren(...choices.map(value => { const option = document.createElement('option'); option.value = value; return option; }));
        };
        category.addEventListener('change', update);
        update();
    }
    document.querySelectorAll('[data-ws-form]').forEach(form => {
        if (form.dataset.connected) return;
        form.dataset.connected = 'true';
        let dirty = false;
        const beforeUnload = event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } };
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', beforeUnload);
        document.addEventListener('livewire:navigating', () => window.removeEventListener('beforeunload', beforeUnload), { once: true });
    });
}
connectSettings();
document.addEventListener('livewire:navigated', connectSettings);
