export function attachSocialImporter(editor) {
    const panel = editor.querySelector('[data-social-import]');
    if (!panel) return;
    const status = panel.querySelector('[data-social-status]');
    const source = editor.querySelector('[data-video-file]');
    const copy = panel.querySelector('[data-social-file]');
    const browse = panel.querySelector('[data-social-connect]');
    const title = editor.elements.title;
    const maxBytes = Number(editor.dataset.maxMb) * 1048576;
    function selectFile(file, clip = null) {
        if (!file || file.size > maxBytes || !file.size) throw new Error('Choose a video within the upload limit.');
        const files = new DataTransfer();
        files.items.add(file);
        source.files = files.files;
        source.dispatchEvent(new Event('change', {bubbles: true}));
        if (!title.value.trim()) title.value = clip?.title || file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').slice(0, 160);
        if (clip) {
            editor.elements.source_post_url.value = clip.url || '';
            if (!editor.elements.description.value) editor.elements.description.value = clip.description || '';
        }
        status.textContent = 'Ready to preview, trim and tag. Save the draft when you’re ready.';
    }
    copy.addEventListener('change', () => {
        try { selectFile(copy.files[0]); } catch (error) { status.textContent = error.message; }
    });
    if (!browse) return;
    const accounts = panel.querySelector('[data-social-accounts]');
    const clips = panel.querySelector('[data-social-clips]');
    const choices = panel.querySelector('[data-social-choices]');
    const load = panel.querySelector('[data-social-load]');
    const accountUrl = panel.dataset.accounts;
    const base = accountUrl.slice(0, accountUrl.lastIndexOf('/'));
    const request = async (url, method = 'GET') => {
        const response = await fetch(url, {method, credentials: 'same-origin', headers: {
            Accept: 'application/json', 'X-CSRF-TOKEN': editor.querySelector('[name="_token"]').value,
        }});
        if (!response.ok) throw new Error(response.status === 413 ? 'This clip is too large for direct import. Choose the original file.' : 'Direct import is unavailable for this account. Choose your saved video above.');
        return response;
    };
    let rows = [];
    let epoch = 0;
    const changes = async () => {
        const version = ++epoch;
        rows = []; clips.replaceChildren(); load.disabled = true;
        try {
            const items = await (await request(base + '/' + accounts.value)).json();
            if (version !== epoch) return;
            rows = items;
            clips.replaceChildren(...rows.map((row, i) => new Option(row.title, String(i))));
            load.disabled = !rows.length;
            status.textContent = rows.length ? 'Select a video to preview in the editor.' : 'No recent videos available. You can choose your saved video above.';
        } catch (error) { if (epoch === version) status.textContent = error.message; }
    };
    accounts.addEventListener('change', changes);
    browse.hidden = false;
    browse.addEventListener('click', async () => {
        browse.disabled = true;
        try {
            const items = await (await request(accountUrl)).json();
            accounts.replaceChildren(...items.map(row => new Option(row.platform + ' · ' + row.id, row.platform + '/' + row.id)));
            choices.hidden = items.length === 0;
            if (items.length) await changes();
            else status.textContent = 'No connected creator accounts with video access. Use a saved video.';
        } catch (error) { status.textContent = error.message; }
        finally { browse.disabled = false; }
    });
    load.addEventListener('click', async () => {
        const row = rows[Number(clips.value)];
        if (!row) return;
        load.disabled = accounts.disabled = clips.disabled = browse.disabled = true;
        status.textContent = 'Importing your video…';
        try {
            const response = await request(base + '/' + accounts.value + '/' + row.id, 'POST');
            const blob = await response.blob();
            const ext = {'video/mp4':'mp4','video/quicktime':'mov','video/webm':'webm'}[blob.type];
            if (!ext) throw new Error('Use the original MP4, MOV or WebM from your creator account.');
            selectFile(new File([blob], row.id + '.' + ext, {type:blob.type}), row);
        } catch (error) { status.textContent = error.message; }
        finally { load.disabled = accounts.disabled = clips.disabled = browse.disabled = false; }
    });
}
