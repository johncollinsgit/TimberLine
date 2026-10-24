/* Only fixed page names and tour positions leave the browser. No work/form contents. */
(() => {
  'use strict';
  const marker = document.querySelector('[data-review-activity]');
  if (!marker || marker.dataset.mounted) return;
  marker.dataset.mounted = 'true';
  const boot = marker.dataset;
  let endpoint;
  try {
    endpoint = new URL(boot.endpoint, location.href);
    if (endpoint.origin !== location.origin || endpoint.username || endpoint.password || endpoint.search || endpoint.hash
      || !/\/service-proposals\/[^/]+\/activity$/.test(endpoint.pathname)) return;
  } catch { return; }
  let stopped = false, previousPage = boot.page, previousScene = null, completed = false;
  const send = (event, page = null, scene = null) => {
    if (stopped || typeof crypto.randomUUID !== 'function') return;
    fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', redirect: 'error', keepalive: true,
      headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': boot.csrf},
      body: JSON.stringify({event, page, scene, event_id: crypto.randomUUID(), version_hash: boot.versionHash})})
      .then(response => { if ([403, 404, 409, 410, 419, 429, 503].includes(response.status)) stopped = true; }).catch(() => {});
  };
  if (boot.mode === 'proposal') {
    document.addEventListener('change', event => {
      const input = event.target.closest?.('input[data-proposal-section]');
      if (input?.checked) send('proposal_section', input.dataset.proposalSection);
    });
    return;
  }
  document.addEventListener('enterprise:section-rendered', event => {
    const next = event.detail?.section;
    if (typeof next === 'string' && next !== previousPage) { previousPage = next; send('campus_page', next); }
  });
  const root = document.querySelector('.ert-root');
  if (!root) return;
  const inspect = () => {
    if (document.visibilityState === 'hidden' || !root.querySelector('[data-tour-dialog]')?.open) return;
    const scene = Number(root.dataset.tourScene) + 1;
    if (!Number.isInteger(scene) || scene < 1 || scene > 7) return;
    if (previousScene !== scene) { previousScene = scene; send('tour_scene', null, scene); }
    const progress = root.querySelector('[data-tour-progress]');
    const finished = scene === 7 && progress?.max > 0 && progress.value >= progress.max;
    if (finished && !completed) send('tour_completed', null, scene);
    completed = finished;
  };
  new MutationObserver(inspect).observe(root, {subtree: true, attributes: true,
    attributeFilter: ['open', 'data-tour-scene', 'value']});
  inspect();
})();
