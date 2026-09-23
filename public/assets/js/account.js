'use strict';

import { $, api, state, pictoIcon } from './core.js?v=77';
import { handleRoute, switchView } from './views.js?v=77';
import { syncPlayBtn } from './player.js?v=77';

/* ============================================================ */
/*  AUTHENTIFICATION PAR L'APPLICATION                          */
/* ------------------------------------------------------------ */
let authState = { checked: false, authenticated: false, user: null };

export async function bootAuth() {
  const screen = $('#auth-screen');
  const btnLogout = $('#btn-logout');
  try {
    const r = await fetch('api/auth');
    const data = await r.json();
    authState.authenticated = !!data.authenticated;
    authState.user = data.user || null;
  } catch {
    authState.authenticated = true;
    authState.user = null;
  }
  authState.checked = true;

  const needsLogin = !authState.authenticated;
  if (screen) screen.hidden = !needsLogin;
  if (btnLogout) btnLogout.hidden = !(authState.authenticated && authState.user);
  if (needsLogin && window.location.hash) {
    history.replaceState(null, '', '#');
  }
  return authState.authenticated;
}

function resetLoadedViews() {
  ['#view-home', '#view-artists'].forEach(sel => {
    const el = $(sel);
    if (el) delete el.dataset.loaded;
  });
  state.view = '';
}

async function submitAuth() {
  const user = $('#auth-user').value.trim();
  const pass = $('#auth-pass').value;
  const remember = $('#auth-remember')?.checked ? '1' : '0';
  const error = $('#auth-error');
  if (error) error.hidden = true;
  try {
    const r = await fetch('api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ user, pass, remember }),
    });
    if (!r.ok) throw new Error('Identifiants invalides');
    if (await bootAuth()) {
      resetLoadedViews();
      switchView('home');
      loadSettings();
    }
  } catch (e) {
    if (error) {
      error.textContent = 'Connexion refusée : vérifiez vos identifiants.';
      error.hidden = false;
    }
  }
  return false;
}

async function doLogout() {
  try {
    await fetch('api/logout', { method: 'POST' });
  } catch {}
  authState.authenticated = false;
  authState.user = null;
  resetLoadedViews();
  const screen = $('#auth-screen');
  if (screen) screen.hidden = false;
  const btnLogout = $('#btn-logout');
  if (btnLogout) btnLogout.hidden = true;
  history.replaceState(null, '', window.location.pathname + window.location.search);
}

$('#auth-form')?.addEventListener('submit', e => { e.preventDefault(); submitAuth(); });
$('#btn-logout')?.addEventListener('click', doLogout);

export async function loadSettings() {
  try {
    const s = await api.get('api/settings');
    if (s.transcode != null) {
      state.transcode = parseInt(s.transcode, 10);
      $('#player-transcode').value = String(state.transcode);
    }
  } catch {}
}

/* ------------------------------------------------------------------ */
/*  Réglages : rescannage de la bibliothèque                           */
/* ------------------------------------------------------------------ */

let scanPollTimer = null;

function fmtDateTime(unixTs) {
  if (unixTs == null || unixTs === '') return '';
  const n = Number(unixTs);
  if (!isFinite(n) || n <= 0) return '';
  return new Date(n * 1000).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
}

