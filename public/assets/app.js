'use strict';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];

const api = {
  async get(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
};

const state = {
  view: '',
  letter: 'A',
  queue: [],
  index: -1,
  shuffle: false,
  repeat: false,
  transcode: 0,
  albumPage: 1,
  currentAlbum: null,
  currentArtistName: null,
};

const audio = new Audio();
audio.preload = 'auto';
audio.autoplay = true;

const audioBg = new Audio();
audioBg.preload = 'auto';

/* ============================================================ */
/*  DIAGNOSTICS TEMPORAIRES — à retirer une fois le bug résolu  */
/* ------------------------------------------------------------ */
/*  Journalise les événements audio + rejets de play() + sommeil
 *  réseau dans localStorage, pour identifier la cause de l'arrêt
 *  de lecture écran verrouillé. Lecteur : 5 taps sur le titre du
 *  player dans #player-info.                                    */
/* ============================================================ */
const DIAG_KEY = 'muzik-diag-12';
const DIAG_LOG = [];
let diagBlobPlays = 0;
let diagStreamPlays = 0;
let diagFlushedAt = 0;
function diagLog(ev, detail = '') {
  let buf = '';
  try {
    if (audio.buffered.length) buf = Math.round(audio.buffered.end(audio.buffered.length - 1) * 10) / 10;
  } catch {}
  DIAG_LOG.push({
    t: new Date().toISOString().slice(11, 23),
    ms: Date.now(),
    e: ev,
    d: detail,
    h: document.hidden ? 1 : 0,
    ct: Math.round((audio.currentTime || 0) * 10) / 10,
    ns: audio.networkState,
    rs: audio.readyState,
    buf,
    dur: audio.duration && isFinite(audio.duration) ? Math.round(audio.duration) : 'inf',
  });
  while (DIAG_LOG.length > 400) DIAG_LOG.shift();
  try { localStorage.setItem(DIAG_KEY, JSON.stringify(DIAG_LOG)); } catch {}
}
function diagFlush() {
  if (!DIAG_LOG.length || Date.now() - diagFlushedAt < 10000) return;
  diagFlushedAt = Date.now();
  const body = JSON.stringify({ log: DIAG_LOG.slice(-300) });
  if (navigator.sendBeacon) {
    let blob;
    try { blob = new Blob([body], { type: 'application/json' }); } catch { return; }
    try { navigator.sendBeacon('api/diag', blob); } catch {}
  } else {
    fetch('api/diag', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body }).catch(() => {});
  }
}
['loadstart', 'emptied', 'stalled', 'waiting', 'playing', 'pause', 'ended', 'error', 'durationchange', 'loadeddata'].forEach(evt => {
  audio.addEventListener(evt, () => {
    if (evt === 'error' && audio.error) {
      const detail = 'code=' + audio.error.code + ' ' + (audio.error.name || '') + ' ' + (audio.error.message || '');
      diagLog(evt, detail);
      diagLog('marker-' + (audio.error.code === 2 ? 'reseau' : audio.error.code === 4 ? 'decodage' : 'autre'));
    } else {
      diagLog(evt);
    }
  });
});
const _nativePlay = audio.play.bind(audio);
audio.play = (...args) => _nativePlay(...args).catch(err => {
  diagLog('play-rejected', (err && err.name || '?') + ': ' + (err && err.message || ''));
  throw err;
});
setInterval(() => {
  if (document.hidden && wantPlay && audio.src) {
    diagLog('hb5', 'src=' + audio.currentSrc.slice(0, 40) +
      ' dur=' + (isFinite(audio.duration) ? Math.round(audio.duration) : 'inf') +
      ' ct=' + Math.round(audio.currentTime));
  }
}, 5000);
setInterval(() => {
  if (!audio.paused && document.hidden && audio.src) { diagLog('heartbeat'); diagFlush(); }
}, 20000);
setInterval(() => {
  if (document.hidden && wantPlay && audio.paused && audio.readyState >= 1 && audio.currentSrc && !transitionHold) {
    diagLog('watchdog-play', 'rs=' + audio.readyState + ' ns=' + audio.networkState);
    audio.play().catch(() => {});
  }
}, 4000);
/* ============ FIN DIAGNOSTICS ============ */

let wakeLock = null;
let wantPlay = false;
async function acquireWakeLock() {
  try {
    if ('wakeLock' in navigator && document.visibilityState === 'visible' && !wakeLock) {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; });
    }
  } catch {}
}
function releaseWakeLock() {
  try { if (wakeLock) wakeLock.release(); } catch {}
  wakeLock = null;
}
document.addEventListener('visibilitychange', () => {
  const last = DIAG_LOG[DIAG_LOG.length - 1];
  if (document.visibilityState === 'visible' && last && last.ms && Date.now() - last.ms > 4000) {
    diagLog('resume-gap', 'gap=' + Math.round((Date.now() - last.ms) / 1000) + 's');
  }
  diagLog('vis', document.visibilityState);
  if (document.visibilityState !== 'visible') {
    const entry = state.queue[state.index];
    if (wantPlay && entry && audio.currentSrc && !isLightSource(audio.currentSrc) && !audio.paused) {
      bgBlobSwap(String(entry.id), audio.currentTime || 0);
    }
    upcomingPrefetch();
    return;
  }
  acquireWakeLock();
  diagFlush();
  if (!audio.paused && audio.src) return;
  if (wantPlay && audio.paused && audio.src) audio.play().catch(() => {});
});
window.addEventListener('pagehide', diagFlush);

/* ------------------------------------------------------------------ */
/*  Util                                                               */
/* ------------------------------------------------------------------ */

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

/* Pictos de pages (même dessin que le menu, couleur du texte) */
const PICTOS = {
  home: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
  artists: 'M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z',
  albums: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 14.5A6.5 6.5 0 1 1 12 3.5a6.5 6.5 0 0 1 0 13zm0-5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  genres: 'M12 3v10.55A4 4 0 1 0 14 17V7h4V3h-6z',
  top: 'M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z',
  recent: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm.5-13H11v6l5.25 3.15.75-1.23L12.5 13V7z',
  random: 'M10.59 9.17 5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z',
  favorites: 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z',
};

function pictoIcon(name) {
  return '<svg class="picto" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="' + PICTOS[name] + '"/></svg>';
}

function fmtDur(sec) {
  if (sec == null || isNaN(sec)) return '–';
  sec = Math.round(sec);
  return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
}

function fmtSize(bytes) {
  if (bytes == null) return '';
  return (bytes / 1024 / 1024 / 1024).toFixed(1) + ' Go';
}

function qs(val) {
  return encodeURIComponent(val == null ? '' : String(val));
}

function a11yCard(card, role = 'link') {
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

function a11yRow(row, label) {
  if (row._a11y) return;
  row._a11y = true;
  row.tabIndex = 0;
  row.setAttribute('role', 'button');
  row.setAttribute('aria-label', label || ((row.dataset.title || '') + (row.dataset.artist ? ' — ' + row.dataset.artist : '')));
  row.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); }
  });
}

/* ------------------------------------------------------------------ */
/*  Navigation                                                         */
/* ------------------------------------------------------------------ */

function switchView(name) {
  state.view = name;
  $$('#views > .view').forEach(v => v.classList.remove('active'));
  const el = $('#view-' + name) || $('#view-artists');
  el.classList.add('active');
  $$('.nav-item').forEach(i => {
    const on = i.dataset.view === name
      || (name === 'genre-detail' && i.dataset.view === 'genres')
      || (name === 'artist-detail' && i.dataset.view === 'artists')
      || (name === 'album-detail' && i.dataset.view === 'albums');
    i.classList.toggle('active', on);
    i.toggleAttribute('aria-current', on);
    if (on) i.setAttribute('aria-current', 'page');
  });
  $('#content').scrollTop = 0;
  if (name === 'album-detail' || name === 'artist-detail' || name === 'genre-detail') return;
  navigateTo('#/' + name);
  if (name === 'home' && !el.dataset.loaded) loadHome();
  if (name === 'artists' && !el.dataset.loaded) loadArtists();
  if (name === 'albums') loadAlbums(true);
  if (name === 'genres') loadGenres();
  if (name === 'favorites') loadFavorites();
  if (name === 'random') loadRandom();
  if (name === 'top') loadTop();
  if (name === 'recent') loadRecent();
}

let ownHash = '';
function navigateTo(target) {
  if (location.hash === target) return;
  ownHash = target;
  location.hash = target;
}

