export function installTeamsApp() {
  const install = document.querySelector('[data-teams-install]');
  const help = document.querySelector('[data-teams-install-help]');
  if (!install || !help) return;
  let prompt;
  window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); prompt = event; });
  install.onclick = async () => {
    if (prompt) { await prompt.prompt(); await prompt.userChoice; prompt = null; }
    else {
      help.hidden = !help.hidden;
      help.textContent = 'iPhone: open this page in Safari, tap Share, then Add to Home Screen. Android: open in Chrome, tap the menu, then Install app or Add to Home screen. Your app opens Events → My Tasks for this workspace. An internet connection is required.';
    }
  };
  if ('serviceWorker' in navigator && window.isSecureContext) navigator.serviceWorker.register('/teams/sw.js', { scope: '/teams/' }).catch(() => {});
}