function renderSettingsView() {
  $('#view-settings').innerHTML =
    '<div class="view-header">' + pictoIcon('settings') + '<h2>Réglages</h2></div>' +
    '<section class="settings-section" aria-labelledby="settings-account-title">' +
      '<h3 id="settings-account-title">Compte</h3>' +
      '<p class="settings-desc">Instance connectée : ' +
        '<strong id="settings-account-login">…</strong>' +
      '</p>' +
    '</section>' +
    '<section class="settings-section" aria-labelledby="settings-lib-title">' +
      '<h3 id="settings-lib-title">Emplacement des musiques</h3>' +
      '<p class="settings-desc">Chemin absolu du dossier contenant vos fichiers (MP3, FLAC, OGG, M4A, WAV).</p>' +
      '<div class="settings-row">' +
        '<input type="text" id="settings-music-root" class="settings-input" placeholder="/chemin/vers/ma/musique" autocomplete="off">' +
        '<button id="btn-save-root" type="button">Enregistrer</button>' +
      '</div>' +
      '<p class="settings-detail" id="root-save-status" role="status"></p>' +
    '</section>' +
    '<section class="settings-section" aria-labelledby="settings-scan-title">' +
      '<h3 id="settings-scan-title">Bibliothèque</h3>' +
      '<p class="settings-desc">Relance une analyse de la bibliothèque en arrière-plan pour détecter de nouveaux fichiers.</p>' +
      '<div class="settings-row">' +
        '<span id="scan-status" role="status" aria-live="polite">Chargement…</span>' +
        '<button id="btn-rescan" type="button">Rescanner la bibliothèque</button>' +
      '</div>' +
      '<p class="settings-detail" id="scan-detail"></p>' +
    '</section>';
  $('#btn-rescan').addEventListener('click', triggerScan);
  $('#btn-save-root').addEventListener('click', saveMusicRoot);
}

export async function loadSettingsView() {
  renderSettingsView();
  await loadMusicRootField();
  await refreshScanStatus();
}

async function loadMusicRootField() {
  let s = {};
  try {
    s = await api.get('api/settings');
  } catch {}
  const input = $('#settings-music-root');
  if (input && s.music_root) input.value = s.music_root;
  const loginEl = $('#settings-account-login');
  if (loginEl) loginEl.textContent = s.user || 'inconnu';
}

async function saveMusicRoot() {
  const input = $('#settings-music-root');
  const btn = $('#btn-save-root');
  const status = $('#root-save-status');
  if (!input || !btn || !status) return;
  const path = input.value.trim();
  if (path === '') {
    status.textContent = 'Indiquez le dossier contenant vos fichiers de musique.';
    return;
  }
  btn.disabled = true;
  status.textContent = 'Enregistrement en cours…';
  try {
    const r = await fetch('api/config', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ music_root: path }),
    });
    const body = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(body && body.error ? body.error : 'Réponse inattendue du serveur.');
    input.value = body.music_root || path;
    status.textContent = body.scan_started
      ? 'Emplacement enregistré. Indexation de la bibliothèque lancée.'
      : 'Emplacement enregistré.';
    await refreshScanStatus();
  } catch (e) {
    status.textContent = 'Impossible d\'enregistrer l\'emplacement : ' + (e && e.message ? e.message : 'erreur serveur.');
  } finally {
    btn.disabled = false;
  }
}

async function refreshScanStatus() {
  let s = {};
  try {
    s = await api.get('api/settings');
  } catch {}
  if (scanPollTimer) {
    clearTimeout(scanPollTimer);
    scanPollTimer = null;
  }
  const statusEl = $('#scan-status');
  if (!statusEl) return;
  if (state.view !== 'settings') return;
  const running = s.scan_running === '1';
  const btn = $('#btn-rescan');
  if (btn) btn.disabled = running;
  if (running) {
    statusEl.textContent = 'Indexation en cours…';
    const started = fmtDateTime(s.scan_started_at);
    if (started) $('#scan-detail').textContent = 'Démarrée ' + started;
    scanPollTimer = setTimeout(refreshScanStatus, 3000);
  } else {
    statusEl.textContent = 'Aucune analyse en cours.';
    const last = fmtDateTime(s.scan_last_run);
    $('#scan-detail').textContent = last ? 'Dernière analyse : ' + last : '';
  }
}

async function triggerScan() {
  const btn = $('#btn-rescan');
  if (!btn || btn.disabled || scanPollTimer) return;
  const statusEl = $('#scan-status');
  btn.disabled = true;
  statusEl.textContent = 'Lancement…';
  try {
    const r = await fetch('api/scan', { method: 'POST' });
    const body = await r.json().catch(() => ({}));
    if (body && body.ok === true) {
      await refreshScanStatus();
    } else if (body && body.running === true) {
      await refreshScanStatus();
    } else {
      throw new Error(body && body.error ? body.error : 'Réponse inattendue du serveur.');
    }
  } catch (e) {
    statusEl.textContent = 'Impossible de lancer le scan : ' + (e && e.message ? e.message : 'erreur serveur.');
    btn.disabled = false;
  }
}
