'use strict';

import { $, $$, api, state, esc, pictoIcon, fmtDur, qs, a11yCard, a11yRow } from './core.js?v=77';
import { configureFavorites, getFavIds, toggleFav } from './favorites.js?v=77';
import { audio, clearPlaybackRetries, mediaSessionUpdate, numberRows, openQueue, play, playQueue, refreshSongInfo, renderQueue, setPlayBtn, togglePlayback, toggleQueue } from './player.js?v=77';

/* ------------------------------------------------------------------ */
/*  Navigation                                                         */
/* ------------------------------------------------------------------ */

let settingsViewLoader = () => {};

export function configureSettingsView(loader) {
  settingsViewLoader = loader;
}

export function switchView(name) {
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
  if (name === 'settings') settingsViewLoader();
}

let ownHash = '';
function navigateTo(target) {
  if (location.hash === target) return;
  ownHash = target;
  location.hash = target;
}

window.addEventListener('hashchange', handleRoute);
export function handleRoute() {
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
  const views = ['home', 'genres', 'artists', 'albums', 'top', 'recent', 'random', 'favorites', 'settings'];
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
  const showFilter = data.total >= 20;
  if (showFilter && !letters.includes(state.letter) && letters) {
    state.letter = letters[0];
  }
  el.innerHTML =
    '<div class="view-header">' + pictoIcon('artists') + '<h2>Artistes</h2><span class="count">' + (data.total || 0) + ' artistes</span></div>' +
    (showFilter
      ? '<div class="letters">' +
        [...letters].map(l =>
          `<button type="button" data-l="${esc(l)}" class="${l === state.letter ? 'active' : ''}">${esc(l)}</button>`
        ).join('') +
        '</div>'
      : '') +
    '<div class="grid" id="artist-grid"></div>';
  if (showFilter) {
    $$('#view-artists .letters button').forEach(b =>
      b.addEventListener('click', () => {
        state.letter = b.dataset.l;
        $$('#view-artists .letters button').forEach(x => x.classList.toggle('active', x === b));
        loadArtistLetter(state.letter);
      })
    );
    loadArtistLetter(state.letter);
  } else {
    loadAllArtists();
  }
}

async function loadArtistLetter(letter) {
  const grid = $('#artist-grid');
  grid.innerHTML = '<div class="empty">Chargement…</div>';
  const list = await api.get('api/artists?letter=' + qs(letter));
  renderArtistGrid(grid, list);
}

async function loadAllArtists() {
  const grid = $('#artist-grid');
  grid.innerHTML = '<div class="empty">Chargement…</div>';
  const list = await api.get('api/artists?letter=');
  renderArtistGrid(grid, list);
}

function renderArtistGrid(grid, list) {
  if (!list.length) {
    grid.innerHTML = '<div class="empty">Aucun artiste.</div>';
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

const ALBUM_PER = 120;
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
  const data = await api.get('api/albums?page=' + (albumsInf.page + 1) + '&limit=' + ALBUM_PER);
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
  let d;
  try {
    d = await api.get('api/home');
  } catch (err) {
    el.dataset.loaded = '';
    el.innerHTML = '<div class="empty">Chargement impossible.' +
      '<br><span class="err-sub">' + esc(String((err && err.message) || err)) + '</span>' +
      '<br><button type="button" class="retry" data-retry="home">Réessayer</button></div>';
    const retry = el.querySelector('[data-retry="home"]');
    if (retry) retry.addEventListener('click', () => loadHome());
    return;
  }
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
      </div>` : '<div class="empty">Aucun favoris pour le moment.<br>Appuie sur ♡ sur une piste pour la retrouver ici.</div>'}
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
    if ($('#album-edit-form')) return;
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
        <button id="delete-album" class="add-to-queue delete-album" type="button">Supprimer</button>
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
    $('#delete-album').addEventListener('click', () => deleteAlbum(id));
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
      <span class="ti">${esc(t.title)}<span class="t-artist">${esc(t.artist)}</span></span>
      <span class="du">${fmtDur(t.duration)}</span>
      <span></span>
      <button class="fav${on}" data-fav type="button" aria-label="${on ? 'Retirer des favoris' : 'Ajouter aux favoris'}" aria-pressed="${on}">${on ? '♥' : '♡'}</button>
      <button class="add-q" data-addq type="button" title="Ajouter à la file d'attente" aria-label="Ajouter à la file d'attente">+</button>
    </div>`;
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

async function loadFavorites() {
  const el = $('#view-favorites');
  const songs = await api.get('api/favorites');
  if (!songs.length) {
    el.innerHTML = '<div class="empty">Aucun favoris pour le moment.<br>Appuie sur ♡ sur une piste pour la retrouver ici.</div>';
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

configureFavorites(loadFavorites);

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
      html = '<div class="grid grid-top">' + d.albums.map(a => `
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
const searchClose = $('#search-close');
let searchTimer;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  searchClose.hidden = q.length === 0;
  if (q.length < 2) { closeSearch(); return; }
  searchTimer = setTimeout(() => doSearch(q), 250);
});

searchClose.addEventListener('click', () => {
  clearTimeout(searchTimer);
  searchInput.value = '';
  searchClose.hidden = true;
  closeSearch();
  searchInput.focus();
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
      searchClose.hidden = true;
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
