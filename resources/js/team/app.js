import '../../css/team-premium.css';
import '../../css/team-work-toolbar.css';
import { installPremium } from './premium.js';
import { installOperations } from './operations.js';
import { installSettings } from './settings.js';
import { installTeamsApp } from './install-app.js';
import { installTeamsAssistant } from './assistant.js';
import { installUniversalSearch } from './universal-search.js';
import '../../css/team-assistant.css';

void installTeamsAssistant(document.querySelector('[data-team-standalone]'));

const root = document.getElementById('team-work-app');
if (root) {
  document.body.classList.add('teams-premium');
  window.TeamPremium = installPremium(root);
  installOperations(root);
  installSettings(root);
  installTeamsApp();
  installUniversalSearch(root);
  import(/* @vite-ignore */ root.dataset.coreUrl).catch(() => {
    document.getElementById('tw-status').textContent = 'Teams could not start. Refresh this page to try again.';
  });
}
