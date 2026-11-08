const mounts = new WeakMap();
/** Only the server-authorized standalone host loads the shared assistant. */
export function installTeamsAssistant(shell, load = () => import('../ai-assistant.jsx')) {
  const entry = shell?.querySelector('[data-team-assistant-entry]');
  const host = entry?.querySelector('[data-ai-assistant]');
  if (!host) return Promise.resolve(false);
  if (mounts.has(host)) return mounts.get(host);
  const mounted = Promise.resolve().then(load).then(module => {
    if (!host.isConnected || !shell.isConnected) return false;
    module.mountAiAssistantNow();
    return true;
  }).catch(() => {
    if (host.isConnected && shell.isConnected) {
      const status = entry.querySelector('[data-team-assistant-status]');
      const fallback = entry.querySelector('[data-team-assistant-fallback]');
      if (status) { status.textContent = 'Ask Everbranch could not open here. Try the assistant page.'; status.hidden = false; }
      if (fallback) fallback.hidden = false;
    }
    return false;
  });
  mounts.set(host, mounted);
  return mounted;
}
