// The browser reads the selected export locally. Only the chosen video is attached to the form.
export function parseStudioPackage(manifest) {
    if (manifest?.schema_version !== 1 || !Array.isArray(manifest.assets) || manifest.assets.length > 500) {
        throw Error('Choose a version 1 Everbranch Studio export.');
    }
    if (manifest.approval !== 'approved' || manifest.visibility !== 'public') {
        throw Error('Approve a public export in Everbranch Studio before importing it into Watch.');
    }
    const paths = new Set();
    for (const asset of manifest.assets) {
        if (!asset || typeof asset.path !== 'string' || !/^[\w.-]+$/.test(asset.path) || /^\.+$/.test(asset.path)
            || paths.has(asset.path) || !/^[a-f\d]{64}$/i.test(asset.sha256) || !Number.isSafeInteger(asset.bytes) || asset.bytes < 1) {
            throw Error('An asset in this export has invalid or duplicate file information.');
        }
        paths.add(asset.path);
    }
    return manifest.assets.filter(asset => ['episode_video', 'short', 'trailer'].includes(asset.kind) && asset.mime_type === 'video/mp4');
}

export async function verifyStudioAsset(asset, files, maxBytes) {
    const file = files.get(asset.path);
    if (!file || file.size !== asset.bytes || file.size > maxBytes) throw Error('Missing, oversized or changed file: ' + asset.path);
    const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
    const actual = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
    if (actual !== asset.sha256.toLowerCase()) throw Error('File verification failed: ' + asset.path);
    return file;
}

export function attachStudioImporter(editor) {
    if (!window.crypto?.subtle || !window.DataTransfer) return;
    const panel = editor.querySelector('[data-package-panel]');
    const folder = panel.querySelector('[data-package-files]');
    const choice = panel.querySelector('[data-package-choice]');
    const button = panel.querySelector('[data-package-apply]');
    const message = panel.querySelector('[role="status"]');
    let manifest, choices, files;
    panel.hidden = false;
    folder.addEventListener('change', async () => {
        button.disabled = true;
        choice.replaceChildren();
        message.textContent = '';
        try {
            const selected = [...folder.files];
            const manifests = selected.filter(f => f.name === 'studio-package.json');
            if (manifests.length !== 1 || manifests[0].size > 2_000_000) throw Error('Select one exported revision folder containing studio-package.json.');
            const root = manifests[0].webkitRelativePath.split('/').slice(0, -1).join('/');
            files = new Map(selected.filter(f => f.webkitRelativePath.split('/').slice(0, -1).join('/') === root).map(f => [f.name, f]));
            manifest = JSON.parse(await manifests[0].text());
            choices = parseStudioPackage(manifest);
            if (!choices.length) throw Error('No Watch-compatible video is listed in this export.');
            choices.forEach(asset => choice.add(new Option(asset.path, asset.path)));
            button.disabled = false;
        } catch (error) { message.textContent = error.message; }
    });

    button.addEventListener('click', async () => {
        button.disabled = folder.disabled = choice.disabled = true;
        try {
            message.textContent = 'Verifying the selected video and captions…';
            const asset = choices.find(item => item.path === choice.value);
            const file = await verifyStudioAsset(asset, files, Number(editor.dataset.maxMb) * 1024 * 1024);
            const captionAsset = manifest.assets.find(item => item.kind === 'captions' && item.video === asset.path);
            const captions = captionAsset ? await (await verifyStudioAsset(captionAsset, files, 1_000_000)).text() : '';
            const clip = (manifest.clips ?? []).find(item => item.path === asset.path);
            const transfer = new DataTransfer();
            transfer.items.add(file);
            editor.elements.source.files = transfer.files;
            editor.elements.source.dispatchEvent(new Event('change'));
            editor.elements.source_sha256.value = asset.sha256;
            editor.elements.title.value = String(manifest.title ?? '').slice(0, 160);
            editor.elements.description.value = String(manifest.description ?? '').slice(0, 5000);
            editor.elements.captions.value = captions;
            editor.elements.trim_start.value = '0';
            editor.elements.trim_end.value = '';
            editor.elements.format.value = asset.kind === 'episode_video' ? 'long' : 'short';
            const list = editor.querySelector('[data-tag-list]');
            for (const field of list.querySelectorAll('select,input')) field.value = '';
            const suggestions = clip ? clip.product_tags : manifest.product_tags;
            const unmatched = [];
            let next = 0;
            for (const suggestion of Array.isArray(suggestions) ? suggestions : []) {
                const id = String(suggestion.variant_id ?? '');
                const label = String(suggestion.label ?? id);
                if (next === 12 || !id) { unmatched.push(label); continue; }
                const matches = [...list.querySelector('select').options].filter(o => o.value && (o.value === id || o.value.split(':').at(-1) === id));
                if (matches.length !== 1) { unmatched.push(label); continue; }
                if (next === list.children.length) editor.querySelector('[data-add-tag]').click();
                const fields = list.children[next].querySelectorAll('select,input');
                if ([...list.querySelectorAll('select')].some(select => select.value === matches[0].value)) continue;
                fields[0].value = matches[0].value;
                fields[1].value = Math.max(0, Math.floor(Number(suggestion.start) || 0));
                fields[2].value = Number.isFinite(suggestion.end) ? Math.ceil(suggestion.end) : '';
                next++;
            }
            message.textContent = 'Verified export imported. Review the captions and products before submission.'
                + (unmatched.length ? ' Match these products manually: ' + unmatched.join(', ') : '');
        } catch (error) { message.textContent = error.message; }
        finally { button.disabled = folder.disabled = choice.disabled = false; }
    });
}