window.addEventListener('hashchange', handleRoute);
function handleRoute() {
  if (ownHash) {
    ownHash = '';
    return;
  }
  const m = location.hash.match(/^#\/(artist|album)\/(\d+)$/);
  const gm = location.hash.match(/^#\/genre\/(.+)$/);
  if (gm) {
    openGenre(decodeURIComponent(gm[1]), state.back || 'genres');
    return;
  }
  if (m) {
    if (m[1] === 'album') openAlbum(m[2], state.back || 'albums');
    else openArtist(m[2], state.back || 'artists');
    return;
  }
  const name = (location.hash || '#/home').replace(/^#\//, '') || 'home';
  const views = ['home', 'genres', 'artists', 'albums', 'top', 'recent', 'random', 'favorites'];
  if (views.includes(name) && state.view !== name) switchView(name);
}

$('#sidebar').addEventListener('click', e => {
  const item = e.target.closest('.nav-item');
  if (!item) return;
  if (item.dataset.view === 'queue') { e.preventDefault(); return toggleQueue(); }
  switchView(item.dataset.view);
});

/* ------------------------------------------------------------------ */
/*  Pochettes                                                          */
/* ------------------------------------------------------------------ */

function artUrl(id, type) {
  return 'api/art/' + id + (type === 'artist' ? '?type=artist' : '');
}

function artImg(album, artist, cls) {
  const img = document.createElement('img');
  img.loading = 'lazy';
  img.alt = '';
  if (cls) img.className = cls;
  const id = album || artist;
  if (!id) return img;
  img.src = artUrl(id, artist && !album ? 'artist' : 'album');
  img.onerror = () => {
    img.onerror = null;
    const ph = document.createElement('span');
    ph.className = 'ph' + (cls ? ' ' + cls : '');
    ph.textContent = '♪';
    img.replaceWith(ph);
  };
  return img;
}

function openArtLightbox(src) {
  const box = $('#art-lightbox');
  if (!box) return;
  $('#art-lightbox-img').src = src;
  box.hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeArtLightbox() {
  const box = $('#art-lightbox');
  if (!box || box.hidden) return;
  box.hidden = true;
  $('#art-lightbox-img').src = '';
  document.body.style.overflow = '';
}

$('#art-lightbox')?.addEventListener('click', e => {
  if (e.target.closest('#art-lightbox-img') || e.target.closest('#art-lightbox-close')) return;
  closeArtLightbox();
});
$('#art-lightbox-close')?.addEventListener('click', closeArtLightbox);
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#art-lightbox').hidden) closeArtLightbox(); });

/* ------------------------------------------------------------------ */
/*  Artistes                                                           */
/* ------------------------------------------------------------------ */

async function loadArtists() {
  const el = $('#view-artists');
  el.dataset.loaded = '1';
  const data = await api.get('api/artists');
  const letters = data.letters.map(l => l.l).join('');
  el.innerHTML =
    '<div class="view-header">' + pictoIcon('artists') + '<h2>Artistes</h2><span class="count">' + (data.total || 0) + ' artistes</span></div>' +
    '<div class="letters">' +
    [...letters].map(l =>
      `<button type="button" data-l="${esc(l)}" class="${l === state.letter ? 'active' : ''}">${esc(l)}</button>`
    ).join('') +
    '</div><div class="grid" id="artist-grid"></div>';
  $$('#view-artists .letters button').forEach(b =>
    b.addEventListener('click', () => {
      state.letter = b.dataset.l;
      $$('#view-artists .letters button').forEach(x => x.classList.toggle('active', x === b));
      loadArtistLetter(state.letter);
    })
  );
  loadArtistLetter(state.letter);
}

async function loadArtistLetter(letter) {
  const grid = $('#artist-grid');
  grid.innerHTML = '<div class="empty">Chargement…</div>';
  const list = await api.get('api/artists?letter=' + qs(letter));
  if (!list.length) {
    grid.innerHTML = '<div class="empty">Aucun artiste pour cette lettre.</div>';
    return;
  }
  grid.innerHTML = list.map(a => `
    <div class="card" data-id="${a.id}">
      <div class="card-art"></div>
      <div class="card-body"><div class="t">${esc(a.name)}</div><div class="s">${a.song_count} piste${a.song_count > 1 ? 's' : ''}</div></div>
    </div>`).join('');
  $$('#artist-grid .card').forEach(card => {
    card.querySelector('.card-art').appendChild(artImg(null, card.dataset.id));
    a11yCard(card);
    card.addEventListener('click', () => openArtist(card.dataset.id, 'artists'));
  });
}

/* ------------------------------------------------------------------ */
/*  Albums                                                             */
/* ------------------------------------------------------------------ */

const ALBUM_PER = 240;
const albumsInf = { page: 0, totalPages: 1, loading: false, done: false };

async function loadAlbums(reset = true) {
  const el = $('#view-albums');
  const grid = $('#album-grid');
  if (reset) {
    albumsInf.page = 0;
    albumsInf.totalPages = 1;
    albumsInf.loading = false;
    albumsInf.done = false;
    el.dataset.loaded = '1';
    el.innerHTML = `
      <div class="view-header">${pictoIcon('albums')}<h2>Albums</h2><span class="count" id="album-count"></span></div>
      <div class="grid" id="album-grid"></div>
      <div class="end-note" id="album-end"></div>
    `;
    $('#content').scrollTop = 0;
  }
  if (albumsInf.loading || albumsInf.done) return;
  albumsInf.loading = true;
  const end = $('#album-end');
  end.textContent = 'Chargement…';
  const data = await api.get('api/albums?page=' + (albumsInf.page + 1));
  const gridNow = $('#album-grid');
  const countEl = $('#album-count');
  if (albumsInf.page === 0) {
    countEl.textContent = data.total + ' albums';
    if (!data.albums.length) gridNow.innerHTML = '<div class="empty">Aucun album.</div>';
  }
  appendAlbumGrid(gridNow, data.albums);
  albumsInf.page = data.page;
  albumsInf.totalPages = Math.max(1, Math.ceil(data.total / ALBUM_PER));
  albumsInf.loading = false;
  if (albumsInf.page >= albumsInf.totalPages) {
    albumsInf.done = true;
    end.textContent = albumsInf.page > 1 ? '— Toutes les pistes affichées —' : '';
    if (gridNow && gridNow.querySelector('.empty')) {
      countEl.textContent = data.total + ' albums';
    }
    return;
  }
  end.textContent = '';
}

$('#content').addEventListener('scroll', () => {
  const c = $('#content');
  if (c.scrollTop + c.clientHeight < c.scrollHeight - 600) return;
  if (state.view === 'albums') loadAlbums(false);
  if (state.view === 'random') loadRandMore();
});

function appendAlbumGrid(grid, albums) {
  if (!albums.length || !grid) return;
  grid.insertAdjacentHTML('beforeend', albums.map(a => `
    <div class="card" data-id="${a.id}">
      <div class="card-art"></div>
      <div class="card-body">
        <div class="t">${esc(a.name)}</div>
        <div class="s">${esc(a.artist_name)}${a.year ? ' · ' + a.year : ''} · ${a.song_count} piste${a.song_count > 1 ? 's' : ''}</div>
      </div>
    </div>`).join(''));
  grid.querySelectorAll('.card').forEach(card => {
    if (card._bound) return;
    card._bound = true;
    card.querySelector('.card-art').appendChild(artImg(card.dataset.id, null));
    a11yCard(card);
    card.addEventListener('click', () => openAlbum(card.dataset.id, 'albums'));
  });
}

/* ------------------------------------------------------------------ */
/*  Genres                                                             */
/* ------------------------------------------------------------------ */

const GENRE_COLORS = ['#5aa7ff', '#f25172', '#1fbe8f', '#ffb020', '#9b6bff', '#ff7a59', '#3fc6ff', '#d94fd0', '#b2c94a', '#e880ff', '#00c2b8', '#ff5287', '#7ad04f', '#6a7dff', '#f5a623', '#3ddad7'];

function genreColor(name) {
  let h = 0;
  for (const c of String(name)) h = ((h * 31) + c.codePointAt(0)) >>> 0;
  return GENRE_COLORS[h % GENRE_COLORS.length];
}

async function loadGenres() {
  const el = $('#view-genres');
  el.dataset.loaded = '1';
  el.innerHTML = '<div class="view-header">' + pictoIcon('genres') + '<h2>Genres</h2><span class="count" id="genre-count"></span></div>';
  const genres = await api.get('api/genres');
  const countEl = $('#genre-count');
  countEl.textContent = genres.length + ' genres';
  if (!genres.length) {
    el.insertAdjacentHTML('beforeend', '<div class="empty">Aucun genre disponible.</div>');
    return;
  }
  const grid = document.createElement('div');
  grid.className = 'genre-grid';
  grid.innerHTML = genres.map(g => `
    <button type="button" class="genre-tile" data-genre="${esc(g.genre)}" style="--genre-color:${genreColor(g.genre)}">
      <span class="genre-name">${esc(g.genre)}</span>
      <span class="genre-count">${g.album_count} alb${g.album_count > 1 ? 'ums' : 'um'} · ${g.song_count} piste${g.song_count > 1 ? 's' : ''}</span>
    </button>`).join('');
  el.appendChild(grid);
  $$('.genre-tile', grid).forEach(t => {
    t.addEventListener('click', () => openGenre(t.dataset.genre, 'genres'));
  });
}

/* ------------------------------------------------------------------ */
/*  Accueil                                                            */
/* ------------------------------------------------------------------ */

async function loadHome() {
  const el = $('#view-home');
  el.dataset.loaded = '1';
  el.innerHTML = '<div class="empty">Chargement…</div>';
  const d = await api.get('api/home');
  const favIds = await getFavIds();
  el.innerHTML = `
    <div class="home-section">
      <div class="section-head">${pictoIcon('genres')}<h2>Genres</h2><a class="see-all" href="#/genres">Voir tous →</a></div>
      <div class="genre-grid">${d.genres.map(g => `
        <button type="button" class="genre-tile" data-genre="${esc(g.genre)}" style="--genre-color:${genreColor(g.genre)}">
          <span class="genre-name">${esc(g.genre)}</span>
          <span class="genre-count">${g.album_count} alb${g.album_count > 1 ? 'ums' : ''} · ${g.song_count} piste${g.song_count > 1 ? 's' : ''}</span>
        </button>`).join('')}
      </div>
    </div>

    <hr class="home-divider">

    <div class="home-section">
      <div class="section-head">${pictoIcon('artists')}<h2>Artistes</h2><a class="see-all" href="#/artists">Voir tous →</a></div>
      <div class="home-grid">${d.artists.map(a => `
        <div class="card" data-id="${a.id}" data-artist="1">
          <div class="card-art"></div>
          <div class="card-body">
            <div class="t">${esc(a.name)}</div>
            <div class="s">${a.song_count} piste${a.song_count > 1 ? 's' : ''}</div>
          </div>
        </div>`).join('')}
      </div>
    </div>

    <hr class="home-divider">

    <div class="home-section">
      <div class="section-head">${pictoIcon('albums')}<h2>Albums</h2><a class="see-all" href="#/albums">Voir tous →</a></div>
      <div class="home-grid">${d.albums.map(a => `
        <div class="card" data-id="${a.id}">
          <div class="card-art"></div>
          <div class="card-body">
            <div class="t">${esc(a.name)}</div>
            <div class="s">${esc(a.artist_name)}${a.year ? ' · ' + a.year : ''} · ${a.song_count} piste${a.song_count > 1 ? 's' : ''}</div>
          </div>
        </div>`).join('')}
      </div>
    </div>

    <hr class="home-divider">

    <div class="home-section">
      <div class="section-head">${pictoIcon('recent')}<h2>Récemment écoutés</h2><a class="see-all" href="#/recent">Voir tous →</a></div>
      ${d.recent.length ? `
      <div class="track-list">
        ${d.recent.map(s => `
          <div class="track-row" data-id="${s.id}" data-title="${esc(s.title)}" data-artist="${esc(s.artist_name + ' — ' + s.album_name)}" data-duration="" data-album="${s.album_id || ''}">
            ${thumbHtml(s.album_id)}
            <span class="ti">${esc(s.title)}<span class="t-artist">${esc(s.artist_name + ' — ' + s.album_name)}</span></span>
            <span class="cnt">${timeAgo(s.last_played)}</span>
            <span></span>
            <button type="button" class="fav${favIds.has(String(s.id)) ? ' on' : ''}" data-fav aria-pressed="${favIds.has(String(s.id))}" aria-label="${favIds.has(String(s.id)) ? 'Retirer des favoris' : 'Ajouter aux favoris'}">${favIds.has(String(s.id)) ? '♥' : '♡'}</button>
            <button type="button" class="add-q" data-addq title="Ajouter à la file d'attente" aria-label="Ajouter à la file d'attente">+</button>
          </div>`).join('')}
      </div>` : '<div class="empty">Aucune écoute pour le moment.<br>Écoute des pistes pour les retrouver ici.</div>'}
    </div>

    <hr class="home-divider">

    <div class="home-section">
      <div class="section-head">${pictoIcon('favorites')}<h2>Favoris</h2><a class="see-all" href="#/favorites">Voir tous →</a></div>
      ${d.poche.length ? `
      <div class="cover-grid">
        ${d.poche.map(s => `
          <button type="button" class="cover-tile" data-id="${s.id}" data-title="${esc(s.title)}" data-artist="${esc(s.artist_name + ' — ' + s.album_name)}" data-album="${s.album_id || ''}" aria-label="${esc(s.artist_name)} – ${esc(s.title)}">
            <img loading="lazy" src="api/art/${s.album_id || ''}" alt="" onerror="this.remove()">
            <span class="cover-label">${esc(s.artist_name)}</span>
          </button>`).join('')}
      </div>` : '<div class="empty">Aucune poche pour le moment.<br>Appuie sur ♡ sur une piste pour la retrouver ici.</div>'}
    </div>
  `;

  $$('.genre-tile', el).forEach(t => {
    t.addEventListener('click', () => openGenre(t.dataset.genre, 'home'));
  });
  $$('.card[data-artist]', el).forEach(c => {
    c.querySelector('.card-art').appendChild(artImg(null, c.dataset.id));
    a11yCard(c);
    c.addEventListener('click', () => openArtist(c.dataset.id, 'home'));
  });
  $$('.card:not([data-artist])', el).forEach(c => {
    c.querySelector('.card-art').appendChild(artImg(c.dataset.id, null));
    a11yCard(c);
    c.addEventListener('click', () => openAlbum(c.dataset.id, 'home'));
  });
  $$('.cover-tile', el).forEach(t => {
    t.addEventListener('click', () => {
      playQueue([{ id: t.dataset.id, title: t.dataset.title, artist: t.dataset.artist, duration: '', album: t.dataset.album }], 0);
    });
  });
  bindTrackClick(el);
}

async function openGenre(name, backView) {
  state.back = backView;
  const target = '#/genre/' + qs(name);
  navigateTo(target);
  switchView('genre-detail');
  $('#view-genre-detail').dataset.back = backView;
  $('#view-genre-detail').innerHTML = '<div class="empty">Chargement…</div>';
  const data = await api.get('api/genre?name=' + qs(name));
  $('#view-genre-detail').innerHTML = `
    <div class="view-header">
      <button class="back" type="button">← Retour</button>
      ${pictoIcon('genres')}<h2>${esc(data.genre)}</h2>
      <span class="count">${data.total_albums} alb${data.total_albums > 1 ? 'ums' : 'um'} · ${data.total_songs} piste${data.total_songs > 1 ? 's' : ''}</span>
    </div>
    <div class="grid">${data.albums.map(a => `
      <div class="card" data-id="${a.id}">
        <div class="card-art"></div>
        <div class="card-body">
          <div class="t">${esc(a.name)}</div>
          <div class="s">${esc(a.artist_name)}${a.year ? ' · ' + a.year : ''} · ${a.song_count} piste${a.song_count > 1 ? 's' : ''}</div>
        </div>
      </div>`).join('')}
    </div>`;
  bindBack(backView, $('#view-genre-detail'));
  $$('#view-genre-detail .card').forEach(card => {
    card.querySelector('.card-art').appendChild(artImg(card.dataset.id, null));
    a11yCard(card);
    card.addEventListener('click', () => openAlbum(card.dataset.id, 'genre-detail'));
  });
}

/* ------------------------------------------------------------------ */
/*  Détails album / artiste                                            */
/* ------------------------------------------------------------------ */

let albumToken = 0;

function bindBack(backView, view) {
  const link = $('.back', view);
  if (!link) return;
  link.addEventListener('click', e => {
    e.preventDefault();
    const before = location.hash;
    history.back();
    setTimeout(() => {
      if (location.hash !== before) return;
      const fallback = (backView === 'album-detail' || backView === 'artist-detail') ? 'albums' : backView;
      switchView(fallback || 'albums');
    }, 150);
  });
}

async function openAlbum(id, backView) {
  state.back = backView;
  await renderAlbumDetail(id, backView);
}

async function renderAlbumDetail(id, backView) {
  const token = ++albumToken;
  const data = await api.get('api/album/' + id);
  if (token !== albumToken) return;
  state.currentAlbum = data;
  state.currentArtistName = data.artist_name;
  switchView('album-detail');
  $('#view-album-detail').dataset.back = backView;
  const target = '#/album/' + id;
  navigateTo(target);
  const favIds = await getFavIds();
  $('#view-album-detail').innerHTML = `
    <div class="view-header">
      <button class="back" type="button">← Retour</button>
    </div>
    <div class="album-hero">
      <div class="album-hero-art" id="album-hero-art"></div>
      <div class="album-hero-info">
        <span class="album-hero-lbl">Album</span>
        <h1>${esc(data.name)}</h1>
        <p class="album-hero-artist">${esc(data.artist_name)}</p>
        <p class="album-hero-meta">${data.genre ? '<span class="genre-badge">' + esc(data.genre) + '</span>' + ' · ' : ''}${data.songs.length} piste${data.songs.length > 1 ? 's' : ''}${data.year ? ' · ' + data.year : ''}${data.path ? ' · ' + esc(data.path) : ''}</p>
        <div class="album-hero-actions">
          <button id="play-all" class="play-all" type="button">▶ Tout lire</button>
          <button id="add-to-queue" class="add-to-queue" type="button">Ajouter à la file d'attente</button>
          <button id="edit-album" class="add-to-queue" type="button">✏ Modifier</button>
          <button id="delete-album" class="add-to-queue delete-album" type="button">Supprimer</button>
        </div>
      </div>
    </div>
    <div class="track-list">${renderTracks(data.songs).map(s => renderTrack(s, favIds)).join('')}</div>
  `;
  $('#album-hero-art').appendChild(artImg(id, null, 'hero'));
  const heroImg = $('#album-hero-art img');
  if (heroImg) heroImg.addEventListener('click', () => openArtLightbox(heroImg.src));
  bindBack(backView, $('#view-album-detail'));
  $('#play-all').addEventListener('click', () => playQueue(data.songs.map(s => ({
    id: s.id, title: s.title, artist: data.artist_name, duration: s.duration, album: id,
  }))));
  $('#add-to-queue').addEventListener('click', e => {
    e.stopPropagation();
    const wasEmpty = state.queue.length === 0;
    const entries = data.songs.map(s => toEntry({
      id: s.id, title: s.title, artist: data.artist_name, duration: s.duration, album: id,
    }));
    state.queue.push(...entries);
    if (wasEmpty) state.index = 0;
    openQueue();
  });
  $('#delete-album').addEventListener('click', () => deleteAlbum(id));
  bindAlbumEdit(id, backView);
  bindTrackClick($('#view-album-detail'));
  $$('#view-album-detail .track-row').forEach(r => {
    r.dataset.album = id;
    const img = r.querySelector('.tr-thumb img');
    if (img) img.src = 'api/art/' + id;
  });
}

function bindAlbumEdit(id, backView) {
  $('#edit-album').addEventListener('click', () => {
    const album = state.currentAlbum;
    if (!album || String(album.id) !== String(id)) return;
    const info = $('#view-album-detail').querySelector('.album-hero-info');
    if (!info) return;
    const maxYear = new Date().getFullYear();
    const form = document.createElement('div');
    form.id = 'album-edit-form';
    form.innerHTML = `
      <label class="album-edit-field">Nom<input id="edit-name" type="text" maxlength="200" value="${esc(album.name)}"></label>
      <label class="album-edit-field">Année<input id="edit-year" type="number" min="1" max="${maxYear}" value="${album.year ?? ''}" placeholder="—"></label>
      <div class="album-edit-actions">
        <button id="edit-save" class="play-all" type="button">Enregistrer</button>
        <button id="edit-cancel" class="add-to-queue" type="button">Annuler</button>
      </div>
      <p class="album-edit-hint">Modifie la base. Pour écrire dans les fichiers audio : php bin/album-edit.php ${id} --apply</p>`;
    const title = info.querySelector('h1');
    const meta = info.querySelector('.album-hero-meta');
    if (title) title.remove();
    if (meta) meta.remove();
    const artist = info.querySelector('.album-hero-artist');
    info.insertBefore(form, artist ? artist.nextSibling : null);
    $('#edit-cancel').addEventListener('click', () => renderAlbumDetail(id, backView));
    $('#edit-save').addEventListener('click', () => saveAlbumEdit(id, backView));
  });
}

async function saveAlbumEdit(id, backView) {
  const name = $('#edit-name').value.trim();
  const year = $('#edit-year').value.trim();
  if (!name) {
    alert("Le nom de l'album ne peut pas être vide.");
    return;
  }
  const body = new URLSearchParams({ name });
  if (year !== '') body.set('year', year);
  const btn = $('#edit-save');
  if (btn) btn.disabled = true;
  try {
    const r = await fetch('api/album/' + id, { method: 'PATCH', body });
    if (!r.ok) {
      let msg = '';
      try { const j = await r.json(); msg = j.error || ''; } catch (e) {}
      alert('Échec de la modification : ' + (msg || r.status));
      return;
    }
  } finally {
    if (btn) btn.disabled = false;
  }
  await renderAlbumDetail(id, backView);
}

async function deleteAlbum(id) {
  if (!state.currentAlbum || String(state.currentAlbum.id) !== String(id)) return;
  const album = state.currentAlbum;
  if (!confirm(`Supprimer l'album « ${album.name} » et ses ${album.songs.length} piste${album.songs.length > 1 ? 's' : ''} du disque ? Cette action est irréversible.`)) return;
  const r = await fetch('api/album/' + id, { method: 'DELETE' });
  if (!r.ok) {
    alert('Échec de la suppression : ' + (await r.text()));
    return;
  }
  try {
    const res = await r.json();
    state.lastDeleted = res;
  } catch (e) {}
  const orig = state.queue;
  const oldIdx = state.index;
  const playing = String(state.playingId);
  const removedIds = new Set(orig.filter(e => String(e.album) === String(id)).map(e => String(e.id)));
  const removedBefore = orig.slice(0, oldIdx).filter(e => String(e.album) === String(id)).length;
  state.queue = orig.filter(e => String(e.album) !== String(id));
  state.currentAlbum = null;
  if (removedIds.has(playing)) {
    state.playingId = null;
    if (!state.queue.length) {
      state.index = -1;
      audio.pause(); audio.src = '';
      clearPlaybackRetries();
      setPlayBtn(false);
      refreshSongInfo(); mediaSessionUpdate();
    } else {
      state.index = Math.max(0, oldIdx - removedBefore);
      if (state.index >= state.queue.length) state.index = state.queue.length - 1;
      play();
    }
  } else if (!state.queue.length) {
    state.index = -1;
    audio.pause(); audio.src = '';
    clearPlaybackRetries();
    setPlayBtn(false);
    refreshSongInfo(); mediaSessionUpdate();
  } else {
    state.index = Math.max(0, oldIdx - removedBefore);
    if (state.index >= state.queue.length) state.index = state.queue.length - 1;
  }
  renderQueue();
  numberRows();
  switchView(state.back && state.back !== 'album-detail' ? state.back : 'albums');
}

async function openArtist(id, backView) {
  state.back = backView;
  const token = ++albumToken;
  const data = await api.get('api/artist/' + id);
  if (token !== albumToken) return;
  state.currentArtistName = data.name;
  switchView('artist-detail');
  $('#view-artist-detail').dataset.back = backView;
  const target = '#/artist/' + id;
  navigateTo(target);
  $('#view-artist-detail').innerHTML = `
    <div class="view-header">
      <button class="back" type="button">← Retour</button>
      ${pictoIcon('artists')}<h2>${esc(data.name)}</h2>
      <span class="count">${data.albums.length} alb${data.albums.length > 1 ? 'ums' : 'um'}</span>
    </div>
    <div class="grid">${data.albums.map(al => `
      <div class="card" data-id="${al.id}">
        <div class="card-art"></div>
        <div class="card-body"><div class="t">${esc(al.name)}</div><div class="s">${al.song_count} piste${al.song_count > 1 ? 's' : ''}</div></div>
      </div>`).join('')}
    </div>
  `;
  bindBack(backView, $('#view-artist-detail'));
  $$('#view-artist-detail .card').forEach(card => {
    card.querySelector('.card-art').appendChild(artImg(card.dataset.id, null));
    a11yCard(card);
    card.addEventListener('click', () => openAlbum(card.dataset.id, 'artist-detail'));
  });
}

/* ------------------------------------------------------------------ */
/*  Pistes                                                             */
/* ------------------------------------------------------------------ */

function renderTracks(songs) {
  return songs.map(s => ({
    id: s.id,
    title: s.title,
    artist: s.artist_name || state.currentArtistName || '',
    duration: s.duration,
    album: s.album_id || '',
  }));
}

function thumbHtml(albumId) {
  return `<span class="tr-thumb"><img loading="lazy" src="api/art/${albumId || ''}" alt=""></span>`;
}

function renderTrack(t, favIds) {
  const on = favIds.has(String(t.id)) ? ' on' : '';
  return `
    <div class="track-row" data-id="${t.id}" data-title="${esc(t.title)}" data-artist="${esc(t.artist)}" data-duration="${t.duration || ''}">
      ${thumbHtml(t.album)}
      <span class="ti">${esc(t.title)}<span class="t-artist" data-art>${esc(t.artist)}</span></span>
      <span class="du">${fmtDur(t.duration)}</span>
      <span></span>
      <button class="fav${on}" data-fav type="button" aria-label="${on ? 'Retirer des favoris' : 'Ajouter aux favoris'}" aria-pressed="${on}">${on ? '♥' : '♡'}</button>
      <button class="add-q" data-addq type="button" title="Ajouter à la file d'attente" aria-label="Ajouter à la file d'attente">+</button>
    </div>`;
}

function numberRows() {
  $$('.track-row, .art-row').forEach(row => {
    const playing = state.playingId === row.dataset.id;
    row.classList.toggle('playing', playing);
    row.classList.toggle('paused', playing && audio.paused);
  });
}

function bindTrackClick(container) {
  container.addEventListener('error', e => {
    if (e.target.tagName === 'IMG' && e.target.closest('.tr-thumb')) e.target.style.display = 'none';
  }, true);
  container.querySelectorAll('.track-row').forEach(row => {
    a11yRow(row);
    row.addEventListener('click', e => {
      if (e.target.closest('[data-fav]') || e.target.closest('[data-addq]')) return;
      if (state.playingId === row.dataset.id && audio.src) {
        togglePlayback();
        return;
      }
      const ids = $$('.track-row', container).map(r => r.dataset.id);
      playQueue(ids, ids.indexOf(row.dataset.id));
    });
  });
  container.querySelectorAll('[data-fav]').forEach(btn => {
    btn.addEventListener('click', async e => {
      e.stopPropagation();
      const row = btn.closest('.track-row');
      await toggleFav(row.dataset.id, btn);
    });
  });
  container.querySelectorAll('[data-addq]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const row = btn.closest('.track-row');
      const wasEmpty = state.queue.length === 0;
      state.queue.push(toEntry(row.dataset.id));
      if (wasEmpty) state.index = 0;
      openQueue();
      btn.classList.add('pulse');
      setTimeout(() => btn.classList.remove('pulse'), 300);
    });
  });
  numberRows();
}

let favCache = new Set();
async function getFavIds() {
  try { const list = await api.get('api/favorites'); favCache = new Set(list.map(s => String(s.id))); } catch {}
  return favCache;
}

async function toggleFav(id, btn) {
  const has = favCache.has(id);
  await api.get(`api/favorites?action=${has ? 'remove' : 'add'}&id=${id}`);
  if (has) favCache.delete(id); else favCache.add(id);
  btn.textContent = has ? '♡' : '♥';
  btn.classList.toggle('on', !has);
  btn.setAttribute('aria-pressed', has ? 'false' : 'true');
  btn.setAttribute('aria-label', has ? 'Ajouter aux favoris' : 'Retirer des favoris');
  if (state.view === 'favorites') loadFavorites();
}

async function loadFavorites() {
  const el = $('#view-favorites');
  const songs = await api.get('api/favorites');
  if (!songs.length) {
    el.innerHTML = '<div class="empty">Aucune poche pour le moment.<br>Appuie sur ♡ sur une piste pour la retrouver ici.</div>';
    return;
  }
  const favIds = await getFavIds();
  el.innerHTML = `
    <div class="view-header">${pictoIcon('favorites')}<h2>Favoris</h2><span class="count">${songs.length} pistes</span></div>
    <div class="track-list">
      ${songs.map(s => renderTrack({
        id: s.id, title: s.title,
        artist: s.artist_name + ' — ' + s.album_name, duration: s.duration,
        album: s.album_id || '',
      }, favIds)).join('')}
    </div>`;
  bindTrackClick(el);
}

const RAND_BATCH = 100;
let randInf = { offset: 0, loading: false };

async function loadRandom() {
  randInf = { offset: 0, loading: false };
  const el = $('#view-random');
  el.dataset.loaded = '1';
  el.innerHTML = `
    <div class="view-header">
      ${pictoIcon('random')}<h2>Sélection aléatoire</h2>
      <span class="count" id="rand-count">0 pistes</span>
      <button class="nav-item" id="rand-again" type="button" style="background:var(--bg-3);color:var(--text);border:1px solid var(--border);font-weight:600;">🔄 Nouvelle sélection</button>
      <button class="nav-item" id="rand-playall" type="button" style="background:var(--accent);color:#082015;border:none;font-weight:600;">▶ Tout lire</button>
    </div>
    <div class="track-list" id="rand-list"></div>
    <div class="end-note" id="rand-end"></div>`;
  $('#rand-again').addEventListener('click', loadRandom);
  $('#rand-playall').addEventListener('click', () => randPlay(0));
  $('#content').scrollTop = 0;
  loadRandMore();
}

function allRandIds() {
  return $$('#rand-list .art-row').map(r => r.dataset.id);
}

function randPlay(index) {
  const ids = allRandIds();
  playQueue(ids, index);
}

async function loadRandMore() {
  if (randInf.loading) return;
  randInf.loading = true;
  const end = $('#rand-end');
  end.textContent = 'Chargement…';
  const [songs, favIds] = await Promise.all([
    api.get('api/random?n=' + RAND_BATCH + '&offset=' + randInf.offset),
    getFavIds(),
  ]);
  const list = $('#rand-list');
  songs.forEach(s => {
    const idx = list.children.length;
    const row = document.createElement('div');
    row.className = 'art-row';
    row.dataset.id = s.id;
    row.dataset.album = s.album_id;
    row.dataset.title = s.title;
    row.dataset.artist = s.artist_name + ' — ' + s.album_name;
    row.dataset.duration = s.duration || '';
    row.innerHTML = `
      <span class="athumb"></span>
      <span class="ati">${esc(s.title)}<span class="t-artist">${esc(s.artist_name)} · ${esc(s.album_name)}</span></span>
      <span class="adu">${fmtDur(s.duration)}</span>
      <button type="button" class="fav${favIds.has(String(s.id)) ? ' on' : ''}" data-fav aria-pressed="${favIds.has(String(s.id))}" aria-label="${favIds.has(String(s.id)) ? 'Retirer des favoris' : 'Ajouter aux favoris'}">${favIds.has(String(s.id)) ? '♥' : '♡'}</button>
      <button class="add-q" data-addq type="button" title="Ajouter à la file d'attente" aria-label="Ajouter à la file d'attente">+</button>`;
    const thumb = row.querySelector('.athumb');
    const img = document.createElement('img');
    img.loading = 'lazy';
    img.alt = '';
    img.src = 'api/art/' + (s.album_id || '');
    img.onerror = () => img.remove();
    thumb.appendChild(img);
    a11yRow(row, s.title + ' — ' + s.artist_name + ' — ' + s.album_name);
    row.querySelector('[data-fav]').addEventListener('click', async e => {
      e.stopPropagation();
      await toggleFav(String(s.id), e.currentTarget);
    });
    row.querySelector('[data-addq]').addEventListener('click', e => {
      e.stopPropagation();
      const wasEmpty = state.queue.length === 0;
      state.queue.push(toEntry(s));
      if (wasEmpty) state.index = 0;
      openQueue();
      e.currentTarget.classList.add('pulse');
      setTimeout(() => e.currentTarget.classList.remove('pulse'), 300);
    });
    row.addEventListener('click', () => {
      if (state.playingId === row.dataset.id && audio.src) {
        togglePlayback();
        return;
      }
      randPlay(idx);
    });
    list.appendChild(row);
  });
  randInf.offset += songs.length;
  randInf.loading = false;
  $('#rand-count').textContent = list.children.length + ' pistes';
  end.textContent = songs.length ? '' : '— Toutes les pistes affichées —';
}

/* ------------------------------------------------------------------ */
/*  Top & Récent                                                       */
/* ------------------------------------------------------------------ */

function timeAgo(ts) {
  if (!ts) return '';
  const then = new Date(ts.replace(' ', 'T') + 'Z').getTime();
  const diff = Math.max(0, Date.now() - then);
  const m = Math.floor(diff / 60000);
  if (m < 1) return "à l'instant";
  if (m < 60) return 'il y a ' + m + ' min';
  const h = Math.floor(m / 60);
  if (h < 24) return 'il y a ' + h + ' h';
  const d = Math.floor(h / 24);
  if (d < 30) return 'il y a ' + d + ' j';
  return new Date(ts).toLocaleDateString('fr-FR');
}

let topTab = 'songs';

async function loadTop() {
  const el = $('#view-top');
  el.innerHTML = '<div class="empty">Chargement…</div>';
  const [d, favIds] = await Promise.all([api.get('api/top'), getFavIds()]);
  el.innerHTML = `
    <div class="view-header">
      ${pictoIcon('top')}<h2>Plus écoutés</h2>
      <span class="count">${d.total_plays} écoutes au total</span>
      <div class="tabs">
        <button type="button" class="tab${topTab === 'songs' ? ' on' : ''}" data-tab="songs">Pistes</button>
        <button type="button" class="tab${topTab === 'albums' ? ' on' : ''}" data-tab="albums">Albums</button>
      </div>
    </div>
    <div id="top-body"></div>`;
  $$('.tab', el).forEach(b => b.addEventListener('click', () => {
    topTab = b.dataset.tab;
    renderTopBody(el, d, favIds);
  }));
  renderTopBody(el, d, favIds);
}

function renderTopBody(el, d, favIds) {
  let html = '';
  if (topTab === 'songs') {
    if (!d.songs.length) {
      html = '<div class="empty">Aucune écoute pour le moment.<br>Écoute des pistes pour les voir apparaître ici.</div>';
    } else {
      html = '<div class="track-list">' + d.songs.map(s => `
        <div class="track-row" data-id="${s.id}" data-title="${esc(s.title)}" data-artist="${esc(s.artist_name + ' — ' + s.album_name)}" data-duration="${s.duration || ''}" data-album="${s.album_id || ''}">
          ${thumbHtml(s.album_id)}
          <span class="ti">${esc(s.title)}<span class="t-artist">${esc(s.artist_name + ' — ' + s.album_name)}</span></span>
          <span class="du">${fmtDur(s.duration)}</span>
          <span class="cnt">${s.play_count}×</span>
          <button type="button" class="fav${favIds.has(String(s.id)) ? ' on' : ''}" data-fav aria-pressed="${favIds.has(String(s.id))}" aria-label="${favIds.has(String(s.id)) ? 'Retirer des favoris' : 'Ajouter aux favoris'}">${favIds.has(String(s.id)) ? '♥' : '♡'}</button>
          <button class="add-q" data-addq title="Ajouter à la file d'attente">+</button>
        </div>`).join('') + '</div>';
    }
  } else {
    if (!d.albums.length) {
      html = '<div class="empty">Aucune écoute pour le moment.</div>';
    } else {
      html = '<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">' + d.albums.map(a => `
        <div class="card" data-id="${a.id}">
          <div class="card-art"></div>
          <div class="card-body"><div class="t">${esc(a.name)}</div><div class="s">${esc(a.artist_name)} · ${a.plays}×</div></div>
        </div>`).join('') + '</div>';
    }
  }
  const body = el.querySelector('#top-body');
  body.innerHTML = html;
  $$('.card', body).forEach(c => {
    c.querySelector('.card-art').appendChild(artImg(c.dataset.id, null));
    a11yCard(c);
    c.addEventListener('click', () => openAlbum(c.dataset.id, 'top'));
  });
  bindTrackClick(body);
}

async function loadRecent() {
  const el = $('#view-recent');
  el.innerHTML = '<div class="empty">Chargement…</div>';
  const songs = await api.get('api/recent');
  const favIds = await getFavIds();
  if (!songs.length) {
    el.innerHTML = '<div class="empty">Aucune écoute récente.<br>Écoute des pistes pour les retrouver ici.</div>';
    return;
  }
  el.innerHTML = `
    <div class="view-header">${pictoIcon('recent')}<h2>Récemment écouté</h2><span class="count">${songs.length} écoutes</span></div>
    <div class="track-list">
      ${songs.map(s => `
        <div class="track-row" data-id="${s.id}" data-title="${esc(s.title)}" data-artist="${esc(s.artist_name + ' — ' + s.album_name)}" data-duration="" data-album="${s.album_id || ''}">
          ${thumbHtml(s.album_id)}
          <span class="ti">${esc(s.title)}<span class="t-artist">${esc(s.artist_name + ' — ' + s.album_name)}</span></span>
          <span class="cnt">${timeAgo(s.last_played)}</span>
          <span></span>
          <button type="button" class="fav${favIds.has(String(s.id)) ? ' on' : ''}" data-fav aria-pressed="${favIds.has(String(s.id))}" aria-label="${favIds.has(String(s.id)) ? 'Retirer des favoris' : 'Ajouter aux favoris'}">${favIds.has(String(s.id)) ? '♥' : '♡'}</button>
          <button type="button" class="add-q" data-addq title="Ajouter à la file d'attente" aria-label="Ajouter à la file d'attente">+</button>
        </div>`).join('')}
    </div>`;
  bindTrackClick(el);
}

/* ------------------------------------------------------------------ */
/*  Recherche                                                          */
/* ------------------------------------------------------------------ */

const searchInput = $('#search-input');
const searchResults = $('#search-results');
let searchTimer;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (q.length < 2) { closeSearch(); return; }
  searchTimer = setTimeout(() => doSearch(q), 250);
});

