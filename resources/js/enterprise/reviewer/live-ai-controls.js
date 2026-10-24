/* Reusable DOM controls for the same Ask composer; all content is rendered as text, never HTML. */
(() => {
    'use strict';
    function mount({document, host, form, question, submit, log, adapter, context, request, openSource, persistReceipt, savedReceipt}) {
        const node = (tag, text, parent, className = '') => {
            const value = document.createElement(tag); value.textContent = text; value.className = className; parent?.append(value); return value;
        };
        host.classList.add('proposal-ai-controls');
        node('p', adapter.mode === 'shared'
            ? 'AI can explain the saved shared fictional work after you review its current server fields. It cannot change work, send messages or access real campus data.'
            : 'AI can explain this tab’s fictional work after you review the fields. It cannot change work, send messages or access real campus data.', host);
        const voiceActions = node('div', '', host, 'actions');
        const startVoice = node('button', 'Start voice', voiceActions, 'button'); startVoice.type = 'button'; startVoice.disabled = true;
        const stopVoice = node('button', 'Stop voice', voiceActions, 'button'); stopVoice.type = 'button'; stopVoice.disabled = true;
        const interruptVoice = node('button', 'Interrupt reply', voiceActions, 'button'); interruptVoice.type = 'button'; interruptVoice.disabled = true;
        const voiceStatus = node('p', 'Type a question, or start voice in a supported browser. Each spoken question needs Review and Send. Browser speech recognition may use its speech service.', host);
        voiceStatus.setAttribute('role', 'status');
        let voice = null;
        const allLabel = node('label', '', host), all = document.createElement('input');
        all.type = 'checkbox'; allLabel.append(all, document.createTextNode(' Include all departments visible in this sample perspective'));
        const status = node('p', '', host); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        const recover = node('button', 'Check saved request status', host, 'button'); recover.type = 'button'; recover.hidden = true;
        const cancelSaved = node('button', 'Cancel unsubmitted preview', host, 'button'); cancelSaved.type = 'button'; cancelSaved.hidden = true;
        const resolve = node('button', 'Close settled request and review current work', host, 'button'); resolve.type = 'button'; resolve.hidden = true;
        const dialog = document.createElement('dialog'); dialog.className = 'proposal-ai-preview';
        const title = node('h2', 'Review fields before sending', dialog); title.id = 'proposal-ai-preview-title-' + crypto.randomUUID(); dialog.setAttribute('aria-labelledby', title.id);
        const previewQuestion = node('p', '', dialog); const historyFields = node('div', '', dialog); const allowance = node('p', '', dialog);
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
                voice?.observe(state);
                startVoice.disabled = !voice || !!state.pending || ['preparing','checking','sending','recovering','cancelling'].includes(state.phase);
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
                    historyFields.replaceChildren();
                    if (state.preview.history?.length) {
                        node('h3', 'Previous turns included with this question', historyFields);
                        for (const turn of state.preview.history) {
                            node('p', 'You asked: ' + turn.question, historyFields);
                            node('p', 'Previous AI answer (context only): ' + turn.answer, historyFields);
                            node('p', 'Source references: ' + turn.refs.join(', '), historyFields);
                        }
                    }
                    fields.replaceChildren(globalThis.EverbranchProposalAi.preview(document, state.snapshot));
                    if (!dialog.open) dialog.showModal(); cancel.focus();
                } else if (state.phase !== 'preview' && dialog.open) dialog.close();
                if (state.result && state.result.request_id !== lastShownAnswer) {
                    lastShownAnswer = state.result.request_id;
                    const answer = node('article', '', log, 'chat-turn proposal-ai-answer');
                    ownedAnswers.add(answer);
                    node('p', 'You asked: ' + state.question, answer, 'proposal-ai-question');
                    node('p', state.result.provider_simulated ? 'Simulated model response · local testing' : adapter.mode === 'shared' ? 'AI-generated text · saved shared fictional records' : 'AI-generated text · fictional browser records', answer, 'caption');
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
        const mountVoice = () => {
            if (disposed || voice || !globalThis.EverbranchReviewerVoice) return;
            const Recognition = globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition;
            if (!Recognition || !globalThis.SpeechSynthesisUtterance || !globalThis.speechSynthesis) {
                voiceStatus.textContent = 'Voice is unavailable in this browser. You can type every question instead.'; return;
            }
            voice = globalThis.EverbranchReviewerVoice.create({client, Recognition, Utterance:globalThis.SpeechSynthesisUtterance, synthesis:globalThis.speechSynthesis,
                onState(value) {
                    stopVoice.disabled = value === 'idle'; interruptVoice.disabled = value !== 'speaking';
                    voiceStatus.textContent = ({idle:'Voice stopped. Submitted requests retain their saved status and cost.',starting:'Starting microphone…',listening:'Listening…',thinking:'Review the sharing fields and choose Send once to hear the answer.',speaking:'Reading the verified text answer. The microphone is off.'})[value] || '';
                }, onTranscript(value) { question.value = value; }, onError(message) { voiceStatus.textContent = message; }});
            startVoice.disabled = !!client.read().pending;
        };
        globalThis.addEventListener('everbranch:voice-jobs-ready', mountVoice);
        mountVoice();
        startVoice.onclick = () => voice?.start(); stopVoice.onclick = () => voice?.stop(); interruptVoice.onclick = () => voice?.interrupt();
        const onVisibility = () => { if (document.hidden) voice?.stop(); };
        document.addEventListener('visibilitychange', onVisibility);
        const onSubmit = event => { event.preventDefault(); voice?.stop(); client.prepare(question.value); };
        form.onsubmit = onSubmit;
        send.onclick = () => client.confirm();
        cancel.onclick = () => { voice?.stop(); client.invalidate(); question.focus(); };
        dialog.addEventListener('cancel', event => { event.preventDefault(); voice?.stop(); client.invalidate(); question.focus(); });
        recover.onclick = () => client.recover(); resolve.onclick = () => {client.acknowledgeSettled(); question.focus();};
        cancelSaved.onclick = () => client.cancelPrepared();
        all.onchange = () => { voice?.stop(); client.invalidate(); };
        if (savedReceipt) client.resume(savedReceipt);
        return Object.freeze({client, invalidate: () => { voice?.stop(); client.invalidate(); }, destroy() {
            if (disposed) return;
            disposed = true; voice?.destroy(); client.invalidate();
            globalThis.removeEventListener('everbranch:voice-jobs-ready', mountVoice);
            document.removeEventListener('visibilitychange', onVisibility);
            for (const value of ownedAnswers) value.remove(); ownedAnswers.clear();
            dialog.close(); if (form.onsubmit === onSubmit) form.onsubmit = previousSubmit; host.replaceChildren();
        }});
    }
    globalThis.EverbranchProposalAiControls = Object.freeze({mount});
})();
