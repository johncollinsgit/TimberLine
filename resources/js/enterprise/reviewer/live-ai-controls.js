/* Reusable DOM controls for the same Ask composer; all content is rendered as text, never HTML. */
(() => {
    'use strict';
    function mount({document, host, form, question, submit, log, adapter, context, request, openSource, persistReceipt, savedReceipt}) {
        const node = (tag, text, parent, className = '') => {
            const value = document.createElement(tag); value.textContent = text; value.className = className; parent?.append(value); return value;
        };
        host.classList.add('proposal-ai-controls');
        node('p', 'AI can explain this tab’s fictional work after you review the fields. It cannot change work, send messages or access real campus data.', host);
        const allLabel = node('label', '', host), all = document.createElement('input');
        all.type = 'checkbox'; allLabel.append(all, document.createTextNode(' Include all departments visible in this sample perspective'));
        const status = node('p', '', host); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        const recover = node('button', 'Check saved request status', host, 'button'); recover.type = 'button'; recover.hidden = true;
        const cancelSaved = node('button', 'Cancel unsubmitted preview', host, 'button'); cancelSaved.type = 'button'; cancelSaved.hidden = true;
        const resolve = node('button', 'Close settled request and review current work', host, 'button'); resolve.type = 'button'; resolve.hidden = true;
        const dialog = document.createElement('dialog'); dialog.className = 'proposal-ai-preview';
        const title = node('h2', 'Review fields before sending', dialog); title.id = 'proposal-ai-preview-title-' + crypto.randomUUID(); dialog.setAttribute('aria-labelledby', title.id);
        const previewQuestion = node('p', '', dialog); const allowance = node('p', '', dialog);
        const fields = node('div', '', dialog); fields.className = 'proposal-ai-fields';
        const actions = node('div', '', dialog, 'actions');
        const send = node('button', 'Send once to OpenAI', actions, 'button primary'); send.type = 'button';
        const cancel = node('button', 'Keep editing', actions, 'button'); cancel.type = 'button';
        host.append(dialog);
        let lastShownPreview = null, lastShownAnswer = null, disposed = false;
        const ownedAnswers = new Set();
        const scope = () => ({...context(), all_departments: all.checked});
        const amount = value => '$' + (value / 1000000).toFixed(6);
        const client = globalThis.EverbranchProposalAiClient.create({adapter, context: scope, request, persistReceipt,
            changed(state) {
                if (disposed) return;
                const busy = ['preparing', 'checking', 'sending', 'recovering', 'cancelling'].includes(state.phase);
                question.readOnly = !!state.pending || busy;
                submit.disabled = !!state.pending || busy;
                submit.textContent = state.phase === 'preparing' ? 'Checking current fields…' : 'Review fields for AI';
                all.disabled = !!state.pending || busy;
                recover.hidden = !state.pending; recover.disabled = busy;
                resolve.hidden = !['stale', 'settled_expired'].includes(state.phase); resolve.disabled = busy;
                cancelSaved.hidden = state.phase !== 'prepared'; cancelSaved.disabled = busy;
                send.disabled = state.phase !== 'preview';
                status.textContent = state.error || ({preparing:'Preparing the exact sharing preview. No model request yet.',preview:'Review the question and fields. Send only if you want to share them with OpenAI.',checking:'Checking that the preview still matches this tab.',sending:'Waiting for the saved request. Closing or navigating does not cancel its cost.',recovering:'Checking saved status without sending another model request.',cancelling:'Cancelling only an unclaimed preview. This cannot release a paid or uncertain request.',accepted:'AI answer saved for these exact fictional fields.',rejected:'The saved request was rejected.'})[state.phase] || '';
                if (state.phase === 'preview' && state.preview?.id !== lastShownPreview) {
                    lastShownPreview = state.preview.id;
                    previewQuestion.textContent = 'Question: ' + state.question;
                    allowance.textContent = `Maximum held before this send: ${amount(state.preview.maximum_microusd)}, within the shared $5 lifetime trial allowance. This is a maximum reservation, not a new allowance per reviewer or session.`;
                    fields.replaceChildren(globalThis.EverbranchProposalAi.preview(document, state.snapshot));
                    if (!dialog.open) dialog.showModal(); cancel.focus();
                } else if (state.phase !== 'preview' && dialog.open) dialog.close();
                if (state.result && state.result.request_id !== lastShownAnswer) {
                    lastShownAnswer = state.result.request_id;
                    const answer = node('article', '', log, 'chat-turn proposal-ai-answer');
                    ownedAnswers.add(answer);
                    node('p', 'You asked: ' + state.question, answer, 'proposal-ai-question');
                    node('p', state.result.provider_simulated ? 'Simulated model response · local testing' : 'AI-generated text · fictional browser records', answer, 'caption');
                    node('p', state.result.answer, answer);
                    for (const ref of state.result.refs) {
                        const source = state.snapshot.sources.find(row => row.ref === ref);
                        const button = node('button', 'Inspect fictional source: ' + source.title, answer, 'button'); button.type = 'button';
                        button.onclick = async () => { if (await client.source(ref)) openSource(ref); };
                    }
                    node('p', 'This answer uses the fields you shared, not verified campus facts. Inspect current work before acting.', answer, 'caption');
                }
                if (!state.result) { for (const value of ownedAnswers) value.remove(); ownedAnswers.clear(); }
            },
        });
        const previousSubmit = form.onsubmit;
        const onSubmit = event => { event.preventDefault(); client.prepare(question.value); };
        form.onsubmit = onSubmit;
        send.onclick = () => client.confirm();
        cancel.onclick = () => { client.invalidate(); question.focus(); };
        dialog.addEventListener('cancel', event => { event.preventDefault(); client.invalidate(); question.focus(); });
        recover.onclick = () => client.recover(); resolve.onclick = () => {client.acknowledgeSettled(); question.focus();};
        cancelSaved.onclick = () => client.cancelPrepared();
        all.onchange = () => client.invalidate();
        if (savedReceipt) client.resume(savedReceipt);
        return Object.freeze({client, invalidate: () => client.invalidate(), destroy() {
            if (disposed) return;
            disposed = true; client.invalidate();
            for (const value of ownedAnswers) value.remove(); ownedAnswers.clear();
            dialog.close(); if (form.onsubmit === onSubmit) form.onsubmit = previousSubmit; host.replaceChildren();
        }});
    }
    globalThis.EverbranchProposalAiControls = Object.freeze({mount});
})();