async function doSearch(q) {
  const d = await api.get('api/search?q=' + qs(q));
  if (searchInput.value.trim() !== q) return;
  searchResults.classList.add('open');
  searchInput.setAttribute('aria-expanded', 'true');
  let html = '';
  let srIndex = 0;
  const item = (type, attrs, inner) =>
    `<div class="sr-item" id="sr-${srIndex++}" role="option" data-type="${type}" ${attrs}>${inner}</div>`;
  if (d.artists.length) {
    html += '<h3>Artistes</h3>' + d.artists.map(a =>
      item('artist', `data-id="${a.id}"`, esc(a.name))).join('');
  }
  if (d.albums.length) {
    html += '<h3>Albums</h3>' + d.albums.map(a =>
      item('album', `data-id="${a.id}"`, esc(a.name))).join('');
  }
  if (d.songs.length) {
    html += '<h3>Pistes</h3>' + d.songs.map(s =>
      item('song', `data-id="${s.id}" data-title="${esc(s.title)}" data-artist="${esc(s.artist_name || '')}" data-album="${s.album_id || ''}"`,
        `<span class="ti">${esc(s.title)}</span><span class="sub"> ${esc(s.artist_name || '')} — ${esc(s.album_name || '')}</span>`)).join('');
  }
  searchResults.innerHTML = html || '<div class="empty" style="padding:12px">Aucun résultat.</div>';
  $$('.sr-item', searchResults).forEach(item => {
    item.addEventListener('click', async () => {
      closeSearch();
      searchInput.value = '';
      const type = item.dataset.type;
      if (type === 'artist') openArtist(item.dataset.id, 'artists');
      else if (type === 'album') openAlbum(item.dataset.id, state.view);
      else {
        playQueue([{ id: item.dataset.id, title: item.dataset.title, artist: item.dataset.artist, duration: '', album: item.dataset.album || '' }], 0);
      }
    });
  });
}

