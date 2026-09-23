'use strict';

import { bootAuth, loadSettings, loadSettingsView } from './account.js?v=77';
import { configureSettingsView, handleRoute } from './views.js?v=77';
import { syncPlayBtn } from './player.js?v=77';

configureSettingsView(loadSettingsView);

(async function startApp() {
  if (!await bootAuth()) return;
  handleRoute();
  syncPlayBtn();
  loadSettings();
})();

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}
