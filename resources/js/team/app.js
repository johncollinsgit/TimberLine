import '../../css/team-premium.css';
import { installPremium } from './premium.js';
import { installOperations } from './operations.js';
import { installSettings } from './settings.js';
import { installTeamsApp } from './install-app.js';

const root = document.getElementById('team-work-app');
if (root) {
  document.body.classList.add('teams-premium');
  window.TeamPremium = installPremium(root);
  installOperations(root);
  installSettings(root);
  installTeamsApp();
  import(/* @vite-ignore */ root.dataset.coreUrl).catch(() => {
    document.getElementById('tw-status').textContent = 'Teams could not start. Refresh this page to try again.';
  });
}
