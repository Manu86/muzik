'use strict';

import { bootAuth, loadSettings, loadSettingsView } from './account.js?v=84';
import { configureSettingsView, handleRoute } from './views.js?v=84';
import { syncPlayBtn } from './player.js?v=84';

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
