(() => {
  'use strict';
  const catalog = globalThis.CAMPUS_MEDIA_CATALOG || { assets: {}, records: {} };
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const own = (value, key) => value && Object.hasOwn(value, key);
  const indexes = new Map();
  let context = null, observer = null, queued = false, viewer = null, active = null, timer = null, returnFocus = null;

  function rowFor(kind, id) {
    if (!context) return null;
    const data = context.data, ops = data.operations || {};
    const rows = { project: [...(data.projects || []), ...(ops.projects || [])], task: data.tasks, job: [...(data.jobs || []), ...(ops.jobs || [])], request: data.requests, contractor: ops.contractors };
    return (own(rows, kind) && Array.isArray(rows[kind]) ? rows[kind] : []).find(row => row.id === id) || null;
  }
  function asset(id) {
    if (!own(catalog.assets, id)) return null;
    const photo = catalog.assets[id];
    const legacy = photo.legacy_id ? globalThis.CAMPUS_JOB_PHOTOS?.find(item => item.id === photo.legacy_id) : null;
    const src = legacy?.src || photo.src;
    if (typeof src !== 'string' || !/^data:image\/(jpeg|png|webp|avif);base64,/.test(src)) return null;
    return { ...photo, id, src };
  }
  function photosFor(kind, id) {
    const row = rowFor(kind, id);
    if (!row) return [];
    let ids = own(catalog.records, kind) && own(catalog.records[kind], id) ? catalog.records[kind][id] : [];
    if (kind === 'contractor') ids = (row.assigned_job_ids || []).flatMap(jobId => rowFor('job', jobId) && own(catalog.records.job, jobId) ? catalog.records.job[jobId] : []);
    return [...new Set(ids)].map(asset).filter(Boolean);
  }
  function keyParts(key) { const split = key.indexOf(':'); return [key.slice(0, split), key.slice(split + 1)]; }
  function titleFor(kind, id) { const row = rowFor(kind, id); return row?.title || row?.name || 'Campus work'; }
  function render(kind, id, variant = 'detail') {
    const photos = photosFor(kind, id); if (!photos.length) return '';
    if (!['cover', 'task', 'thumb', 'detail'].includes(variant)) variant = 'detail';
    const key = kind + ':' + id, index = (indexes.get(key) || 0) % photos.length, photo = photos[index], title = titleFor(kind, id), compact = variant === 'thumb';
    return `<figure class="campus-media campus-media--${variant}" data-campus-gallery="${escape(key)}" role="group" aria-roledescription="carousel" aria-label="Photos for ${escape(title)}"><div class="campus-media-frame"><button type="button" class="campus-media-open" data-photo-action="open" aria-label="View ${photos.length} ${photos.length === 1 ? 'photo' : 'photos'} for ${escape(title)}"><img src="${escape(photo.src)}" alt="Illustrative: ${escape(photo.title)}" loading="lazy" decoding="async" draggable="false"></button>${!compact && photos.length > 1 ? '<button type="button" class="campus-media-arrow campus-media-prev" data-photo-action="previous" aria-label="Previous photo">‹</button><button type="button" class="campus-media-arrow campus-media-next" data-photo-action="next" aria-label="Next photo">›</button>' : ''}<span class="campus-media-count" aria-hidden="true">${index + 1} / ${photos.length}</span></div>${compact ? '' : '<figcaption>Illustrative demo photos <span class="campus-media-expand">Click to enlarge</span></figcaption>'}<span class="campus-media-sr" data-photo-announcement aria-live="polite"></span></figure>`;
  }
  function insert(container, kind, id, variant, before = null) {
    if (!container || container.querySelector(':scope > [data-campus-gallery]')) return;
    const html = render(kind, id, variant); if (!html) return;
    const holder = document.createElement('div'); holder.innerHTML = html;
    container.insertBefore(holder.firstElementChild, before);
  }
  function jobDetail(container, id) {
    if (!container || !photosFor('job', id).length) return;
    const story = container.querySelector('.job-story');
    insert(story || container, 'job', id, 'detail', (story || container).firstChild);
    if (story) {
      story.querySelectorAll('.job-stock-photo, figure:has(svg[aria-label^="Illustrative"])').forEach(item => { item.hidden = true; });
    }
  }
  function scan() {
    if (!context) return;
    // Preserve existing nodes, event handlers, editable forms, focus and page state.
    document.querySelectorAll('button.project-card[data-project]').forEach(button => {
      if (button.parentElement.classList.contains('campus-photo-project') || !photosFor('project', button.dataset.project).length) return;
      const wrapper = document.createElement('div'); wrapper.className = 'campus-photo-project';
      button.before(wrapper); wrapper.append(button); insert(wrapper, 'project', button.dataset.project, 'cover', button);
    });
    document.querySelectorAll('.task-card[data-id]').forEach(card => insert(card, 'task', card.dataset.id, 'task', card.querySelector('.task-open')));
    document.querySelectorAll('.content table [data-task]').forEach(button => { const cell = button.closest('td'); if (cell) insert(cell, 'task', button.dataset.task, 'thumb', button); });
    document.querySelectorAll('#portfolio-table [data-project]').forEach(button => insert(button.closest('td'), 'project', button.dataset.project, 'thumb', button));
    document.querySelectorAll('[data-ops-job]').forEach(button => insert(button.closest('td'), 'job', button.dataset.opsJob, 'thumb', button));
    document.querySelectorAll('.attention-row [data-task]').forEach(button => {
      const row = button.closest('.attention-row'), icon = row.querySelector('.icon-box');
      if (!row.querySelector('[data-campus-gallery]') && photosFor('task', button.dataset.task).length) { insert(row, 'task', button.dataset.task, 'thumb', row.firstChild); if (icon) icon.hidden = true; }
    });
    const ops = context.data.operations || {};
    if (['operations', 'contractors'].includes(context.view)) document.querySelectorAll('.project-grid > .panel').forEach(panel => {
      const heading = panel.querySelector('h3'); if (!heading) return;
      const project = (ops.projects || []).find(row => row.name === heading.textContent.trim());
      const contractor = (ops.contractors || []).find(row => row.name === heading.textContent.trim());
      if (project) insert(panel, 'project', project.id, 'cover', heading);
      else if (contractor) insert(panel, 'contractor', contractor.id, 'cover', heading);
    });
    document.querySelectorAll('[data-request]').forEach(button => {
      // List rows and cards have room for a thumbnail; calendar/map markers stay compact.
      if (button.closest('table')) insert(button.closest('td'), 'request', button.dataset.request, 'thumb', button);
      else if (button.closest('.request-mobile-card')) insert(button.closest('.request-mobile-card'), 'request', button.dataset.request, 'thumb', button);
    });
    const taskForm = document.querySelector('#task-edit[data-campus-task]');
    if (taskForm) insert(taskForm.parentElement, 'task', taskForm.dataset.campusTask, 'detail', taskForm);
    const modalJob = document.querySelector('#modal[open] [data-campus-job]');
    if (modalJob) jobDetail(modalJob.parentElement, modalJob.dataset.campusJob);
    const selectedJob = document.querySelector('#campus-job-info #job-select');
    if (selectedJob) jobDetail(document.querySelector('#campus-job-info'), selectedJob.value);
    const requestTitle = document.querySelector('#modal[open] [data-request-title]');
    if (requestTitle) {
      const row = (context.data.requests || []).find(row => row.title === requestTitle.textContent);
      if (row) insert(requestTitle.parentElement, 'request', row.id, 'detail', requestTitle.nextSibling);
    }
  }
  function scheduleScan() { if (queued || !context) return; queued = true; requestAnimationFrame(() => { queued = false; scan(); }); }
  function setInline(frame, offset) {
    const [kind, id] = keyParts(frame.dataset.campusGallery), photos = photosFor(kind, id); if (!photos.length) return;
    const key = kind + ':' + id, index = ((indexes.get(key) || 0) + offset + photos.length) % photos.length; indexes.set(key, index);
    const image = frame.querySelector('img'); image.src = photos[index].src; image.alt = 'Illustrative: ' + photos[index].title;
    frame.querySelector('.campus-media-count').textContent = `${index + 1} / ${photos.length}`;
    frame.querySelector('[data-photo-announcement]').textContent = `Photo ${index + 1} of ${photos.length}: ${photos[index].title}`;
  }
  function stop() { if (timer) clearInterval(timer); timer = null; if (viewer?.open) { viewer.querySelector('[data-viewer-action="play"]')?.setAttribute('aria-pressed', 'false'); const play = viewer.querySelector('[data-viewer-action="play"]'); if (play) play.textContent = 'Play slideshow'; viewer.querySelector('[data-viewer-caption]')?.setAttribute('aria-live', 'polite'); } }
  function close(restore = true) {
    stop();
    if (active && typeof document !== 'undefined') {
      const key = active.kind + ':' + active.id;
      document.querySelectorAll('[data-campus-gallery]').forEach(frame => { if (frame.dataset.campusGallery === key) setInline(frame, 0); });
    }
    active = null;
    if (viewer?.open) viewer.close();
    viewer?.replaceChildren();
    if (restore && returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
    returnFocus = null;
  }
  function updateViewer(offset = 0) {
    if (!active) return;
    const photos = photosFor(active.kind, active.id); if (!photos.length) { close(); return; }
    active.index = (active.index + offset + photos.length) % photos.length;
    const photo = photos[active.index], image = viewer.querySelector('img'); image.src = photo.src; image.alt = 'Illustrative: ' + photo.title;
    indexes.set(active.kind + ':' + active.id, active.index);
    viewer.querySelector('[data-viewer-caption]').textContent = `${active.index + 1} of ${photos.length} · ${photo.title}`;
    const credit = viewer.querySelector('[data-photo-credit]'); credit.textContent = photo.author + ' · ' + photo.license;
    credit.href = /^https:\/\/(www\.pexels\.com|commons\.wikimedia\.org)\//.test(photo.source) ? photo.source : '#';
    viewer.querySelectorAll('[data-photo-dot]').forEach((button, index) => button.setAttribute('aria-current', String(index === active.index)));
  }
  function ensureViewer() {
    if (viewer) return;
    viewer = document.createElement('dialog'); viewer.id = 'campus-photo-viewer'; viewer.className = 'campus-photo-viewer'; viewer.setAttribute('aria-labelledby', 'campus-photo-title');
    document.body.append(viewer);
    viewer.addEventListener('cancel', event => { event.preventDefault(); close(); });
    viewer.addEventListener('click', event => { if (event.target === viewer) close(); });
    viewer.addEventListener('keydown', event => {
      if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
      if (['ArrowLeft', 'ArrowRight'].includes(event.key)) { event.preventDefault(); stop(); updateViewer(event.key === 'ArrowLeft' ? -1 : 1); }
    });
    let touch = null;
    viewer.addEventListener('pointerdown', event => { if (event.pointerType === 'touch' && event.target.tagName === 'IMG') touch = { x: event.clientX, y: event.clientY }; });
    viewer.addEventListener('pointerup', event => { if (touch && Math.abs(event.clientX - touch.x) > 45 && Math.abs(event.clientY - touch.y) < 60) { stop(); updateViewer(event.clientX < touch.x ? 1 : -1); } touch = null; });
  }
  function open(frame, trigger) {
    const [kind, id] = keyParts(frame.dataset.campusGallery), photos = photosFor(kind, id); if (!photos.length) return;
    stop(); ensureViewer(); returnFocus = trigger; active = { kind, id, index: (indexes.get(kind + ':' + id) || 0) % photos.length };
    viewer.innerHTML = `<header><div><small>Illustrative demo photos</small><h2 id="campus-photo-title">${escape(titleFor(kind, id))}</h2></div><button type="button" data-viewer-action="close" aria-label="Close photos">×</button></header><div class="campus-photo-stage"><img alt="" draggable="false">${photos.length > 1 ? '<button type="button" class="campus-viewer-prev" data-viewer-action="previous" aria-label="Previous photo">‹</button><button type="button" class="campus-viewer-next" data-viewer-action="next" aria-label="Next photo">›</button>' : ''}</div><footer><p data-viewer-caption aria-live="polite"></p><div class="campus-photo-controls">${photos.length > 1 ? `<div class="campus-photo-dots" role="group" aria-label="Choose photo">${photos.map((_, i) => `<button type="button" data-photo-dot="${i}" aria-label="Show photo ${i + 1}">${i + 1}</button>`).join('')}</div><button type="button" data-viewer-action="play" aria-pressed="false">Play slideshow</button>` : ''}</div><p class="campus-photo-disclosure">Illustrative stock photos, not documentation of campus work. <a data-photo-credit target="_blank" rel="noopener noreferrer"></a></p></footer>`;
    updateViewer(); viewer.showModal(); viewer.querySelector('[data-viewer-action="close"]').focus();
  }
  function retire() { context = null; close(false); indexes.clear(); if (typeof document !== 'undefined') document.querySelectorAll('[data-campus-gallery]').forEach(frame => frame.remove()); }
  function mount(environment) {
    if (context && (context.data.persona !== environment.data.persona || context.view !== environment.view)) close(false);
    context = environment;
    if (!observer) { observer = new MutationObserver(scheduleScan); observer.observe(document.body, { childList: true, subtree: true }); }
    scheduleScan();
  }
  globalThis.CampusMedia = { mount, retire, photosFor, render };
  if (typeof document !== 'undefined') {
    document.addEventListener('change', event => { if (event.target.id === 'persona') retire(); }, true);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
    document.addEventListener('click', event => {
      const button = event.target.closest?.('[data-photo-action], [data-viewer-action], [data-photo-dot]'); if (!button) return;
      event.preventDefault(); event.stopPropagation();
      if (button.hasAttribute('data-photo-action')) {
        const frame = button.closest('[data-campus-gallery]'); if (!frame) return;
        if (button.dataset.photoAction === 'open') open(frame, button); else setInline(frame, button.dataset.photoAction === 'next' ? 1 : -1);
      } else if (button.hasAttribute('data-photo-dot')) { stop(); if (active) { active.index = Number(button.dataset.photoDot); updateViewer(); } }
      else {
        const action = button.dataset.viewerAction;
        if (action === 'close') close();
        else if (action === 'play') { if (timer) stop(); else { button.setAttribute('aria-pressed', 'true'); button.textContent = 'Pause slideshow'; viewer.querySelector('[data-viewer-caption]').setAttribute('aria-live', 'off'); timer = setInterval(() => updateViewer(1), 4000); } }
        else { stop(); updateViewer(action === 'next' ? 1 : -1); }
      }
    }, true);
  }
})();
