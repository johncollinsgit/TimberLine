(() => {
    const dialog = document.getElementById('ask-everbranch');
    if (!dialog) return;
    const form = dialog.querySelector('form');
    const input = form.querySelector('textarea');
    const thread = dialog.querySelector('[role="log"]');
    const status = dialog.querySelector('.eb-ask-status');
    const transcript = [];
    const conversationId = globalThis.crypto?.randomUUID?.() || `visitor-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    let busy = false;
    function add(role, text) {
        const item = document.createElement('p');
        item.dataset.role = role;
        item.textContent = text;
        thread.append(item);
        thread.scrollTop = thread.scrollHeight;
        transcript.push({ role, text });
    }
    document.querySelectorAll('[data-ask-everbranch-open]').forEach(button => button.addEventListener('click', () => {
        dialog.showModal();
        input.focus();
    }));
    dialog.querySelector('[data-ask-everbranch-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) {
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        }
    });
    dialog.querySelectorAll('[data-ask-question]').forEach(button => button.addEventListener('click', () => {
        input.value = button.dataset.askQuestion;
        form.requestSubmit();
    }));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const question = input.value.trim();
        if (busy || !question) return;
        busy = true;
        add('user', question);
        status.textContent = 'Finding your next step…';
        form.querySelector('button').disabled = true;
        dialog.querySelectorAll('[data-ask-question]').forEach(button => button.disabled = true);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(dialog.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ conversation_id: conversationId, source_page: 'everbranch_public_help', page_url: location.origin + location.pathname, question, transcript: transcript.slice(-12), context: { source: 'public_help', surface: 'public' } })
            });
            if (!response.ok) throw new Error('unavailable');
            const result = await response.json();
            if (typeof result.reply !== 'string' || !result.reply) throw new Error('unavailable');
            add('assistant', result.reply);
            input.value = '';
            status.textContent = '';
        } catch {
            status.textContent = 'Help is taking a moment. Try again, or choose Contact a person below.';
        } finally {
            clearTimeout(timeout);
            busy = false;
            form.querySelector('button').disabled = false;
            dialog.querySelectorAll('[data-ask-question]').forEach(button => button.disabled = false);
        }
    });
})();
