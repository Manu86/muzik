'use strict';

export const $ = (s, el = document) => el.querySelector(s);
export const $$ = (s, el = document) => [...el.querySelectorAll(s)];

export const api = {
  async get(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
};

export const state = {
  view: '',
  letter: 'A',
  queue: [],
  index: -1,
  shuffle: false,
  repeat: false,
  transcode: 0,
  currentAlbum: null,
  currentArtistName: null,
};

/* ------------------------------------------------------------------ */
/*  Util                                                               */
/* ------------------------------------------------------------------ */

export function esc(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

/* Pictos de pages (même dessin que le menu, couleur du texte) */
const PICTOS = {
  artists: 'M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z',
  albums: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 14.5A6.5 6.5 0 1 1 12 3.5a6.5 6.5 0 0 1 0 13zm0-5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  genres: 'M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6z',
  top: 'M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z',
  recent: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm.5-13H11v6l5.25 3.15.75-1.23L12.5 13V7z',
  random: 'M10.59 9.17 5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z',
  favorites: 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z',
  settings: 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z',
};

export function pictoIcon(name) {
  return '<svg class="picto" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="' + PICTOS[name] + '"/></svg>';
}

export function fmtDur(sec) {
  if (sec == null || isNaN(sec)) return '–';
  sec = Math.round(sec);
  return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
}

export function qs(val) {
  return encodeURIComponent(val == null ? '' : String(val));
}

export function a11yCard(card, role = 'link') {
  if (card._a11y) return;
  card._a11y = true;
  card.tabIndex = 0;
  card.setAttribute('role', role);
  const label = (card.querySelector('.t') || {}).textContent || card.dataset.id || 'Ouvrir';
  card.setAttribute('aria-label', label);
  card.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
  });
}

export function a11yRow(row, label) {
  if (row._a11y) return;
  row._a11y = true;
  row.tabIndex = 0;
  row.setAttribute('role', 'button');
  row.setAttribute('aria-label', label || ((row.dataset.title || '') + (row.dataset.artist ? ' — ' + row.dataset.artist : '')));
  row.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); }
  });
}