let srActive = -1;
function closeSearch() {
  searchResults.classList.remove('open');
  searchInput.setAttribute('aria-expanded', 'false');
  searchInput.removeAttribute('aria-activedescendant');
  srActive = -1;
  $$('.sr-item', searchResults).forEach(o => o.classList.remove('sr-active'));
}

function setSrActive(i, opts) {
  srActive = i;
  $$('.sr-item', searchResults).forEach(o => o.classList.toggle('sr-active', o === opts[i]));
  if (i >= 0) {
    searchInput.setAttribute('aria-activedescendant', opts[i].id);
    opts[i].scrollIntoView({ block: 'nearest' });
  } else {
    searchInput.removeAttribute('aria-activedescendant');
  }
}

searchInput.addEventListener('keydown', e => {
  const opts = $$('.sr-item', searchResults);
  if (searchResults.classList.contains('open') && opts.length) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const dir = e.key === 'ArrowDown' ? 1 : -1;
      const next = srActive < 0 ? (dir === 1 ? 0 : opts.length - 1) : (srActive + dir + opts.length) % opts.length;
      setSrActive(next, opts);
      return;
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      const target = (srActive >= 0 && opts[srActive]) ? opts[srActive] : opts[0];
      if (target) target.click();
      return;
    }
  }
  if (e.key === 'Escape') closeSearch();
});

