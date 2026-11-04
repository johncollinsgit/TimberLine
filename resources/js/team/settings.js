// Settings stay over the current Teams page; closing never resets task or area drafts.
export function installSettings(root) {
  const dialog = document.getElementById('tw-settings-dialog');
  if (!dialog) return;
  const notice = document.getElementById('tw-settings-notice');
  let returnFocus, movingToArea = false;
  const report = error => { notice.textContent = error.message || 'These settings could not be opened.'; };
  function renderAreas(kind) {
    const container = document.getElementById('tw-settings-' + kind);
    if (!container) return;
    container.replaceChildren();
    const areas = window.TeamOperations?.settingsAreas() || [];
    if (!areas.length) {
      const text = document.createElement('p'); text.className = 'muted';
      text.textContent = document.getElementById('team-operations') ? 'Work areas are loading or unavailable. Reopen this section after checking workspace access.' : 'Connected work areas are not enabled for this workspace.';
      container.append(text); return;
    }
    for (const area of areas) {
      const button = document.createElement('button'); button.type = 'button';
      button.textContent = area.name + (kind === 'connections' ? ' connections' : ' reminder rules');
      button.onclick = async () => {
        try {
          // Use the same draft guard and authorized area navigation as the work area itself.
          movingToArea = true;
          dialog.close();
          if (window.TeamPremium?.context && !window.TeamPremium.context.ready()) { dialog.showModal(); notice.textContent = 'Finish or retain your current task draft before switching work areas.'; return; }
          await window.TeamOperations.openSettingsArea(area.id, kind === 'connections' ? 'connections' : 'automations');
        } catch (error) { if (!dialog.open) dialog.showModal(); report(error); }
        finally { movingToArea = false; }
      };
      container.append(button);
    }
  }
  function select(section) {
    notice.textContent = '';
    dialog.querySelectorAll('[data-team-settings-panel]').forEach(panel => { panel.hidden = panel.dataset.teamSettingsPanel !== section; });
    dialog.querySelectorAll('[data-team-settings-tab]').forEach(button => { if (button.dataset.teamSettingsTab === section) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current'); });
    if (section !== 'profile') renderAreas(section);
  }
  document.querySelectorAll('[data-team-settings]').forEach(button => button.addEventListener('click', () => {
    returnFocus = document.activeElement; notice.textContent = '';
    window.TeamPremium?.closeMenu();
    if (!dialog.open) dialog.showModal();
  }));
  document.getElementById('tw-settings-close').onclick = () => dialog.close();
  dialog.querySelectorAll('[data-team-settings-tab]').forEach(button => { button.onclick = () => select(button.dataset.teamSettingsTab); });
  dialog.addEventListener('close', () => {
    if (movingToArea) return;
    const visible = control => { const rect = control?.getBoundingClientRect(); return rect && rect.width > 0 && rect.right > 0 && rect.left < innerWidth; };
    const target = visible(returnFocus) ? returnFocus : [document.getElementById('tops-menu'), document.querySelector('[data-teams-menu]')].find(visible);
    target?.focus();
  });
  dialog.addEventListener('input', event => {
    const form = event.target.closest('[data-team-profile-form]');
    if (!form) return;
    const saved = form.querySelector('.teams-settings-saved'); if (saved) saved.hidden = true;
    notice.textContent = 'Your profile edits stay in this page until you save them.';
  });
  dialog.addEventListener('submit', event => {
    if (!event.target.matches('[data-team-profile-form]')) return;
    // Never fall back to native navigation or put profile fields into a URL.
    // Livewire's existing submit handler still receives the event.
    event.preventDefault();
    const component = event.target.closest('[wire\\:id]')?.getAttribute('wire:id');
    if (!component || !window.Livewire?.find(component)) notice.textContent = 'Account settings have not finished loading. Your edits stay here; try again when the page is ready.';
  }, true);
  addEventListener('profile-updated', event => {
    if (typeof event.detail?.name !== 'string') return;
    notice.textContent = '';
    document.querySelectorAll('[data-team-user-name]').forEach(node => { node.textContent = event.detail.name; });
    document.querySelectorAll('[data-team-user-avatar]').forEach(node => { node.textContent = event.detail.name.slice(0, 1); });
  });
  addEventListener('beforeunload', event => {
    const form = dialog.querySelector('[data-team-profile-form]');
    if (form && (form.elements.name.value !== form.dataset.savedName || form.elements.email.value !== form.dataset.savedEmail)) { event.preventDefault(); event.returnValue = ''; }
  });
}
