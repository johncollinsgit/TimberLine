/* Shared detail file controls. No inline handlers, browser byte persistence, scanner invocation or native grant. */
(() => {
    'use strict';
    const root = globalThis, instances = new WeakMap(), plain = v => JSON.parse(JSON.stringify(v));
    function mount({ host, uploads, images = null, downloads = null, workspace, dialog, getReviewerName, getCurrentTarget, onOpenTarget = () => { }, onAccessLoss = () => { } }) {
        if (!host || !uploads || workspace?.mode !== 'shared' || typeof getReviewerName !== 'function' || typeof getCurrentTarget !== 'function')
            throw Error('Private file controls require the shared workspace.');
        if (instances.has(host)) {
            const old = instances.get(host);
            if (old.uploads !== uploads || old.images !== images || old.downloads !== downloads)
                throw Error('Reuse the current private file controls.');
            return old.api;
        }
        const doc = host.ownerDocument, q = s => host.querySelector(s), listeners = [], downloadListeners = [], same = (a, b) => a && b && a.kind === b.kind && a.id === b.id;
        const nodes = { choose: q('[data-work-files-choose]'), photoChoose: q('[data-work-photo-choose]'), input: q('[data-work-files-input]'), photo: q('[data-work-photo-input]'), upload: q('[data-work-files-upload]'), selected: q('[data-work-files-selected]'), pending: q('[data-work-files-pending]'), pendingLabel: q('[data-work-files-pending-label]'), open: q('[data-work-files-open-original]'), recover: q('[data-work-files-recover]'), retry: q('[data-work-files-retry]'), resume: q('[data-work-files-resume]'), cancel: q('[data-work-files-cancel]'), status: q('[data-work-files-status]'), list: q('[data-work-files-list]'), refresh: q('[data-work-files-refresh]') };
        if (Object.values(nodes).some(n => !n))
            throw Error('Private file controls are incomplete.');
        nodes.choose.textContent = downloads ? 'Add file' : 'Add image file';
        nodes.input.accept = downloads ? 'image/jpeg,image/png,application/pdf' : 'image/jpeg,image/png';
        const hint = q('[data-work-files-hint]');
        if (hint) hint.textContent = downloads ? 'JPEG, PNG or PDF, up to 5 MiB. Uploads are held until scanning.' : 'JPEG or PNG images, up to 5 MiB. Other file types are unavailable.';
        let disposed = false, busy = false, generation = 0, readSerial = 0, target = null, version = null, selectedFile = null, view = null, gallery = null;
        const listen = (n, e, f) => { n.addEventListener(e, f); listeners.push(() => n.removeEventListener(e, f)); };
        const valid = epoch => !disposed && epoch === generation && host.isConnected && same(target, getCurrentTarget());
        function clearFile() { selectedFile = null; nodes.input.value = ''; nodes.photo.value = ''; nodes.selected.textContent = ''; }
        function clearDownloads() { downloadListeners.splice(0).forEach(f => f()); downloads?.retire(); }
        function retire() { generation++; readSerial++; gallery?.destroy(); gallery = null; images?.retire(); clearDownloads(); view = null; nodes.list.replaceChildren(); }
        function controls() {
            const pending = uploads.pending(), original = pending && same(pending.command.target, target), name = getReviewerName(), can = !!view?.can_upload && valid(generation) && typeof name === 'string' && name.length > 0;
            nodes.input.disabled = busy || !can;
            nodes.photo.disabled = busy || !can;
            nodes.choose.disabled = busy || !can;
            nodes.photoChoose.disabled = busy || !can;
            nodes.upload.disabled = busy || !can || !selectedFile || !!pending;
            nodes.pending.hidden = !pending;
            nodes.open.hidden = !pending || original;
            nodes.pendingLabel.textContent = pending ? `Pending: ${pending.command.name} · as ${pending.command.expected_reviewer_name}` : '';
            nodes.recover.disabled = busy || !original;
            nodes.retry.disabled = busy || !original || !can;
            nodes.resume.disabled = busy || !original || !can || !selectedFile;
            nodes.cancel.disabled = busy || !original;
            nodes.refresh.disabled = busy;
            nodes.open.disabled = busy;
        }
        function message(text) { nodes.status.textContent = text; }
        function renderFiles(files) {
            const fragment = doc.createDocumentFragment();
            for (const file of files) {
                const row = doc.createElement('li');
                row.dataset.discussionFile = file.id;
                const title = doc.createElement('span');
                title.textContent = file.name;
                row.append(title);
                const state = doc.createElement('span');
                state.dataset.fileState = file.id;
                state.textContent = file.mime === 'application/pdf' ? (downloads ? ' · Held for scanning' : ' · PDF retrieval unavailable') : file.status === 'quarantined' ? ' · Held for scanning' : file.status === 'uploading' || file.status === 'assembling' ? ' · Upload incomplete' : ' · Unavailable';
                row.append(state);
                fragment.append(row);
            }
            nodes.list.replaceChildren(fragment);
        }
        async function refresh() {
            if (!target || disposed)
                return;
            const epoch = generation, serial = ++readSerial, t = plain(target);
            gallery?.destroy();
            gallery = null;
            images?.retire();
            clearDownloads();
            try {
                const fresh = await uploads.show(t);
                if (!valid(epoch) || serial !== readSerial)
                    return;
                if (fresh.target_version !== version) {
                    view = null;
                    nodes.list.replaceChildren();
                    message('Work changed. Refresh the workspace before uploading.');
                    controls();
                    return;
                }
                view = fresh;
                renderFiles(fresh.files);
                message(fresh.files.length ? '' : 'No files yet.');
                controls();
                if (images && dialog) {
                    try {
                        const mounted = await images.mount({ host: nodes.list, dialog, target: t, current: () => valid(epoch) && serial === readSerial, onDenied: () => {
                                if (!valid(epoch) || serial !== readSerial)
                                    return;
                                gallery?.destroy();
                                gallery = null;
                                nodes.list.replaceChildren();
                                view = null;
                                message('Photo access changed. Refresh current work.');
                                controls();
                                onAccessLoss();
                            }, onExpired: () => {
                                if (!valid(epoch) || serial !== readSerial)
                                    return;
                                gallery = null;
                                nodes.list.querySelectorAll('[data-file-state]').forEach(n => { if (n.textContent === ' · Ready')
                                    n.textContent = ' · Scan expired'; });
                                message('Photo scan expired. Refresh files.');
                                controls();
                            } });
                        if (!valid(epoch) || serial !== readSerial) {
                            mounted.destroy();
                            return;
                        }
                        gallery = mounted;
                        for (const file of gallery.files) {
                            if (file.state === 'ready') {
                                const badge = [...nodes.list.querySelectorAll('[data-file-state]')].find(n => n.dataset.fileState === file.id);
                                if (badge)
                                    badge.textContent = ' · Ready';
                            }
                        }
                    }
                    catch (e) {
                        if (!valid(epoch) || serial !== readSerial)
                            return;
                        images.retire();
                        gallery = null;
                        if ([403, 404, 410, 419].includes(e.status)) {
                            nodes.list.replaceChildren();
                            view = null;
                            message('File access changed. Refresh current work.');
                            controls();
                            onAccessLoss();
                        }
                    }
                }
                if (downloads && valid(epoch) && serial === readSerial) await refreshDownloads(fresh, t, epoch, serial);
            }
            catch {
                if (valid(epoch) && serial === readSerial) {
                    view = null;
                    nodes.list.replaceChildren();
                    message('Files unavailable. Keep any pending upload and refresh current work.');
                    controls();
                    onAccessLoss();
                }
            }
        }
        async function refreshDownloads(fresh, t, epoch, serial) {
            try {
                const result = await downloads.metadata(t);
                if (!valid(epoch) || serial !== readSerial) return;
                if (result.target_version !== version) throw Error('The PDF target changed.');
                for (const file of result.files) {
                    const original = fresh.files.find(row => row.id === file.id);
                    if (!original || original.mime !== file.mime || original.name !== file.name || original.size !== file.size || original.sha256 !== file.original_sha256) throw Error('The PDF metadata changed.');
                    const row = [...nodes.list.querySelectorAll('[data-discussion-file]')].find(n => n.dataset.discussionFile === file.id);
                    if (!row) continue;
                    const state = [...row.querySelectorAll('[data-file-state]')].find(n => n.dataset.fileState === file.id);
                    if (state) state.textContent = file.state === 'ready' ? ' · Ready to download' : file.state === 'rejected' ? ' · Rejected' : ' · Held for scanning';
                    if (!downloads.eligible(file)) continue;
                    const button = doc.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'Download PDF'; button.setAttribute('aria-label', `Download ${file.name}`);
                    row.append(button);
                    const downloadClick = async () => {
                        if (!valid(epoch) || serial !== readSerial || !downloads.eligible(file)) return;
                        button.disabled = true;
                        try {
                            await downloads.download(file);
                            if (valid(epoch) && serial === readSerial) message('Download requested.');
                        } catch (e) {
                            if (!valid(epoch) || serial !== readSerial) return;
                            message('PDF download unavailable. Refresh files.');
                            if ([403, 404, 410, 419].includes(e.status)) { gallery?.destroy(); gallery = null; images?.retire(); clearDownloads(); nodes.list.replaceChildren(); view = null; controls(); onAccessLoss(); }
                        } finally { if (valid(epoch) && serial === readSerial) button.disabled = !downloads.eligible(file); }
                    };
                    button.addEventListener('click', downloadClick);
                    downloadListeners.push(() => { button.removeEventListener('click', downloadClick); button.remove(); });
                }
            } catch (e) {
                if (!valid(epoch) || serial !== readSerial) return;
                clearDownloads();
                nodes.list.querySelectorAll('[data-file-state]').forEach(n => { const file = fresh.files.find(f => f.id === n.dataset.fileState); if (file?.mime === 'application/pdf') n.textContent = ' · PDF retrieval unavailable'; });
                if ([403, 404, 410, 419].includes(e.status)) { gallery?.destroy(); gallery = null; images?.retire(); nodes.list.replaceChildren(); view = null; controls(); onAccessLoss(); }
            }
        }
        async function operation(action) {
            if (busy || !target || disposed)
                return;
            const epoch = generation;
            busy = true;
            controls();
            try {
                await action(epoch);
            }
            catch (e) {
                if (valid(epoch))
                    message(e.outcome === 'unknown' ? 'Upload status unknown. Recover the original upload.' : 'Upload not started. Check the selected file, reviewer name and current work.');
            }
            finally {
                busy = false;
                if (valid(epoch))
                    controls();
            }
        }
        function choose(input) {
            if (!input.files?.[0])
                return;
            const file = input.files[0];
            if (!(downloads ? ['image/jpeg', 'image/png', 'application/pdf'] : ['image/jpeg', 'image/png']).includes(file.type) || file.size < 1 || file.size > 5242880) {
                clearFile();
                message(downloads ? 'Choose a JPEG, PNG or PDF up to 5 MiB.' : 'Choose a JPEG or PNG image up to 5 MiB.');
                controls();
                return;
            }
            selectedFile = file;
            nodes.selected.textContent = `${file.name} · ${(file.size / 1024).toFixed(1)} KiB · as ${getReviewerName() || 'choose a reviewer name'}`;
            message('');
            controls();
        }
        listen(nodes.choose, 'click', () => nodes.input.click());
        listen(nodes.photoChoose, 'click', () => nodes.photo.click());
        listen(nodes.input, 'change', () => choose(nodes.input));
        listen(nodes.photo, 'change', () => choose(nodes.photo));
        listen(nodes.refresh, 'click', refresh);
        listen(nodes.upload, 'click', () => operation(async (epoch) => {
            if (!selectedFile || uploads.pending())
                return;
            const name = getReviewerName(), file = selectedFile, fresh = await uploads.show(plain(target));
            if (!valid(epoch))
                return;
            if (!fresh.can_upload || fresh.target_version !== version || typeof name !== 'string' || name.length === 0) {
                message('Check the current work and reviewer name.');
                return;
            }
            const prepared = await uploads.prepare(fresh, file, { request_id: root.crypto.randomUUID(), expected_reviewer_name: name });
            if (!valid(epoch))
                return;
            const begun = await uploads.begin(prepared);
            if (!valid(epoch) || begun.stale_context)
                return;
            message('Uploading…');
            const result = await uploads.resume(prepared, file);
            if (!valid(epoch) || result.stale_context)
                return;
            if (result.status === 'quarantined') {
                clearFile();
                message('Uploaded · Held for scanning');
                await refresh();
            }
        }));
        listen(nodes.recover, 'click', () => operation(async (epoch) => {
            const out = await uploads.recover();
            if (!valid(epoch) || out.stale_context)
                return;
            message(out.status === 'not_observed' ? 'Reservation not observed. Retry the same reservation.' : out.status === 'quarantined' ? 'Uploaded · Held for scanning' : 'Original upload recovered. Reselect the same file to resume.');
            await refresh();
        }));
        listen(nodes.retry, 'click', () => operation(async (epoch) => {
            const pending = uploads.pending();
            if (!pending || !same(pending.command.target, target))
                return;
            const out = await uploads.begin(pending);
            if (!valid(epoch) || out.stale_context)
                return;
            message('Original reservation recovered. Resume the same selected file.');
            await refresh();
        }));
        listen(nodes.resume, 'click', () => operation(async (epoch) => {
            const pending = uploads.pending();
            if (!pending || !selectedFile || !same(pending.command.target, target))
                return;
            const out = await uploads.recover();
            if (!valid(epoch) || out.stale_context)
                return;
            if (out.status === 'not_observed') {
                message('Retry the original reservation before resuming.');
                return;
            }
            if (out.settled) {
                clearFile();
                await refresh();
                return;
            }
            const done = await uploads.resume(pending, selectedFile);
            if (!valid(epoch) || done.stale_context)
                return;
            if (done.status === 'quarantined')
                clearFile();
            message('Uploaded · Held for scanning');
            await refresh();
        }));
        listen(nodes.cancel, 'click', () => operation(async (epoch) => {
            const out = await uploads.recover();
            if (!valid(epoch) || out.stale_context)
                return;
            if (out.status === 'not_observed') {
                message('The reservation is unresolved. Keep the original upload for recovery.');
                return;
            }
            if (out.settled) {
                clearFile();
                await refresh();
                return;
            }
            const done = await uploads.cancel();
            if (!valid(epoch) || done.stale_context)
                return;
            clearFile();
            message('Upload cancelled.');
            await refresh();
        }));
        listen(nodes.open, 'click', () => {
            const pending = uploads.pending();
            if (pending)
                onOpenTarget(plain(pending.command.target));
        });
        let snapshot = JSON.stringify(workspace.snapshot());
        const unsubscribe = workspace.subscribe(() => {
            const next = JSON.stringify(workspace.snapshot());
            if (next !== snapshot) {
                snapshot = next;
                retire();
                clearFile();
                message('Work context changed. Refresh files.');
                controls();
            }
        });
        const api = Object.freeze({ async setTarget(t, expectedVersion) {
                if (disposed)
                    return;
                if (same(target, t) && version === expectedVersion)
                    return;
                if (!same(target, t) || version !== expectedVersion) {
                    retire();
                    clearFile();
                    target = plain(t);
                    version = expectedVersion;
                }
                host.hidden = false;
                controls();
                await refresh();
            }, refresh, refreshName() {
                if (selectedFile)
                    nodes.selected.textContent = `${selectedFile.name} · as ${getReviewerName() || 'choose a reviewer name'}`;
                controls();
            }, destroy() {
                if (disposed)
                    return;
                disposed = true;
                retire();
                clearFile();
                unsubscribe();
                listeners.forEach(f => f());
                host.hidden = true;
                instances.delete(host);
            } });
        instances.set(host, { uploads, images, downloads, api });
        return api;
    }
    root.EverbranchWorkspaceFileUi = Object.freeze({ mount });
})();