/* ------------------------------------------------------------------ */
/*  Player                                                             */
/* ------------------------------------------------------------------ */

let playingId = null;
let playToken = 0;
let earlyAdvanced = false;
let transitionHold = null;
function clearTransitionHold() {
  if (transitionHold) clearInterval(transitionHold.timer);
  transitionHold = null;
}
Object.defineProperty(state, 'playingId', { get: () => playingId, set: v => { playingId = v; } });

function toEntry(x) {
  if (x && typeof x === 'object') {
    return { id: x.id, title: x.title || '', artist: x.artist || '', duration: x.duration || '', album: x.album || '' };
  }
  const row = document.querySelector(`#content .track-row[data-id="${x}"], #content .art-row[data-id="${x}"]`);
  return {
    id: x,
    title: row ? row.dataset.title : 'Piste',
    artist: row ? (row.dataset.artist || '') : '',
    duration: row ? (row.dataset.duration || '') : '',
    album: row ? (row.dataset.album || '') : '',
  };
}

function playQueue(ids, start = 0) {
  if (!ids.length) return;
  state.queue = ids.map(toEntry);
  state.index = start;
  play();
}

function syncPlayBtn() {
  const empty = !state.queue.length;
  const btn = $('#btn-play');
  btn.disabled = empty;
  if (empty) setPlayBtn(false);
}

