/* Private reviewer AI orchestration. The injected request is same-origin only; this module has no provider URL/key. */
(() => {
    'use strict';
    const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
    const hash = /^[a-f0-9]{64}$/;
    const copy = value => value == null ? value : JSON.parse(JSON.stringify(value));
    function create({adapter, context, request, changed = () => {}, persistReceipt = () => {}}) {
        const projection = globalThis.EverbranchProposalAi;
        let generation = 0, settledReceipt = null;
        let state = {phase: 'idle', question: '', snapshot: null, preview: null, result: null, pending: null, error: ''};
        const emit = () => changed(copy(state));
        const scope = () => {
            const value = context();
            if (!value || !uuid.test(value.area_id) || typeof value.all_departments !== 'boolean'
                || !value.binding || Object.keys(value.binding).sort().join() !== 'build_id,source_hash,version_hash'
                || !hash.test(value.binding.version_hash) || !hash.test(value.binding.source_hash)
                || typeof value.binding.build_id !== 'string' || !value.binding.build_id) throw Error('Open the current private proposal demo first.');
            return copy(value);
        };
        const sameScope = (left, right) => projection.canonical(left) === projection.canonical(right);
        const owns = receipt => !!receipt && !!state.pending && sameScope(receipt, state.pending);
        const activeSnapshot = async current => projection.capture(adapter, current.area_id, current.all_departments);
        const matches = async (current, snapshot, ownGeneration) => {
            if (generation !== ownGeneration || !sameScope(current, scope())) return false;
            const fresh = await activeSnapshot(current);
            return generation === ownGeneration && sameScope(current, scope()) && projection.stamp(fresh) === projection.stamp(snapshot);
        };
        const failure = message => { state.error = message; emit(); };
        const clearReceipt = () => {
            try { return persistReceipt(null) !== false; } catch { return false; }
        };
        const cleanupWarning = 'The settled request receipt could not be removed from browser storage. Its saved status remains recoverable; this does not send another model request.';
        const validatePreview = (value, snapshot, question, requestId, recovering = false) => {
            if (!value || !uuid.test(value.id) || !hash.test(value.digest) || !hash.test(value.snapshot_digest)
                || value.request_id !== requestId || value.question !== question
                || projection.canonical(value.snapshot) !== projection.canonical(snapshot)
                || !Number.isSafeInteger(value.maximum_microusd) || value.maximum_microusd < 1 || value.maximum_microusd > 5000000
                || !Number.isSafeInteger(value.expires_at) || !recovering && value.expires_at * 1000 <= Date.now()) throw Error('The exact sharing preview could not be verified. Nothing was sent to OpenAI.');
        };
        const validateResult = (value, identityOnly = false) => {
            if (!value || value.preview_id !== state.pending.preview_id || value.request_id !== state.pending.request_id
                || value.digest !== state.pending.digest
                || value.snapshot_digest !== state.pending.snapshot_digest
                || !['prepared', 'claimed', 'accepted', 'rejected', 'uncertain', 'dispatching', 'reserved', 'cancelled'].includes(value.status)
                || value.content_expired !== undefined && typeof value.content_expired !== 'boolean') throw Error('The saved request outcome could not be verified. Check its status; do not send again.');
            if (value.content_expired === true) {
                if (Object.keys(value).sort().join() !== 'content_expired,digest,preview_id,request_id,snapshot_digest,status') throw Error('Expired content must remain withheld.');
                return;
            }
            if (identityOnly) return;
            if (value.status !== 'accepted') return;
            if (value.provider_mode !== 'openai_generated' || value.provider_dispatch !== true || typeof value.provider_simulated !== 'boolean') throw Error('The saved response did not identify its generation mode.');
            const refs = new Set(state.snapshot.sources.map(source => source.ref));
            if (typeof value.answer !== 'string' || !value.answer.trim() || value.answer.length > 1500
                || /[<>]|https?:\/\//iu.test(value.answer) || !Array.isArray(value.refs) || value.refs.length > 5
                || new Set(value.refs).size !== value.refs.length || value.refs.some(ref => !refs.has(ref))) throw Error('The saved answer did not match its reviewed fictional sources. Check the saved request status.');
        };
        async function prepare(question) {
            if (state.pending || ['preparing', 'checking', 'sending', 'recovering'].includes(state.phase)) return failure('Resolve the retained request before preparing another paid question.');
            if (typeof question !== 'string' || !(question = question.trim()) || question.length > 1500) return failure('Enter a question of up to 1,500 characters.');
            const ownGeneration = ++generation;
            state = {...state, phase: 'preparing', question, preview: null, result: null, error: ''}; emit();
            try {
                const current = scope(), snapshot = await activeSnapshot(current);
                if (!snapshot.sources.length) throw Error('No fictional work is visible in this scope. Choose a department with visible records.');
                if (!await matches(current, snapshot, ownGeneration)) throw Error('The fictional records or perspective changed. Review a fresh snapshot.');
                const requestId = crypto.randomUUID();
                const preview = await request('preview', {question, snapshot, request_id: requestId, binding: current.binding});
                if (!await matches(current, snapshot, ownGeneration)) throw Error('The fictional records or perspective changed. Review a fresh snapshot.');
                validatePreview(preview, snapshot, question, requestId);
                state = {...state, phase: 'preview', snapshot, preview: {...copy(preview), context: current}, error: ''}; emit();
            } catch (error) {
                if (ownGeneration !== generation) return;
                state.phase = 'idle'; state.preview = null; failure(error.message || 'The sharing preview is unavailable. Nothing was sent to OpenAI.');
            }
        }
        async function applyOutcome(value, current, snapshot, ownGeneration, receipt) {
            if (!owns(receipt)) return;
            validateResult(value);
            if (value.content_expired === true || value.status === 'cancelled') {
                state.result = null;
                const settled = ['accepted', 'rejected', 'cancelled'].includes(value.status);
                settledReceipt = settled ? copy(receipt) : null;
                state.phase = settled ? 'settled_expired' : value.status === 'prepared' ? 'prepared' : 'uncertain';
                state.error = settled
                    ? 'The saved request is closed. Its private sharing content is unavailable; any recorded cost remains unchanged. Acknowledge it before another question.'
                    : value.status === 'prepared'
                        ? 'This preview was not claimed. Cancel the saved preview before preparing a new question.'
                        : 'The private content expired, but this request is still pending or uncertain. Its maximum cost remains held; check saved status, do not send again.';
                emit(); return;
            }
            if (value.status === 'prepared' || value.status === 'claimed') {
                state.phase = value.status === 'prepared' ? 'prepared' : 'uncertain'; state.result = null;
                state.error = value.status === 'prepared'
                    ? 'This preview was not claimed. Cancel the saved preview before preparing a new question.'
                    : 'The saved request was claimed. It cannot be resent or released here; check its saved status.';
                emit(); return;
            }
            const stillCurrent = await matches(current, snapshot, ownGeneration);
            if (!owns(receipt)) return;
            if (!stillCurrent) {
                const settled = value.status === 'accepted' || value.status === 'rejected';
                settledReceipt = settled ? copy(receipt) : null;
                state.phase = settled ? 'stale' : 'uncertain';
                state.result = null;
                state.error = 'The perspective or fictional records changed. This request may have incurred cost; its old answer is withheld. Check the saved status before another send.';
                emit(); return;
            }
            if (value.status === 'accepted' || value.status === 'rejected') {
                state.phase = value.status; state.result = value.status === 'accepted' ? copy(value) : null;
                state.pending = null; settledReceipt = null;
                const cleared = clearReceipt();
                state.error = [value.status === 'rejected' ? 'The saved request was rejected. No answer is available.' : '', cleared ? '' : cleanupWarning].filter(Boolean).join(' ');
            } else {
                state.phase = 'uncertain'; state.result = null;
                state.error = 'The request is pending or uncertain. Its maximum cost remains held. Check saved status; do not send again.';
            }
            emit();
        }
        async function confirm() {
            if (state.phase !== 'preview' || !state.preview || state.pending) return;
            const ownGeneration = generation, current = state.preview.context, snapshot = state.snapshot;
            let receipt = null;
            state.phase = 'checking'; state.error = ''; emit();
            try {
                const stillCurrent = await matches(current, snapshot, ownGeneration);
                if (generation !== ownGeneration) return;
                if (state.preview.expires_at * 1000 <= Date.now() || !stillCurrent) {
                    state.phase = 'idle'; state.preview = null; return failure('The preview expired or the fictional records changed. Review the fields again before sending.');
                }
                const pending = {preview_id: state.preview.id, digest: state.preview.digest,
                    snapshot_digest: state.preview.snapshot_digest, request_id: state.preview.request_id, binding: current.binding};
                try {
                    if (persistReceipt(copy(pending)) === false) throw Error('Receipt storage unavailable.');
                } catch {
                    state.pending = null; state.phase = 'idle'; state.preview = null; state.snapshot = null;
                    return failure('Your browser could not retain the request receipt. Nothing was sent to OpenAI. Your question remains editable.');
                }
                state.pending = pending;
                receipt = copy(pending);
                state.phase = 'sending'; emit();
                const value = await request('confirm', copy(receipt));
                await applyOutcome(value, current, snapshot, ownGeneration, receipt);
            } catch (error) {
                if (receipt ? !owns(receipt) : generation !== ownGeneration) return;
                if (!state.pending) { state.phase = 'idle'; state.preview = null; return failure(error.message); }
                state.phase = 'uncertain'; state.result = null;
                failure('The outcome is unknown. The same request receipt is retained; check saved status instead of sending again.');
            }
        }
        async function recover() {
            if (!state.pending || ['sending', 'recovering', 'cancelling'].includes(state.phase)) return;
            const ownGeneration = generation, receipt = copy(state.pending);
            state.phase = 'recovering'; state.error = ''; emit();
            try {
                const current = scope();
                const value = await request('status', copy(receipt));
                if (!owns(receipt)) return;
                validateResult(value, true);
                if (value.content_expired === true || value.status === 'cancelled') {
                    return await applyOutcome(value, current, null, ownGeneration, receipt);
                }
                if (!state.preview || !state.snapshot) {
                    const preview = value?.preview;
                    validatePreview(preview, preview?.snapshot, preview?.question, state.pending.request_id, true);
                    if (preview.id !== state.pending.preview_id || preview.digest !== state.pending.digest
                        || preview.snapshot_digest !== state.pending.snapshot_digest || typeof preview.question !== 'string'
                        || !preview.question.trim() || preview.question.length > 1500) throw Error('Saved preview receipt changed.');
                    state.snapshot = copy(preview.snapshot); state.question = preview.question;
                    state.preview = {...copy(preview), context: current};
                }
                await applyOutcome(value, state.preview.context, state.snapshot, ownGeneration, receipt);
            } catch (error) {
                if (!owns(receipt)) return;
                state.phase = 'uncertain'; state.result = null;
                failure('Saved status is unavailable. The retained request cannot be resent automatically.');
            }
        }
        async function cancelPrepared() {
            if (!state.pending || state.phase !== 'prepared') return;
            const ownGeneration = generation, receipt = copy(state.pending);
            state.phase = 'cancelling'; state.error = ''; emit();
            try {
                const value = await request('cancel', copy(receipt));
                if (!owns(receipt)) return;
                validateResult(value);
                if (value.status !== 'cancelled' || value.content_expired !== true) throw Error('Cancellation was not confirmed.');
                await applyOutcome(value, scope(), null, ownGeneration, receipt);
            } catch {
                if (!owns(receipt)) return;
                state.phase = 'uncertain'; state.result = null;
                failure('The saved preview could not be confirmed cancelled. Retain its receipt and check status; do not send again.');
            }
        }
        function resume(receipt) {
            if (state.pending || ['preparing', 'checking', 'sending', 'recovering'].includes(state.phase)) return;
            try {
                if (!receipt || Object.keys(receipt).sort().join() !== 'binding,digest,preview_id,request_id,snapshot_digest'
                    || !uuid.test(receipt.preview_id) || !uuid.test(receipt.request_id) || !hash.test(receipt.digest)
                    || !hash.test(receipt.snapshot_digest) || !sameScope(receipt.binding, scope().binding)) throw Error('The saved request does not belong to this proposal build.');
                state = {...state, phase: 'uncertain', pending: copy(receipt), preview: null, snapshot: null, result: null,
                    error: 'A request receipt is retained. Check its saved status before sending another question.'};
                emit();
            } catch (error) { failure(error.message); }
        }
        async function source(ref) {
            if (state.phase !== 'accepted' || !state.result?.refs.includes(ref)) return null;
            const ownGeneration = generation;
            const current = await matches(state.preview.context, state.snapshot, ownGeneration);
            if (generation !== ownGeneration) return null;
            if (!current) { invalidate(); return null; }
            return copy(state.snapshot.sources.find(value => value.ref === ref) || null);
        }
        function acknowledgeSettled() {
            if (!owns(settledReceipt) || !['stale', 'settled_expired'].includes(state.phase)) return;
            generation++; settledReceipt = null;
            state = {...state, phase: 'idle', snapshot: null, preview: null, result: null, pending: null,
                error: 'The settled old request was closed. Review current fields before a new send.'};
            if (!clearReceipt()) state.error += ' ' + cleanupWarning;
            emit();
        }
        function invalidate() {
            generation++;
            state.result = null;
            if (state.pending) {
                state.phase = 'uncertain'; state.error = 'Context changed. Retain the saved request for status recovery; no old answer is shown.';
            } else {
                state.phase = 'idle'; state.preview = null; state.error = '';
            }
            emit();
        }
        emit();
        return Object.freeze({prepare, confirm, recover, resume, invalidate, cancelPrepared, acknowledgeSettled, source, read: () => copy(state)});
    }
    globalThis.EverbranchProposalAiClient = Object.freeze({create});
})();
