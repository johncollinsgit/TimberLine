/* Private clean-ready images only. No provider, scanner or browser persistence. */
(() => {
    'use strict';
    const root = globalThis;
    const imageTypes = new Set(['image/jpeg', 'image/png']);
    const maxBytes = 10 * 1024 * 1024;
    function previewUrl(file, context) {
        if (context.mode !== 'private' || file.status !== 'ready' || file.downloadable !== true || !imageTypes.has(file.mime_type)
            || !Number.isInteger(file.size) || file.size < 1 || file.size > maxBytes || typeof file.preview_url !== 'string') return null;
        try {
            const url = new URL(file.preview_url, context.origin);
            const base = new URL(context.base, context.origin);
            if (base.origin !== context.origin || base.username || base.password || base.search || base.hash) return null;
            const expected = `${base.pathname.replace(/\/$/, '')}/discussion/${encodeURIComponent(context.kind)}/${encodeURIComponent(context.id)}/files/${encodeURIComponent(file.id)}/preview`;
            if (url.origin !== context.origin || url.username || url.password || url.pathname !== expected || url.hash
                || url.searchParams.get('tenant') !== context.tenant || [...url.searchParams.keys()].length !== 1) return null;
            return url.href;
        } catch { return null; }
    }
    async function fetchImage(file, context, signal) {
        const url = previewUrl(file, context);
        if (!url || !context.viewer || !context.workspace) throw new Error('This image is unavailable.');
        const response = await root.fetch(url, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
            headers: {Accept: file.mime_type, 'X-Team-Viewer': String(context.viewer), 'X-Team-Workspace': String(context.workspace)}});
        if (!response.ok) { const error = new Error('This image is no longer available.'); error.status = response.status; throw error; }
        if (response.headers.get('X-Team-Viewer') !== String(context.viewer) || response.headers.get('X-Team-Workspace') !== String(context.workspace)
            || response.headers.get('Content-Type')?.split(';')[0] !== file.mime_type) throw new Error('Image identity or type changed.');
        const blob = await response.blob();
        if (blob.type !== file.mime_type || blob.size !== file.size || blob.size > maxBytes) throw new Error('Image content could not be verified.');
        return blob;
    }
    function mountWithLoader({host, files, context, current, dialog, onDenied = () => {}}, loader) {
        if (!loader || typeof loader.eligible !== 'function' || typeof loader.fetch !== 'function') throw new Error('An authorized image loader is required.');
        const doc = dialog.ownerDocument, images = files.filter(file => loader.eligible(file, context));
        const controllers = new Set(), urls = new Set(), cleanups = [], thumbnails = new Map();
        let disposed = false, suspended = !!doc.hidden, imageEpoch = 0, index = -1, zoom = 1, generation = 0, viewerUrl = null, viewerController = null, opener = null, timer = null, touch = null;
        const image = dialog.querySelector('[data-gallery-image]'), stage = dialog.querySelector('[data-gallery-stage]');
        const status = dialog.querySelector('[data-gallery-status]'), title = dialog.querySelector('[data-gallery-title]');
        const detail = doc.getElementById('detail');
        let scroll = null;
        const live = () => !disposed && !suspended && !doc.hidden && current();
        const listen = (node, name, callback, options) => { node.addEventListener(name, callback, options); cleanups.push(() => node.removeEventListener(name, callback, options)); };
        const revoke = url => { if (url) { root.URL.revokeObjectURL(url); urls.delete(url); } };
        function setZoom(value) {
            if (!live()) return retireInactive();
            zoom = Math.max(1, Math.min(4, value));
            image.style.width = zoom === 1 ? '' : `${zoom * 100}%`;
            image.classList.toggle('is-zoomed', zoom > 1);
            stage.style.touchAction = zoom === 1 ? 'pan-y pinch-zoom' : 'auto';
            dialog.querySelector('[data-gallery-zoom-value]').textContent = `${Math.round(zoom * 100)}%`;
            dialog.querySelector('[data-gallery-zoom-out]').disabled = zoom === 1;
            dialog.querySelector('[data-gallery-zoom-in]').disabled = zoom === 4;
            stage.scrollTo({top: 0, left: 0});
        }
        function close(restore = true) {
            generation++; viewerController?.abort(); root.clearTimeout(timer); timer = null;
            image.removeAttribute('src'); image.hidden = true; revoke(viewerUrl); viewerUrl = null;
            if (dialog.open) dialog.close();
            if (restore && live()) {
                if (detail && scroll) detail.scrollTo({top: scroll.top, left: scroll.left});
                if (opener?.isConnected) opener.focus({preventScroll: true});
            }
            index = -1;
        }
        function destroy() {
            if (disposed) return;
            close(false); disposed = true;
            controllers.forEach(controller => controller.abort()); controllers.clear();
            urls.forEach(url => root.URL.revokeObjectURL(url)); urls.clear();
            thumbnails.forEach(({button}) => button.remove());
            cleanups.splice(0).forEach(cleanup => cleanup());
        }
        function deny(error) {
            destroy(); onDenied(error);
        }
        function suspendImages() {
            if (disposed) return;
            close(false); suspended = true; imageEpoch++;
            controllers.forEach(controller => controller.abort());
            thumbnails.forEach(({thumb}) => thumb.removeAttribute('src'));
            urls.forEach(url => root.URL.revokeObjectURL(url)); urls.clear();
        }
        function retireInactive() {
            if (current() && (doc.hidden || suspended)) suspendImages();
            else destroy();
        }
        function retireHiddenCancellation(error) {
            if (error.name !== 'AbortError' || (!doc.hidden && !suspended) || !current()) return false;
            suspendImages(); return true;
        }
        async function load(file, viewing = false) {
            if (!live()) { const error = new Error('The selected work changed.'); error.name = 'AbortError'; throw error; }
            const controller = new AbortController(), epoch = imageEpoch; controllers.add(controller);
            if (viewing) { viewerController?.abort(); viewerController = controller; }
            try {
                const blob = await loader.fetch(file, context, controller.signal, viewing ? 'preview' : 'thumbnail');
                if (!live() || epoch !== imageEpoch) { const error = new Error('The selected work changed.'); error.name = 'AbortError'; throw error; }
                const url = root.URL.createObjectURL(blob); urls.add(url); return url;
            }
            finally { controllers.delete(controller); if (viewerController === controller) viewerController = null; }
        }
        async function show(next) {
            if (!live()) return retireInactive();
            const ticket = ++generation; viewerController?.abort();
            root.clearTimeout(timer); image.removeAttribute('src'); image.hidden = true; revoke(viewerUrl); viewerUrl = null;
            index = (next + images.length) % images.length;
            const file = images[index];
            title.textContent = file.filename;
            dialog.querySelector('[data-gallery-count]').textContent = `${index + 1} of ${images.length}`;
            dialog.querySelectorAll('[data-gallery-nav]').forEach(button => button.disabled = images.length < 2);
            status.textContent = 'Checking current image access…'; setZoom(1);
            try {
                const url = await load(file, true);
                if (!live() || ticket !== generation || !dialog.open) { revoke(url); return; }
                viewerUrl = url; image.alt = file.caption || file.filename; image.src = url; image.hidden = false; status.textContent = '';
                // A visible image is reauthorized periodically; browser memory is never an access grant.
                timer = root.setTimeout(reauthorize, 30000);
            } catch (error) {
                if (ticket !== generation || disposed || retireHiddenCancellation(error)) return;
                status.textContent = 'Image unavailable. Close this viewer and refresh current work.';
                deny(error);
            }
        }
        async function reauthorize() {
            if (!dialog.open || !live() || index < 0) return;
            const ticket = generation;
            try {
                const url = await load(images[index], true); revoke(url);
                if (ticket === generation && dialog.open && live()) timer = root.setTimeout(reauthorize, 30000);
            } catch (error) { if (ticket === generation && !disposed && !retireHiddenCancellation(error)) deny(error); }
        }
        function open(next, button) {
            if (!live()) return retireInactive();
            opener = button; scroll = detail ? {top: detail.scrollTop, left: detail.scrollLeft} : null;
            if (!dialog.open) dialog.showModal();
            dialog.querySelector('[data-gallery-close]').focus({preventScroll: true});
            show(next);
        }
        const observer = typeof root.IntersectionObserver === 'function' ? new root.IntersectionObserver(entries => {
            entries.filter(entry => entry.isIntersecting).forEach(entry => {
                observer.unobserve(entry.target);
                const item = thumbnails.get(entry.target.dataset.galleryFile);
                if (!item || !live()) return;
                load(item.file).then(url => { if (!live()) return revoke(url); item.thumb.src = url; }).catch(error => { if (!disposed && error.name !== 'AbortError') deny(error); });
            });
        }, {root: detail, rootMargin: '100px'}) : null;
        if (observer) cleanups.push(() => observer.disconnect());
        images.forEach((file, number) => {
            const row = [...host.querySelectorAll('[data-discussion-file]')].find(node => node.dataset.discussionFile === file.id);
            if (!row) return;
            const button = doc.createElement('button'); button.type = 'button'; button.className = 'discussion-image'; button.setAttribute('aria-label', `View photo ${file.filename}`);
            const thumb = doc.createElement('img'); thumb.alt = ''; thumb.decoding = 'async'; thumb.loading = 'lazy'; button.append(thumb);
            const label = doc.createElement('span'); label.textContent = 'View photo'; button.append(label); row.prepend(button);
            button.dataset.galleryFile = file.id; thumbnails.set(file.id, {button, thumb, file});
            listen(button, 'click', () => open(number, button));
            // The URL and scanned bytes remain server-owned; even thumbnails use the page identity fence.
            if (observer) observer.observe(button);
        });
        listen(dialog.querySelector('[data-gallery-close]'), 'click', () => close());
        listen(dialog.querySelector('[data-gallery-prev]'), 'click', () => show(index - 1));
        listen(dialog.querySelector('[data-gallery-next]'), 'click', () => show(index + 1));
        listen(dialog.querySelector('[data-gallery-zoom-in]'), 'click', () => setZoom(zoom + .5));
        listen(dialog.querySelector('[data-gallery-zoom-out]'), 'click', () => setZoom(zoom - .5));
        listen(dialog.querySelector('[data-gallery-fit]'), 'click', () => setZoom(1));
        listen(dialog, 'cancel', event => { event.preventDefault(); event.stopPropagation(); close(); });
        listen(dialog, 'keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
            if (['ArrowLeft', 'ArrowRight'].includes(event.key) && zoom === 1 && !event.altKey && !event.ctrlKey && !event.metaKey) { event.preventDefault(); event.stopPropagation(); show(index + (event.key === 'ArrowLeft' ? -1 : 1)); }
        });
        listen(stage, 'touchstart', event => { touch = zoom === 1 && event.touches.length === 1 ? {x: event.touches[0].clientX, y: event.touches[0].clientY} : null; }, {passive: true});
        listen(stage, 'touchend', event => { if (!touch || zoom !== 1 || !event.changedTouches[0]) return; const dx = event.changedTouches[0].clientX - touch.x, dy = event.changedTouches[0].clientY - touch.y; touch = null; if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5 && images.length > 1) show(index + (dx < 0 ? 1 : -1)); }, {passive: true});
        listen(image, 'error', () => { if (dialog.open && viewerUrl) deny(new Error('This image cannot be displayed.')); });
        listen(doc, 'enterprise:workspace-state', () => { if (!live()) retireInactive(); });
        listen(doc, 'enterprise:tour-context', () => { if (!live()) retireInactive(); });
        listen(doc, 'visibilitychange', () => {
            if (disposed) return;
            if (doc.hidden) {
                suspendImages();
            } else if (!current()) destroy();
            else { suspended = false; thumbnails.forEach(({button}) => observer?.observe(button)); }
        });
        listen(root, 'pagehide', destroy);
        return Object.freeze({destroy, close});
    }
    function mount(options) { return mountWithLoader(options, {eligible: previewUrl, fetch: fetchImage}); }
    root.EverbranchAttachmentGallery = Object.freeze({previewUrl, fetchImage, mount, mountWithLoader});
})();