async function renderQueue() {
  const list = $('#queue-list');
  const clear = $('#queue-clear');
  clear.style.display = state.queue.length ? '' : 'none';
  list.innerHTML = '';
  if (!state.queue.length) {
    list.innerHTML = '<div class="empty">File d\'attente vide.<br>Joue une piste pour la remplir.</div>';
    syncQueueInset();
    return;
  }
  const favIds = await getFavIds();
  const frag = document.createDocumentFragment();
  state.queue.forEach((e, i) => {
    const row = document.createElement('div');
    row.className = 'q-row' + (i === state.index ? ' cur' : '');
    const isFav = favIds.has(String(e.id));
    row.innerHTML = `
      <span class="tr-thumb"></span>
      <span class="q-info"><span class="t">${esc(e.title || 'Piste')}</span><span class="s">${esc(e.artist || '')}</span></span>
      <span class="q-dur">${fmtDur(e.duration || null)}</span>
      <button type="button" class="fav${isFav ? ' on' : ''}" data-fav aria-pressed="${isFav}" aria-label="${isFav ? 'Retirer des favoris' : 'Ajouter aux favoris'}" title="Ajouter aux favoris">${isFav ? '♥' : '♡'}</button>
      <button class="q-remove" type="button" title="Retirer" aria-label="Retirer de la file d'attente">✕</button>`;
    if (e.album) {
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = '';
      img.src = 'api/art/' + e.album;
      img.onerror = () => img.remove();
      row.querySelector('.tr-thumb').appendChild(img);
    }
    a11yRow(row, (e.title || 'Piste') + (e.artist ? ' — ' + e.artist : ''));
    row.addEventListener('click', () => { state.index = i; play(); });
    row.querySelector('.fav').addEventListener('click', ev => {
      ev.stopPropagation();
      toggleFav(String(e.id), row.querySelector('.fav'));
    });
    row.querySelector('.q-remove').addEventListener('click', ev => {
      ev.stopPropagation();
      removeQueue(i);
    });
    frag.appendChild(row);
  });
  list.appendChild(frag);
  syncPlayBtn();
  syncQueueInset();
}

function removeQueue(i) {
  const wasCurrent = i === state.index;
  state.queue.splice(i, 1);
  if (wasCurrent) {
    if (!state.queue.length) {
      state.index = -1;
      wantPlay = false;
      state.playingId = null;
      playToken++;
      cancelHandoff();
      audio.pause();
      audio.src = '';
      clearPrefetch();
      clearPlaybackRetries();
      setPlayBtn(false);
      refreshSongInfo();
      mediaSessionUpdate();
      numberRows();
    } else {
      state.index = Math.min(i, state.queue.length - 1);
      play();
    }
  } else if (i < state.index) {
    state.index--;
    numberRows();
  }
  renderQueue();
}

function setPlayBtn(playing) {
  $('#ico-play').style.display = playing ? 'none' : '';
  $('#ico-pause').style.display = playing ? '' : 'none';
}

function clearQueue() {
  state.queue = [];
  state.index = -1;
  wantPlay = false;
  state.playingId = null;
  playToken++;
  cancelHandoff();
  audio.pause();
  audio.src = '';
  cancelBgBlobSwap();
  clearPrefetch();
  clearPlaybackRetries();
  setPlayBtn(false);
  refreshSongInfo();
  mediaSessionUpdate();
  numberRows();
  renderQueue();
}

function play() {
  playToken++;
  wantPlay = true;
  earlyAdvanced = false;
  clearTransitionHold();
  cancelBgBlobSwap();
  cancelHandoff();
  const entry = state.queue[state.index];
  if (!entry) return;
  const id = String(entry.id);
  state.playingId = id;
  setPlayBtn(true);
  const blob = cachedAudioUrl(id);
  if (blob) diagBlobPlays++;
  else diagStreamPlays++;
  const url = blob ? blob.url : audioUrl(id);
  const handoff = document.hidden && blob && !audio.paused && !!audio.currentSrc && audio.readyState >= 2;
  if (audio.currentSrc === url && audio.readyState >= 1) {
    audio.currentTime = 0;
  } else if (handoff) {
    const oldBlob = activeBlob;
    if (blob) activeBlob = blob;
    handoffActive = true;
    handoffNext(url, id, playToken, oldBlob);
  } else {
    releaseActiveBlob();
    if (blob) activeBlob = blob;
    audio.src = url;
  }
  if (document.hidden && !blob) bgBlobSwap(id, audio.currentTime || 0);
  diagLog('transition', 'blob=' + (blob ? 1 : 0) +
    ' prefetch=' + prefetched.size + '/' + prefetchPending.size + '/' + prefetchQueue.length +
    ' bytes=' + Math.round(prefetchBytes / 1048576) + 'M');
  if (!handoff) startPlayback();
  acquireWakeLock();
  refreshSongInfo();
  mediaSessionUpdate();
  numberRows();
  renderQueue();
  fetch('api/play/' + id, { method: 'POST' }).catch(() => {});
  upcomingPrefetch();
}

/* Handoff gapless vers la piste suivante en arrière-plan : la piste
   suivante démarre dans `audioBg` pendant que `audio` joue encore les
   dernières secondes, puis `audio` reprend la même source sans jamais
   passer par un état paused. Android ne révoque ainsi pas la session
   audio de l'onglet caché. */
function handoffNext(url, id, token, oldBlob) {
  diagLog('handoff', 'id=' + id + ' start');
  audioBg.src = url;
  let finished = false;
  let dwellTimer = null;
  const finish = () => {
    if (finished) return;
    finished = true;
    clearTimeout(timeoutHandle);
    clearTimeout(dwellTimer);
    audio.removeEventListener('playing', onPlaying);
    audioBg.pause();
    audioBg.src = '';
  };
  const onPlaying = () => {
    if (token === playToken && !finished) {
      if (dwellTimer) clearTimeout(dwellTimer);
      dwellTimer = setTimeout(() => {
        if (!audio.paused && token === playToken && !finished) {
          const pos = audio.currentTime || 0;
          finish();
          handoffActive = false;
          diagLog('handoff-ok', 'id=' + id + ' pos=' + Math.round(pos * 10) / 10);
        }
      }, 1500);
    }
  };
  const releaseOld = () => {
    if (oldBlob && oldBlob.url !== url) URL.revokeObjectURL(oldBlob.url);
    handoffActive = false;
  };
  const bridge = () => {
    audioBg.removeEventListener('canplay', bridge);
    audioBg.removeEventListener('error', fail);
    audioBg.play().then(() => {
      if (token !== playToken) return;
      const pos = audioBg.currentTime || 0;
      audio.addEventListener('playing', onPlaying);
      audio.src = url;
      try { audio.currentTime = pos; } catch {}
      releaseOld();
      startPlayback();
    }).catch(() => {
      if (token !== playToken) return;
      if (!finished) clearTimeout(timeoutHandle);
      if (!finished) clearTimeout(dwellTimer);
      audio.removeEventListener('playing', onPlaying);
      releaseOld();
      audio.src = url;
      startPlayback();
    });
  };
  const fail = () => {
    audioBg.removeEventListener('canplay', bridge);
    audioBg.removeEventListener('error', fail);
    if (token !== playToken) return;
    if (!finished) clearTimeout(timeoutHandle);
    if (!finished) clearTimeout(dwellTimer);
    audio.removeEventListener('playing', onPlaying);
    audioBg.pause();
    audioBg.src = '';
    releaseOld();
    audio.src = url;
    startPlayback();
  };
  const timeoutHandle = setTimeout(() => {
    if (token !== playToken) return;
    if (audio.src !== url && audioBg.readyState < 2) {
      audioBg.removeEventListener('canplay', bridge);
      audioBg.removeEventListener('error', fail);
      audioBg.pause();
      audioBg.src = '';
      releaseOld();
      audio.src = url;
      startPlayback();
      diagLog('handoff-timeout', 'id=' + id);
    }
  }, 5000);
  audioBg.addEventListener('canplay', bridge);
  audioBg.addEventListener('error', fail);
}

function cancelHandoff() {
  try { audioBg.pause(); } catch {}
  audioBg.src = '';
  audioBg.removeAttribute('src');
  handoffActive = false;
}

