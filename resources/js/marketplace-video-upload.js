const CHUNK = 1024 * 1024;
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

async function digest(value) {
    const bytes = await crypto.subtle.digest('SHA-256', value);
    return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
}

async function uploadRequest(url, csrf, fields, retryNotice, method = 'POST') {
    let lastError;
    for (let attempt = 0; attempt < 4; attempt++) {
        let delay = 500 * (2 ** attempt);
        try {
            const form = new FormData();
            for (const [key, value] of Object.entries(fields)) {
                if (value instanceof Blob) form.append(key, value, 'part.bin');
                else if (value !== null && value !== undefined) form.append(key, String(value));
            }
            const response = await fetch(url, {method, body: form, credentials: 'same-origin',
                headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}});
            const data = await response.json().catch(() => ({}));
            if (response.ok) return data;
            const message = Object.values(data.errors || {}).flat()[0] || data.message || 'The upload could not continue.';
            const error = Object.assign(new Error(message), {status: response.status});
            if (![408, 423, 425, 429].includes(response.status) && response.status < 500) throw error;
            lastError = error;
            const retryAfter = Number(response.headers.get('Retry-After'));
            if (retryAfter > 0) delay = Math.max(delay, Math.min(retryAfter, 60) * 1000);
        } catch (error) {
            if (error.status) throw error;
            lastError = error;
        }
        if (attempt < 3) {
            retryNotice();
            await wait(delay);
        }
    }
    throw lastError || Error('The connection was interrupted.');
}

function getRemembered(key) {
    try { return sessionStorage.getItem(key); } catch { return null; }
}

function remember(key, value) {
    try { if (value) sessionStorage.setItem(key, value); else sessionStorage.removeItem(key); } catch { /* Private browsing may disable storage. */ }
}

export function attachVideoUpload(editor) {
    const file = editor.querySelector('[data-video-file]');
    const tokenInput = editor.elements.source_upload_id;
    const panel = editor.querySelector('[data-upload-panel]');
    if (!panel || !tokenInput) return;
    const bar = panel.querySelector('progress');
    const notice = panel.querySelector('[role="status"]');
    const retry = panel.querySelector('[data-retry-upload]');
    const base = editor.dataset.uploadUrl;
    const csrf = editor.elements._token.value;
    let busy = false;
    let stagedSubmit = false;
    let lastButton;
    let prepared;
    let activeToken = tokenInput.value;
    let activeKey;
    const inputRequired = file.required || (!editor.dataset.videoId && Boolean(activeToken));

    function display(text, progress) {
        panel.hidden = false;
        notice.textContent = text;
        if (Number.isFinite(progress)) bar.value = progress;
        else bar.removeAttribute('value');
    }

    function discard() {
        if (activeToken) {
            // The server also expires abandoned uploads, including closed tabs.
            fetch(base + '/' + activeToken, {method: 'DELETE', credentials: 'same-origin',
                headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}}).catch(() => {});
        }
        if (activeKey) remember(activeKey, null);
        activeToken = activeKey = prepared = null;
        tokenInput.value = '';
        file.required = inputRequired;
        retry.hidden = true;
        panel.hidden = true;
    }

    file.addEventListener('change', discard);
    retry.addEventListener('click', () => editor.requestSubmit(lastButton || editor.querySelector('button[name="action"]')));

    editor.addEventListener('submit', async event => {
        if (event.defaultPrevented || stagedSubmit) return;
        if (busy) { event.preventDefault(); return; }
        const source = file.files[0];
        // Native form uploads remain usable for small files and existing drafts.
        if (!source || source.size <= CHUNK) return;
        event.preventDefault();
        if (!crypto.subtle || !crypto.randomUUID) {
            display('Open this editor over HTTPS in an up-to-date browser to upload large videos.', 0);
            return;
        }
        if (source.size > Number(editor.dataset.maxMb) * CHUNK) {
            display('The video exceeds the configured upload limit.', 0);
            return;
        }
        busy = true;
        const frozen = [...editor.querySelectorAll('button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled)')];
        const restore = () => frozen.forEach(control => { control.disabled = false; });
        frozen.forEach(control => { control.disabled = true; });
        retry.hidden = true;
        lastButton = event.submitter;
        try {
            if (!prepared || prepared.file !== source) {
                const sums = [];
                for (let offset = 0; offset < source.size; offset += CHUNK) {
                    display('Checking video before upload…', Math.round(offset / source.size * 100));
                    sums.push(await digest(await source.slice(offset, offset + CHUNK).arrayBuffer()));
                }
                const fingerprint = await digest(new TextEncoder().encode(sums.join('')));
                prepared = {file: source, sums, fingerprint};
            }
            const key = ['maker-video-v1', base, editor.dataset.videoId || 'new', editor.elements.revision.value, prepared.fingerprint].join(':');
            if (activeKey !== key || !activeToken) {
                activeKey = key;
                activeToken = getRemembered(key) || crypto.randomUUID();
                remember(key, activeToken);
            }
            const url = base + '/' + activeToken;
            const retrying = () => display('Connection interrupted. Retrying the current request…', bar.value);
            const info = await uploadRequest(url, csrf, {size: source.size, fingerprint: prepared.fingerprint,
                video_id: editor.dataset.videoId || null, revision: editor.elements.revision.value}, retrying);
            if (info.chunk_bytes !== CHUNK || !Number.isInteger(info.next_index) || info.next_index < 0 || info.next_index > prepared.sums.length) {
                throw Error('The server returned an invalid upload position.');
            }
            for (let index = info.next_index; index < prepared.sums.length; index++) {
                const part = source.slice(index * CHUNK, (index + 1) * CHUNK);
                const percent = Math.floor(index * CHUNK / source.size * 100);
                display('Uploading video · ' + percent + '%', percent);
                await uploadRequest(url + '/parts/' + index, csrf, {part, checksum: prepared.sums[index]}, retrying);
            }
            display('Verifying completed video…', 100);
            await uploadRequest(url + '/finish', csrf, {}, retrying);
            tokenInput.value = activeToken;
            restore();
            file.required = false;
            file.disabled = true;
            remember(activeKey, null);
            stagedSubmit = true;
            display('Video verified. Saving your edits…', 100);
            // Native navigation preserves session validation messages, clicked action and redirect.
            editor.requestSubmit(lastButton || editor.querySelector('button[name="action"]'));
            stagedSubmit = false;
            // If a submit handler or constraint prevented navigation, leave the form usable.
            file.disabled = false;
        } catch (error) {
            if ([404, 409, 410, 422].includes(error.status)) {
                discard();
            }
            display((error.message || 'Upload interrupted.') + ' Select Retry upload to continue.', bar.value);
            retry.hidden = false;
        } finally {
            stagedSubmit = false;
            restore();
            busy = false;
        }
    });
}