let playbackRetryTimer = null;
let handoffActive = false;

function clearPlaybackRetries() {
  if (playbackRetryTimer !== null) {
    clearInterval(playbackRetryTimer);
    playbackRetryTimer = null;
  }
}

function startPlayback() {
  if (!audio.src) return;
  const token = playToken;
  clearPlaybackRetries();

  const retry = () => {
    if (token !== playToken || !wantPlay || !audio.src || !audio.paused) return;
    audio.play().catch(() => {});
  };

  audio.play().catch(() => {
    if (token !== playToken) return;
    audio.addEventListener('canplay', retry, { once: true });
    audio.addEventListener('loadeddata', retry, { once: true });
    let tries = 0;
    playbackRetryTimer = setInterval(() => {
      tries++;
      if (token !== playToken || !wantPlay || !audio.src || !audio.paused || tries >= 30) {
        clearPlaybackRetries();
        return;
      }
      retry();
    }, 1000);
  });
}

function audioUrl(id) {
  return `api/stream/${id}?transcode=${state.transcode}`;
}

function streamUrl(id, bitrate, start = 0) {
  return `api/stream/${id}?transcode=${bitrate}` + (start > 0 ? '&start=' + start.toFixed(1) : '');
}

const BG_TRANSCODE = 192;
function bgBitrate() {
  return state.transcode > 0 ? state.transcode : BG_TRANSCODE;
}
function isLightSource(url) {
  return url.startsWith('blob:') || /transcode=(64|96|128|192|256)/.test(url);
}

/* Bascule en arrière-plan vers un Blob local complet de la piste courante.
   Un Blob (téléchargement court, en mémoire) a une durée finie et ne dépend
   plus du réseau : la lecture verrouillée redevient fluide et les transitions
   (earlyadv) peuvent se déclencher normalement. Contrairement à un flux
   transcodé en direct dont la durée est indéfinie et le buffer limité. */
let bgBlobCtrl = null;

function cancelBgBlobSwap() {
  if (bgBlobCtrl) { bgBlobCtrl.abort(); bgBlobCtrl = null; }
}

function bgBlobSwap(entryId, pos) {
  cancelBgBlobSwap();
  const token = playToken;
  const ctrl = new AbortController();
  bgBlobCtrl = ctrl;
  diagLog('bg-switch', 'ct=' + Math.round(pos * 10) / 10 + ' pending');
  fetch(streamUrl(String(entryId), bgBitrate()), { signal: ctrl.signal })
    .then(res => {
      if (!res.ok) throw new Error('nok');
      return res.blob();
    })
    .then(blob => {
      if (token !== playToken || !wantPlay || !document.hidden) return;
      const url = URL.createObjectURL(blob);
      const cur = audio.currentTime || pos || 0;
      diagLog('bg-blob-swap', 'ct=' + Math.round(cur * 10) / 10 +
        ' size=' + Math.round(blob.size / 1024) + 'K');
      releaseActiveBlob();
      activeBlob = { url, size: blob.size };
      audio.src = url;
      audio.addEventListener('loadeddata', () => {
        try { audio.currentTime = cur; } catch {}
      }, { once: true });
      startPlayback();
    })
    .catch(() => {})
    .finally(() => { if (bgBlobCtrl === ctrl) bgBlobCtrl = null; });
}

/* ------------------------------------------------------------------ */
/*  Tampon roulant des prochaines pistes en mémoire (Blob).           */
/*  Le chargement séquentiel conserve toujours les pistes les plus    */
/*  proches, même lorsque des fichiers FLAC remplissent vite le quota. */
/* ------------------------------------------------------------------ */
const prefetched = new Map();
const prefetchPending = new Map();
const prefetchQueue = [];
const prefetchDeferred = new Set();
const PREFETCH_PARALLEL = 2;
const PREFETCH_DEPTH = 4;
const PREFETCH_CACHE_MAX = 4;
const PREFETCH_MAX_BYTES = 160 * 1024 * 1024;
let prefetchBytes = 0;
let activeBlob = null;
let lastNearPrefetch = 0;

function fetchPrefetch(key, ctrl, tries) {
  return fetch(streamUrl(key, bgBitrate()), { signal: ctrl.signal })
    .then(r => { if (!r.ok) throw new Error(); return r.blob(); })
    .then(blob => {
      const url = URL.createObjectURL(blob);
      prefetched.set(key, { url, size: blob.size });
      prefetchBytes += blob.size;
      trimPrefetch();
    })
    .catch(err => {
      if (err && err.name === 'AbortError') throw err;
      if ((tries || 0) >= 2) {
        diagLog('prefetch-fail', key);
        throw err;
      }
      return fetchPrefetch(key, ctrl, (tries || 0) + 1);
    });
}

function drainPrefetch() {
  while (prefetchPending.size < PREFETCH_PARALLEL && prefetchQueue.length) {
    const key = prefetchQueue.shift();
    if (prefetched.has(key) || prefetchPending.has(key)) continue;
    const ctrl = new AbortController();
    prefetchPending.set(key, ctrl);
    fetchPrefetch(key, ctrl, 0)
      .catch(() => {})
      .finally(() => { prefetchPending.delete(key); drainPrefetch(); });
  }
}

function prefetchDistance(key) {
  const len = state.queue.length;
  if (!len) return Infinity;
  const j = state.queue.findIndex(e => String(e.id) === key);
  if (j === -1) return Infinity;
  if (state.shuffle) return Math.abs(j - state.index);
  let d = (j - state.index + len) % len;
  if (d === 0) d = len;
  return d;
}

function trimPrefetch() {
  while ((prefetched.size > PREFETCH_CACHE_MAX || prefetchBytes > PREFETCH_MAX_BYTES) && prefetched.size > 1) {
    let farthestKey = null;
    let farthestDist = -1;
    prefetched.forEach((val, key) => {
      const d = prefetchDistance(key);
      if (d > farthestDist) { farthestDist = d; farthestKey = key; }
    });
    if (!farthestKey) break;
    const old = prefetched.get(farthestKey);
    prefetchBytes -= old.size;
    URL.revokeObjectURL(old.url);
    prefetched.delete(farthestKey);
    prefetchDeferred.add(farthestKey);
  }
}

function prefetchTrack(id) {
  const key = String(id);
  if (prefetched.has(key) || prefetchPending.has(key) || prefetchQueue.includes(key) || prefetchDeferred.has(key)) return;
  prefetchQueue.push(key);
  drainPrefetch();
}

function prefetchNow(id) {
  const key = String(id);
  const qi = prefetchQueue.indexOf(key);
  if (qi > 0) {
    prefetchQueue.splice(qi, 1);
    prefetchQueue.unshift(key);
  }
  prefetchDeferred.delete(key);
  prefetchTrack(key);
}

function clearPrefetch() {
  prefetchPending.forEach(c => { c.abort(); });
  prefetchPending.clear();
  prefetchQueue.length = 0;
  prefetchDeferred.clear();
  prefetched.forEach(v => URL.revokeObjectURL(v.url));
  prefetched.clear();
  prefetchBytes = 0;
  releaseActiveBlob();
}

function upcomingPrefetch() {
  const len = state.queue.length;
  if (len < 2) return;
  const slots = Math.min(PREFETCH_DEPTH, len - 1);
  if (state.shuffle) {
    const picks = new Set();
    while (picks.size < slots) {
      const n = Math.floor(Math.random() * len);
      if (n !== state.index) picks.add(n);
    }
    picks.forEach(n => prefetchTrack(state.queue[n].id));
    return;
  }
  for (let i = 1; i <= slots; i++) {
    let idx = state.index + i;
    if (idx >= len) {
      if (state.repeat) idx %= len;
      else break;
    }
    if (idx < len) prefetchTrack(state.queue[idx].id);
  }
}

setInterval(() => {
  if (!audio.paused && state.queue.length) upcomingPrefetch();
}, 10000);

audio.addEventListener('loadeddata', () => {
  if (state.queue.length > 1) upcomingPrefetch();
});

function cachedAudioUrl(id) {
  const key = String(id);
  const entry = prefetched.get(key);
  if (!entry) return null;
  prefetched.delete(key);
  prefetchBytes -= entry.size;
  prefetchDeferred.clear();
  return entry;
}

function releaseActiveBlob() {
  if (!activeBlob) return;
  URL.revokeObjectURL(activeBlob.url);
  activeBlob = null;
}

function refreshSongInfo() {
  const img = $('#player-art-img');
  const note = $('#player-art-note');
  const entry = state.queue[state.index];
  if (!entry) {
    $('#player-title').textContent = 'Aucune piste';
    $('#player-artist').textContent = '';
    img.style.display = 'none';
    note.style.display = 'block';
    return;
  }
  $('#player-title').textContent = entry.title || 'Piste';
  $('#player-artist').textContent = entry.artist || '';
  if (entry.album) {
    img.src = 'api/art/' + entry.album;
    img.style.display = 'block';
    note.style.display = 'none';
    img.onerror = () => { img.style.display = 'none'; note.style.display = 'block'; img.onerror = null; };
  } else {
    img.style.display = 'none';
    note.style.display = 'block';
  }
}

function togglePlayback() {
  if (audio.paused) { if (!audio.src) play(); else { wantPlay = true; audio.play().catch(() => {}); } setPlayBtn(true); }
  else { wantPlay = false; audio.pause(); clearPlaybackRetries(); setPlayBtn(false); }
}

$('#btn-play').addEventListener('click', togglePlayback);

$('#btn-next').addEventListener('click', next);
$('#btn-prev').addEventListener('click', prev);

function shuffleNextIndex() {
  const candidates = [];
  prefetched.forEach((_, key) => {
    const j = state.queue.findIndex(e => String(e.id) === key);
    if (j !== -1 && j !== state.index) candidates.push(j);
  });
  if (candidates.length) return candidates[Math.floor(Math.random() * candidates.length)];
  let n;
  do { n = Math.floor(Math.random() * state.queue.length); } while (state.queue.length > 1 && n === state.index);
  return n;
}

function next() {
  if (!state.queue.length) return;
  if (state.shuffle) {
    state.index = shuffleNextIndex();
  } else {
    if (state.index >= state.queue.length - 1) {
      if (state.repeat) state.index = 0;
      else return;
    } else state.index++;
  }
  play();
}

function prev() {
  if (!state.queue.length) return;
  if (audio.currentTime > 3) { audio.currentTime = 0; return; }
  state.index = state.index > 0 ? state.index - 1 : state.queue.length - 1;
  play();
}

let queueOpen = false;
function syncQueueInset() {
  const panel = $('#queue-panel');
  const height = queueOpen ? panel.offsetHeight : 0;
  document.documentElement.style.setProperty('--queue-panel-h', height + 'px');
}

function toggleQueue() {
  queueOpen = !queueOpen;
  $('#queue-panel').hidden = !queueOpen;
  $('#btn-queue').classList.toggle('on', queueOpen);
  $('#nav-queue').classList.toggle('active', queueOpen);
  $('#btn-queue').setAttribute('aria-pressed', String(queueOpen));
  $('#nav-queue').setAttribute('aria-pressed', String(queueOpen));
  if (queueOpen) renderQueue();
  requestAnimationFrame(syncQueueInset);
}
function openQueue() {
  if (!queueOpen) {
    queueOpen = true;
    $('#queue-panel').hidden = false;
    $('#btn-queue').classList.add('on');
    $('#nav-queue').classList.add('active');
  }
  $('#btn-queue').setAttribute('aria-pressed', 'true');
  $('#nav-queue').setAttribute('aria-pressed', 'true');
  renderQueue();
  requestAnimationFrame(syncQueueInset);
}

if ('ResizeObserver' in window) {
  new ResizeObserver(syncQueueInset).observe($('#queue-panel'));
}
window.addEventListener('resize', syncQueueInset);

$('#btn-queue').addEventListener('click', e => { e.stopPropagation(); toggleQueue(); });
$('#queue-clear').addEventListener('click', clearQueue);
$('#queue-close').addEventListener('click', e => { e.stopPropagation(); if (queueOpen) toggleQueue(); });
document.addEventListener('click', e => {
  if (queueOpen && !e.target.closest('#queue-panel') && !e.target.closest('#btn-queue') && !e.target.closest('#nav-queue')) toggleQueue();
});
document.addEventListener('click', e => {
  if (!e.target.closest('#search-box')) closeSearch();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && queueOpen) toggleQueue(); });

function nextTrackId() {
  if (state.shuffle) return null;
  let idx = state.index + 1;
  if (idx >= state.queue.length) {
    if (!state.repeat) return null;
    idx = 0;
  }
  return state.queue[idx] ? String(state.queue[idx].id) : null;
}

function endOfTrack() {
  if (state.repeat === 'one') {
    audio.currentTime = 0;
    startPlayback();
    fetch('api/play/' + state.playingId, { method: 'POST' }).catch(() => {});
    return;
  }
  if (state.index >= state.queue.length - 1 && !state.repeat && !state.shuffle) {
    wantPlay = false;
    clearPlaybackRetries();
    setPlayBtn(false);
    releaseWakeLock();
    mediaSessionUpdate();
    return;
  }
  if (transitionHold && transitionHold.token === playToken) return;
  const nextId = nextTrackId();
  if (nextId && document.hidden && !prefetched.has(nextId)) {
    diagLog('hold-next', 'id=' + nextId + ' prefetch=' + prefetched.size + '/' + prefetchPending.size + '/' + prefetchQueue.length);
    prefetchNow(nextId);
    const holdTailAt = () => {
      try {
        if (audio.duration && isFinite(audio.duration) && audio.duration > 8) {
          audio.currentTime = Math.max(0, audio.duration - 8);
        } else {
          audio.currentTime = 0;
        }
      } catch {}
      diagLog('hold-loop', 'id=' + nextId);
      audio.play().catch(() => {});
    };
    transitionHold = { token: playToken, id: nextId, holdTailAt };
    let waits = 0;
    transitionHold.timer = setInterval(() => {
      waits++;
      if (transitionHold && transitionHold.token !== playToken) {
        clearTransitionHold();
        return;
      }
      if (!transitionHold || transitionHold.id !== nextId) return;
      if (prefetched.has(nextId)) {
        clearTransitionHold();
        next();
        return;
      }
      if (document.hidden && audio.paused && wantPlay && audio.src) {
        transitionHold.holdTailAt();
      }
      if (waits >= 120) {
        clearTransitionHold();
        diagLog('hold-timeout', 'id=' + nextId);
        next();
      }
    }, 500);
    return;
  }
  next();
}

audio.addEventListener('ended', () => {
  if (handoffActive) return;
  if (!audio.ended && !(audio.duration && audio.currentTime >= audio.duration - 0.25)) return;
  diagLog('ended', 'hidden=' + (document.hidden ? 1 : 0) +
    ' prefetch=' + prefetched.size + '/' + prefetchPending.size +
    ' nextId=' + (state.queue[state.index + 1] ? state.queue[state.index + 1].id : '?'));
  endOfTrack();
});

audio.addEventListener('error', () => {
  const t = playToken;
  diagLog('error-handler', audio.error ? 'code=' + audio.error.code : '?');
  if (!state.queue.length || !state.playingId) return;
  const code = audio.error ? audio.error.code : -1;
  if (code === 4) {
    const advance = () => { if (t === playToken) next(); };
    setTimeout(advance, 500);
    return;
  }
  const retry = () => { if (t === playToken) play(); };
  setTimeout(retry, 1000);
});
audio.addEventListener('pause', () => { if (!wantPlay) releaseWakeLock(); numberRows(); mediaSessionUpdate(); });
audio.addEventListener('play', () => { acquireWakeLock(); numberRows(); mediaSessionUpdate(); });

function mediaSessionUpdate() {
  if (!('mediaSession' in navigator)) return;
  const ms = navigator.mediaSession;
  const entry = state.queue[state.index];
  const title = entry ? entry.title : '';
  const artist = entry ? entry.artist : '';
  const album = entry && entry.album ? entry.album : null;
  ms.metadata = new MediaMetadata({
    title: title || 'Muzik',
    artist: artist || 'Muzik',
    album: album ? 'Muzik' : '',
    artwork: album ? [{ src: 'api/art/' + album, sizes: '512x512', type: 'image/jpeg' }] : [],
  });
  ms.setActionHandler('play', () => { if (audio.paused && audio.src) { wantPlay = true; audio.play().catch(() => {}); } });
  ms.setActionHandler('pause', () => { wantPlay = false; audio.pause(); });
  ms.setActionHandler('previoustrack', prev);
  ms.setActionHandler('nexttrack', next);
  ms.setActionHandler('seekto', e => { if (e.seekTime != null) audio.currentTime = e.seekTime; });
  // Pendant le changement de source, audio.paused vaut momentanement true.
  // Conserver l'etat demande evite qu'Android libere la session audio entre
  // deux morceaux lorsque la page est masquee ou l'ecran verrouille.
  ms.playbackState = wantPlay ? 'playing' : 'paused';
}

audio.addEventListener('timeupdate', () => {
  const pr = $('#player-progress');
  if (audio.duration && !isNaN(audio.duration)) {
    pr.max = Math.floor(audio.duration);
    pr.value = Math.floor(audio.currentTime);
  }
  $('#player-time').textContent = fmtDur(audio.currentTime) + ' / ' + fmtDur(audio.duration);
  if ('mediaSession' in navigator && 'setPositionState' in navigator.mediaSession && audio.duration) {
    try {
      navigator.mediaSession.setPositionState({
        duration: audio.duration,
        playbackRate: audio.playbackRate,
        position: audio.currentTime,
      });
    } catch {}
  }
  if (audio.duration && !isNaN(audio.duration)) {
    if (audio.duration - audio.currentTime < 30 && state.queue.length > 1) {
      const now = Date.now();
      if (now - lastNearPrefetch > 10000) {
        lastNearPrefetch = now;
        upcomingPrefetch();
      }
    }
    if (!earlyAdvanced && document.hidden && !audio.paused && !handoffActive && state.queue.length > 1
        && state.repeat !== 'one'
        && (state.shuffle || state.repeat || state.index < state.queue.length - 1)) {
      const remain = audio.duration - audio.currentTime;
      const lead = 2;
      if (remain > 0 && remain <= lead) {
        earlyAdvanced = true;
        diagLog('earlyadv', 'remain=' + remain.toFixed(2) +
          ' prefetch=' + prefetched.size + '/' + prefetchPending.size);
        endOfTrack();
      }
    }
  }
});

$('#player-progress').addEventListener('input', e => {
  if (audio.duration) audio.currentTime = parseFloat(e.target.value);
});

$('#btn-shuffle').addEventListener('click', e => {
  state.shuffle = !state.shuffle;
  e.target.classList.toggle('on', state.shuffle);
  e.target.setAttribute('aria-pressed', String(state.shuffle));
});

$('#btn-repeat').addEventListener('click', e => {
  state.repeat = state.repeat === false ? true : (state.repeat === true ? 'one' : false);
  e.target.classList.toggle('on', !!state.repeat);
  e.target.querySelector('#ico-repeat-all').style.display = state.repeat === 'one' ? 'none' : '';
  e.target.querySelector('#ico-repeat-one').style.display = state.repeat === 'one' ? '' : 'none';
  e.target.setAttribute('aria-pressed', String(!!state.repeat));
});

$('#player-transcode').addEventListener('change', e => {
  state.transcode = parseInt(e.target.value, 10);
  clearPrefetch();
  api.get('api/settings?set_key=transcode&value=' + state.transcode).then(() => {
    if (state.playingId) play();
  });
});

/* ============================================================ */
/*  AUTHENTIFICATION PAR L'APPLICATION                          */
/* ------------------------------------------------------------ */
let authState = { checked: false, authenticated: false, user: null };

async function bootAuth() {
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

async function loadSettings() {
  try {
    const s = await api.get('api/settings');
    if (s.transcode != null) {
      state.transcode = parseInt(s.transcode, 10);
      $('#player-transcode').value = String(state.transcode);
    }
  } catch {}
}

(async function startApp() {
  if (!await bootAuth()) return;
  handleRoute();
  syncPlayBtn();
  loadSettings();
})();

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}
